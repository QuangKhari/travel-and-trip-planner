<?php

namespace App\Modules\Planner;

use App\Services\Planner\TourMatch;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripSharing;
use App\Services\Planner\TripWatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giao diện của phân hệ Planner (chuyến tự lập) cho phân hệ khác.
 * - Commerce: kiểm quyền với chuyến khi gắn đơn, chấm độ hợp tour - chuyến, ghi dòng thời gian của chuyến, chụp lịch để báo giá.
 * - AI: ghi chú do trợ lý tạo chuyến điền, và các thao tác của hộp cát đánh giá (tạo chuyến tạm, làm hỏng chuyến có chủ đích).
 * Phân hệ khác không ghi tbl_trips / tbl_trip_items và không gọi lớp nội bộ của Planner trực tiếp (config/modules.php: class_rules).
 */
final class PlannerApi
{
    /** Chuyến người dùng xem / sửa được (chủ hoặc thành viên): ['trip' => object, 'role' => ...] hoặc null. */
    public function access(int $userId, int $tripId): ?array
    {
        return app(TripSharing::class)->access($userId, $tripId);
    }

    /** Lịch chuyến đã dựng (days, unassigned...) như trang sửa chuyến thấy. */
    public function payload(object $trip): array
    {
        return app(TripPlanner::class)->payload($trip);
    }

    /** Điểm Tour Match (0..1) của một tour với chuyến (cùng công thức với khối "Tour hợp chuyến"); null nếu tour không lọt danh sách. */
    public function tourMatchScore(object $trip, int $tourId, ?int $destinationId, int $adults, int $children): ?float
    {
        $payload = $this->payload($trip);
        $items = array_merge($payload['unassigned'], ...array_map(fn($d) => $d['items'], $payload['days']));
        $want = TourMatch::wantFromTrip($trip, $items);
        $want['adults'] = $adults;
        $want['children'] = $children;
        foreach (app(TourMatch::class)->rank($want, [(int) $destinationId], 40) as $r) {
            if ($r['tourId'] === $tourId) {
                return (float) $r['score'];
            }
        }

        return null;
    }

    /** Ghi một dòng vào dòng thời gian của chuyến (tbl_trip_events). */
    public function log(int $tripId, string $kind, string $tone, string $title, ?string $text = null, ?int $day = null): void
    {
        TripWatch::log($tripId, $kind, $tone, $title, $text, $day);
    }

    /** Ghi chú chuyến (tối đa 500 ký tự, bỏ thẻ HTML). */
    public function setNotes(int $tripId, string $notes): void
    {
        if (Schema::hasColumn('tbl_trips', 'notes')) {
            DB::table('tbl_trips')->where('tripId', $tripId)->update(['notes' => mb_substr(strip_tags($notes), 0, 500)]);
        }
    }

    public function exists(int $tripId): bool
    {
        return DB::table('tbl_trips')->where('tripId', $tripId)->exists();
    }

    /**
     * Chuyến tạm cho hộp cát đánh giá AI (bên gọi bọc trong transaction rồi hoàn tác). Mục lặp (cùng loại + mã) chỉ lấy một lần.
     * @param list<array{type:string,refId:int,day:?int,startMin?:?int,durationMin?:?int}> $items
     */
    public function sandboxTrip(int $userId, ?int $destId, int $days, ?string $start, int $group, array $items, string $title): int
    {
        $tripId = (int) DB::table('tbl_trips')->insertGetId(['userId' => $userId, 'kind' => 'trip', 'destinationId' => $destId, 'title' => $title,
            'startDate' => $start, 'days' => $days, 'groupSize' => $group, 'status' => 'planned', 'createdAt' => now(), 'updatedAt' => now()]);
        $order = [];
        $seen = [];
        $rows = [];
        foreach ($items as $it) {
            $k = $it['type'] . ':' . $it['refId'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $d = $it['day'] ?? null;
            $rows[] = ['tripId' => $tripId, 'refType' => $it['type'], 'refId' => (int) $it['refId'], 'dayNo' => $d, 'orderNo' => $order[$d ?? 0] = ($order[$d ?? 0] ?? -1) + 1,
                'startMin' => $it['startMin'] ?? null, 'durationMin' => $it['durationMin'] ?? null];
        }
        if ($rows) {
            DB::table('tbl_trip_items')->insert($rows);
        }

        return $tripId;
    }

    /** Đặt lại ngày / thứ tự / giờ cố định của một mục (chỉ các cột này) rồi tăng rev của chuyến. @param array{dayNo?:?int,orderNo?:int,startMin?:?int} $slot */
    public function setItemSlot(int $tripId, int $itemId, array $slot): void
    {
        $slot = array_intersect_key($slot, array_flip(['dayNo', 'orderNo', 'startMin']));
        if ($slot) {
            DB::table('tbl_trip_items')->where('tripId', $tripId)->where('itemId', $itemId)->update($slot);
        }
    }

    /** Tăng số phiên bản (rev) của chuyến sau khi đổi mục ngoài TripPlanner::save. */
    public function bumpRev(int $tripId): void
    {
        DB::table('tbl_trips')->where('tripId', $tripId)->increment('rev');
    }
}
