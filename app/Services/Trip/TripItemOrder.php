<?php

namespace App\Services\Trip;

use Illuminate\Support\Facades\DB;

/**
 * Thêm, xóa, chuyển mục trong một Trip mà không vi phạm unique(tripId, dayNo, orderNo).
 *
 * Quy tắc để không bao giờ va chạm giữa chừng:
 *  - "đỗ" mục đang chuyển ở dayNo = NULL (MySQL cho phép nhiều NULL trong unique) trước khi dịch các mục khác;
 *  - dồn chỗ trống: dịch giảm theo orderNo TĂNG dần; mở chỗ: dịch tăng theo orderNo GIẢM dần.
 * Mọi thao tác chạy trong transaction và khóa dòng tbl_trips.
 */
class TripItemOrder
{
    public const TYPES = ['destination', 'place', 'event', 'food', 'tour', 'experience', 'transport', 'note'];

    private function lock(int $tripId): void
    {
        DB::table('tbl_trips')->where('tripId', $tripId)->lockForUpdate()->value('tripId');
    }

    private function scope(int $tripId, ?int $day)
    {
        $q = DB::table('tbl_trip_items')->where('tripId', $tripId);

        return $day === null ? $q->whereNull('dayNo') : $q->where('dayNo', $day);
    }

    /** Thêm vào cuối một ngày. Trả về itemId. */
    public function add(int $tripId, ?int $day, string $refType, ?int $refId, ?string $label = null, ?string $slot = null, ?string $note = null): int
    {
        if (!in_array($refType, self::TYPES, true)) {
            throw new \InvalidArgumentException('refType không hợp lệ: ' . $refType);
        }
        if ($refId === null && !in_array($refType, ['transport', 'note'], true)) {
            throw new \InvalidArgumentException('Chỉ transport/note được phép không có refId');
        }

        return DB::transaction(function () use ($tripId, $day, $refType, $refId, $label, $slot, $note) {
            $this->lock($tripId);
            $next = (int) ($this->scope($tripId, $day)->max('orderNo') ?? -1) + 1;

            return (int) DB::table('tbl_trip_items')->insertGetId([
                'tripId' => $tripId, 'dayNo' => $day, 'orderNo' => $next, 'refType' => $refType, 'refId' => $refId,
                'label' => $label, 'slot' => $slot, 'note' => $note,
            ]);
        });
    }

    public function remove(int $itemId): void
    {
        DB::transaction(function () use ($itemId) {
            $item = DB::table('tbl_trip_items')->where('itemId', $itemId)->first();
            if (!$item) {
                return;
            }
            $this->lock((int) $item->tripId);
            DB::table('tbl_trip_items')->where('itemId', $itemId)->delete();
            $this->closeGap((int) $item->tripId, $item->dayNo === null ? null : (int) $item->dayNo, (int) $item->orderNo);
        });
    }

    /** Chuyển một mục sang ngày $toDay, vị trí $toOrder (tính từ 0, tự cắt về khoảng hợp lệ). Dùng cho cả đảo chỗ trong cùng ngày. */
    public function move(int $itemId, ?int $toDay, int $toOrder): void
    {
        DB::transaction(function () use ($itemId, $toDay, $toOrder) {
            $item = DB::table('tbl_trip_items')->where('itemId', $itemId)->first();
            if (!$item) {
                return;
            }
            $tripId = (int) $item->tripId;
            $this->lock($tripId);
            $fromDay = $item->dayNo === null ? null : (int) $item->dayNo;

            // 1) đỗ mục ở dayNo NULL, 2) dồn chỗ trống ngày cũ, 3) mở chỗ ở ngày mới, 4) đặt mục vào
            DB::table('tbl_trip_items')->where('itemId', $itemId)->update(['dayNo' => null, 'orderNo' => 0]);
            $this->closeGap($tripId, $fromDay, (int) $item->orderNo, $itemId);

            $count = $this->scope($tripId, $toDay)->where('itemId', '!=', $itemId)->count();
            $pos = max(0, min($toOrder, $count));
            $this->openGap($tripId, $toDay, $pos, $itemId);
            DB::table('tbl_trip_items')->where('itemId', $itemId)->update(['dayNo' => $toDay, 'orderNo' => $pos]);
        });
    }

    /** Sau khi một mục rời vị trí $from: các mục có orderNo lớn hơn lùi một bậc (theo thứ tự tăng dần). */
    private function closeGap(int $tripId, ?int $day, int $from, ?int $exceptItem = null): void
    {
        $rows = $this->scope($tripId, $day)->where('orderNo', '>', $from)->when($exceptItem, fn ($q) => $q->where('itemId', '!=', $exceptItem))
            ->orderBy('orderNo')->get(['itemId', 'orderNo']);
        foreach ($rows as $r) {
            DB::table('tbl_trip_items')->where('itemId', $r->itemId)->update(['orderNo' => $r->orderNo - 1]);
        }
    }

    /** Mở chỗ trống tại $pos: các mục có orderNo >= $pos tiến một bậc (theo thứ tự GIẢM dần). */
    private function openGap(int $tripId, ?int $day, int $pos, int $exceptItem): void
    {
        $rows = $this->scope($tripId, $day)->where('orderNo', '>=', $pos)->where('itemId', '!=', $exceptItem)
            ->orderByDesc('orderNo')->get(['itemId', 'orderNo']);
        foreach ($rows as $r) {
            DB::table('tbl_trip_items')->where('itemId', $r->itemId)->update(['orderNo' => $r->orderNo + 1]);
        }
    }
}
