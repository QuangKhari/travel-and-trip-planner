<?php

namespace App\Services;

use App\Support\CancelPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Hoàn tiền cho đơn đã thanh toán bị hủy (A6). Đơn hủy -> tbl_refunds (pending) + paymentStatus refund_pending;
 * admin chuyển tiền xong bấm "Đã hoàn" -> refunded. Không tích hợp API hoàn tiền của cổng thanh toán (giới hạn đồ án).
 */
class RefundService
{
    /**
     * Gọi TRONG transaction, sau khi đã khóa dòng đơn. Tạo (hoặc trả lại) yêu cầu hoàn tiền của đơn.
     * $percent = null: theo chính sách bậc thang.
     */
    public function requestLocked(object $booking, string $by, ?int $byId, ?int $percent = null, ?string $reason = null): object
    {
        $existing = DB::table('tbl_refunds')->where('bookingId', $booking->bookingId)->first();
        if ($existing) {
            return $existing;
        }
        $checkout = DB::table('tbl_checkout')->where('bookingId', $booking->bookingId)->lockForUpdate()->first();
        $paid = (int) round((float) ($checkout->amount ?? $booking->totalPrice));
        $start = $booking->departureDate ?? DB::table('tbl_tours')->where('tourId', $booking->tourId)->value('startDate');
        $days = CancelPolicy::daysUntil($start);
        $percent = $percent === null ? CancelPolicy::refundPercent($days) : max(0, min(100, $percent));
        $amount = (int) floor($paid * $percent / 100);

        $id = DB::table('tbl_refunds')->insertGetId([
            'bookingId' => $booking->bookingId,
            'paidAmount' => $paid,
            'percent' => $percent,
            'amount' => $amount,
            'daysBefore' => max(0, $days),
            'requestedBy' => $by,
            'requestedById' => $byId,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'status' => $amount > 0 ? 'pending' : 'refunded',
            'processedAt' => $amount > 0 ? null : now(),
            'note' => $amount > 0 ? null : 'Không phát sinh tiền hoàn (0%).',
            'createdAt' => now(),
        ]);
        DB::table('tbl_checkout')->where('bookingId', $booking->bookingId)
            ->update(['paymentStatus' => $amount > 0 ? 'refund_pending' : 'refunded']);

        return DB::table('tbl_refunds')->where('refundId', $id)->first();
    }

    /** @return array{ok:bool, message:string, before?:object, after?:object} */
    public function markRefunded(int $refundId, int $adminId, string $method, ?string $reference, ?string $note): array
    {
        return DB::transaction(function () use ($refundId, $adminId, $method, $reference, $note) {
            $r = DB::table('tbl_refunds')->where('refundId', $refundId)->lockForUpdate()->first();
            if (!$r) {
                return ['ok' => false, 'message' => 'Không tìm thấy yêu cầu hoàn tiền.'];
            }
            if ($r->status === 'refunded') {
                return ['ok' => true, 'message' => 'Yêu cầu này đã được đánh dấu hoàn tiền trước đó.', 'before' => $r, 'after' => $r];
            }
            DB::table('tbl_refunds')->where('refundId', $refundId)->update([
                'status' => 'refunded',
                'method' => mb_substr($method, 0, 40),
                'reference' => $reference ? mb_substr($reference, 0, 80) : null,
                'note' => $note ? mb_substr($note, 0, 255) : null,
                'processedBy' => $adminId,
                'processedAt' => now(),
            ]);
            DB::table('tbl_checkout')->where('bookingId', $r->bookingId)->update(['paymentStatus' => 'refunded']);

            return ['ok' => true, 'message' => 'Đã ghi nhận hoàn tiền.', 'before' => $r, 'after' => DB::table('tbl_refunds')->where('refundId', $refundId)->first()];
        });
    }
}
