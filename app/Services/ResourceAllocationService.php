<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Phân bổ tài nguyên theo khoảng giờ, không bán quá sức chứa, không xếp một tài nguyên cho hai đoàn trùng giờ.
 *
 * Quy tắc:
 *  - Khoảng nửa mở [startAt, endAt): ca 10:00–12:00 và 12:00–14:00 KHÔNG trùng; 10:00–12:00 và 11:59–14:00 trùng.
 *  - Hai khoảng giao nhau khi  a.startAt < b.endAt  VÀ  a.endAt > b.startAt.
 *  - Dùng = tổng quantity của các phân bổ còn hiệu lực (held, confirmed) giao với khoảng xin; phải <= capacity.
 *    Lưu ý: tổng này là cận trên an toàn (không xét các phân bổ có giao nhau theo cặp hay không), nên có thể từ chối
 *    một tổ hợp thật ra vẫn vừa, nhưng KHÔNG BAO GIỜ nhận quá sức chứa.
 *  - Điểm tuần tự hóa: SELECT ... FOR UPDATE trên dòng tbl_rescue_resources. Hai admin cùng xếp một xe:
 *    transaction thứ hai đợi, đọc lại số đã dùng và bị từ chối nếu hết chỗ.
 *  - Phân bổ cũ theo ngày (tbl_rescue_resource_allocations) vẫn được tính, quy về [startDate 00:00, endDate+1 00:00).
 *  - Cùng chủ sở hữu (ownerType, ownerId) xin lại đúng tài nguyên và khoảng giờ -> trả phân bổ đã có (idempotent).
 */
class ResourceAllocationService
{
    public const ACTIVE = ['held', 'confirmed'];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_resource_allocations');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['resource' => $message]);
    }

    /**
     * Xếp tài nguyên. Tự mở transaction nếu chưa có (lồng được trong transaction của nghiệp vụ gọi tới).
     * @return object dòng tbl_resource_allocations
     */
    public function allocate(int $resourceId, string $ownerType, int $ownerId, CarbonInterface|string $startAt, CarbonInterface|string $endAt,
        int $quantity = 1, string $status = 'confirmed', ?int $by = null, ?string $note = null): object
    {
        $start = Carbon::parse($startAt);
        $end = Carbon::parse($endAt);
        if ($end->lessThanOrEqualTo($start)) {
            $this->fail('Thời gian kết thúc phải sau thời gian bắt đầu.');
        }
        if ($quantity < 1) {
            $this->fail('Số lượng phân bổ phải ít nhất là 1.');
        }
        if (!in_array($status, self::ACTIVE, true)) {
            $this->fail('Trạng thái phân bổ không hợp lệ.');
        }

        return DB::transaction(function () use ($resourceId, $ownerType, $ownerId, $start, $end, $quantity, $status, $by, $note) {
            $resource = DB::table('tbl_rescue_resources')->where('resourceId', $resourceId)->lockForUpdate()->first();
            if (!$resource || !$resource->active) {
                $this->fail('Tài nguyên không tồn tại hoặc đã ngừng hoạt động.');
            }
            $same = DB::table('tbl_resource_allocations')->where(['resourceId' => $resourceId, 'ownerType' => $ownerType, 'ownerId' => $ownerId])
                ->whereIn('status', self::ACTIVE)->where('startAt', $start)->where('endAt', $end)->first();
            if ($same) {
                return $same;
            }
            $used = $this->usedLocked($resourceId, $start, $end);
            if ($used + $quantity > (int) $resource->capacity) {
                $this->fail('“' . $resource->name . '” không đủ sức chứa trong khung ' . $start->format('d/m H:i') . '–' . $end->format('d/m H:i')
                    . ' (đã dùng ' . $used . '/' . $resource->capacity . ', cần thêm ' . $quantity . ').');
            }
            $id = DB::table('tbl_resource_allocations')->insertGetId([
                'resourceId' => $resourceId, 'ownerType' => $ownerType, 'ownerId' => $ownerId, 'startAt' => $start, 'endAt' => $end,
                'quantity' => $quantity, 'status' => $status, 'createdBy' => $by, 'note' => $note ? mb_substr($note, 0, 255) : null,
                'createdAt' => now(), 'updatedAt' => now(),
            ]);

            return DB::table('tbl_resource_allocations')->where('allocationId', $id)->first();
        });
    }

    /** Trả tài nguyên (giữ dòng để còn lịch sử). */
    public function release(int $allocationId): bool
    {
        return DB::table('tbl_resource_allocations')->where('allocationId', $allocationId)->whereIn('status', self::ACTIVE)
            ->update(['status' => 'released', 'updatedAt' => now()]) === 1;
    }

    /** Đã dùng bao nhiêu trong khoảng [start, end) (đọc trong transaction đã khóa dòng tài nguyên). */
    public function usedLocked(int $resourceId, CarbonInterface $start, CarbonInterface $end): int
    {
        $used = (int) DB::table('tbl_resource_allocations')->where('resourceId', $resourceId)->whereIn('status', self::ACTIVE)
            ->where('startAt', '<', $end)->where('endAt', '>', $start)->lockForUpdate()->sum('quantity');
        if (Schema::hasTable('tbl_rescue_resource_allocations')) {
            // dữ liệu cũ theo ngày: [startDate 00:00, endDate + 1 ngày 00:00)
            $used += (int) DB::table('tbl_rescue_resource_allocations')->where('resourceId', $resourceId)->where('status', 'active')
                ->whereRaw('CAST(startDate AS DATETIME) < ?', [$end->format('Y-m-d H:i:s')])   // startDate 00:00 < end
                ->where('endDate', '>=', $start->toDateString())                               // endDate + 1 ngày > start
                ->sum('seats');
        }

        return $used;
    }

    /**
     * Cặp phân bổ còn hiệu lực trùng giờ trên cùng tài nguyên mà tổng vượt sức chứa (dùng cho kiểm tra dữ liệu định kỳ).
     * @return list<object{resourceId:int, a:int, b:int, total:int, capacity:int}>
     */
    public function overlaps(int $limit = 200): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('tbl_resource_allocations as a')
            ->join('tbl_resource_allocations as b', function ($j) {
                $j->on('a.resourceId', '=', 'b.resourceId')->whereColumn('a.allocationId', '<', 'b.allocationId')
                    ->whereColumn('a.startAt', '<', 'b.endAt')->whereColumn('a.endAt', '>', 'b.startAt');
            })
            ->join('tbl_rescue_resources as r', 'r.resourceId', '=', 'a.resourceId')
            ->whereIn('a.status', self::ACTIVE)->whereIn('b.status', self::ACTIVE)
            ->whereRaw('a.quantity + b.quantity > r.capacity')
            ->limit($limit)
            ->get(['a.resourceId', 'a.allocationId as a', 'b.allocationId as b', DB::raw('a.quantity + b.quantity AS total'), 'r.capacity'])
            ->all();
    }
}
