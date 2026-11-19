<?php

namespace App\Modules\Commerce;

use App\Services\BookingService;
use App\Services\RefundService;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\DB;

/**
 * Giao diện GHI của phân hệ Commerce cho phân hệ Tour Ops (đổi ngày khởi hành, cứu chuyến, phiên bản tour).
 * Tour Ops được đọc đơn để quyết định (rules: TourOps => Commerce 'read') nhưng mọi thay đổi trên đơn / hoàn tiền / danh sách chờ đi qua đây,
 * nên luật của đơn (trạng thái, hoàn coupon, sổ cái, sự kiện BookingCancelled) chỉ nằm trong Commerce.
 * Các hàm *Locked / move* phải được gọi trong transaction của bên gọi (đơn đã lockForUpdate).
 */
final class BookingChanges
{
    /** Chuyển một đơn sang ngày khởi hành khác (có thể của tour khác). */
    public function moveBooking(int $bookingId, int $tourId, int $availabilityId, string $departureDate): void
    {
        DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['tourId' => $tourId, 'availabilityId' => $availabilityId, 'departureDate' => $departureDate]);
    }

    /** Chuyển mọi đơn đã xác nhận của một ngày khởi hành sang ngày khác (gộp chuyến). @return list<int> mã đơn đã chuyển */
    public function moveConfirmed(int $fromAvailabilityId, int $toAvailabilityId, string $departureDate): array
    {
        $ids = DB::table('tbl_booking')->where('availabilityId', $fromAvailabilityId)->where('bookingStatus', 'y')->pluck('bookingId')->map(fn($x) => (int) $x)->all();
        if ($ids) {
            DB::table('tbl_booking')->whereIn('bookingId', $ids)->update(['availabilityId' => $toAvailabilityId, 'departureDate' => $departureDate]);
        }

        return $ids;
    }

    /** Đơn cũ (trước khi có ngày khởi hành riêng) của một tour được gắn vào ngày khởi hành đầu tiên vừa tạo. */
    public function attachLegacy(int $tourId, int $availabilityId, ?string $departureDate): void
    {
        DB::table('tbl_booking')->where('tourId', $tourId)->whereNull('availabilityId')->update(['availabilityId' => $availabilityId, 'departureDate' => $departureDate]);
    }

    /** Ghi phiên bản nội dung tour khách nhận: cho các đơn chỉ định, hoặc mọi đơn chưa có phiên bản của tour. @param list<int>|null $bookingIds */
    public function stampVersion(int $versionId, ?array $bookingIds = null, ?int $tourId = null): void
    {
        $q = DB::table('tbl_booking');
        if ($bookingIds !== null) {
            if (!$bookingIds) {
                return;
            }
            $q->whereIn('bookingId', $bookingIds);
        } elseif ($tourId) {
            $q->where('tourId', $tourId)->whereNull('tourVersionId');
        } else {
            return;
        }
        $q->update(['tourVersionId' => $versionId]);
    }

    /**
     * Travela hủy đơn vì phương án cứu chuyến (thiếu khách / sự cố): hủy đơn, đã thanh toán thì lập hồ sơ hoàn 100% gắn với phương án.
     * Đơn chưa thanh toán thì trả lại coupon. @return bool có lập hồ sơ hoàn tiền
     */
    public function cancelForRescue(object $booking, string $reason, bool $paid, ?int $adminId, int $planId, string $refundNote): bool
    {
        app(BookingService::class)->cancelLocked($booking, $reason, !$paid);
        if ($paid) {
            app(RefundService::class)->requestLocked($booking, 'admin', $adminId, 100, $refundNote, $planId);
        }

        return $paid;
    }

    /** Ngày khởi hành bị đóng: đóng danh sách chờ của nó. @return int số người chờ được báo */
    public function closeWaitlist(int $availabilityId): int
    {
        return app(WaitlistService::class)->closeForAvailability($availabilityId);
    }
}
