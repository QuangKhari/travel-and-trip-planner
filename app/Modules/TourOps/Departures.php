<?php

namespace App\Modules\TourOps;

use App\Services\DepartureService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giao diện của phân hệ Tour Ops (ngày khởi hành, chỗ, phương án cứu chuyến) cho phân hệ khác.
 * - Planner / AI chỉ ĐỌC ngày khởi hành còn bán qua đây (không đọc tbl_tour_availability, không gọi DepartureService).
 * - Commerce đổi số chỗ đang giữ / đã bán của một ngày khởi hành qua đây (không ghi thẳng tbl_tour_availability).
 */
final class Departures
{
    public function __construct(private DepartureService $deps) {}

    public function enabled(): bool
    {
        return DepartureService::enabled();
    }

    /**
     * Các ngày khởi hành của một tour (cũ → mới) kèm remaining, endDate, bookable; $onlyBookable: chỉ ngày còn mở và chưa đi.
     * @return list<object>
     */
    public function forTour(int $tourId, bool $onlyBookable = false): array
    {
        return $this->deps->forTour($tourId, $onlyBookable);
    }

    /**
     * Đổi số chỗ của một ngày khởi hành ($col: heldSlots | bookedSlots) thêm / bớt $delta người.
     * Thêm chỉ thành công khi ngày còn mở và còn đủ chỗ trống (một câu UPDATE có điều kiện, an toàn khi nhiều người đặt cùng lúc).
     */
    public function changeSeats(int $availabilityId, string $col, int $delta): bool
    {
        if (!in_array($col, ['heldSlots', 'bookedSlots'], true) || $delta === 0) {
            return $delta === 0;
        }
        if ($delta > 0) {
            $ok = DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->where('status', 'open')
                ->whereRaw('totalSlots - bookedSlots - heldSlots >= ?', [$delta])
                ->update([$col => DB::raw($col . ' + ' . $delta), 'updatedAt' => now()]) === 1;
        } else {
            DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)
                ->update([$col => DB::raw('GREATEST(' . $col . ' - ' . abs($delta) . ', 0)'), 'updatedAt' => now()]);
            $ok = true;
        }
        if ($ok) {
            $this->resync($availabilityId);
        }

        return $ok;
    }

    /** Phương án thiếu khách đang chờ hoàn tiền -> hoàn tất (Commerce gọi khi mọi hồ sơ hoàn của phương án đã xong). */
    public function completeRescuePlan(int $planId): void
    {
        if ($planId > 0 && Schema::hasTable('tbl_departure_rescue_plans')) {
            DB::table('tbl_departure_rescue_plans')->where('planId', $planId)->where('status', 'refund_pending')
                ->update(['status' => 'completed', 'updatedAt' => now()]);
        }
    }

    /** Ghi lại tbl_tours (chuyến gần nhất còn bán) và xóa bộ đệm số chỗ sau khi chỗ của một ngày khởi hành đổi. */
    private function resync(int $availabilityId): void
    {
        $tourId = (int) DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->value('tourId');
        if ($tourId) {
            $this->deps->syncTour($tourId);
            Cache::forget(DepartureService::seatsKey($tourId));
        }
    }
}
