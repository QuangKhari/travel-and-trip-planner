<?php

namespace App\Services;

use App\Mail\TemplateMail;
use App\Support\BookingPii;
use App\Support\CancelPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Hoàn tiền cho đơn đã thanh toán bị hủy (A6). Đơn hủy -> tbl_refunds (pending) + paymentStatus refund_pending;
 * admin chuyển tiền xong bấm "Đã hoàn" -> refunded. Không tích hợp API hoàn tiền của cổng thanh toán (giới hạn đồ án).
 */
class RefundService
{
    private static ?bool $hasPlanColumn = null;

    private static function hasPlanColumn(): bool
    {
        return self::$hasPlanColumn ??= Schema::hasColumn('tbl_refunds', 'rescuePlanId');
    }

    /**
     * Gọi TRONG transaction, sau khi đã khóa dòng đơn. Tạo (hoặc trả lại) yêu cầu hoàn tiền của đơn.
     * $percent = null: theo chính sách bậc thang. $rescuePlanId: hồ sơ phát sinh từ phương án thiếu khách.
     * Mã giảm giá chỉ được nhả khi % hoàn >= config booking.coupon_release_min_percent (đơn bị phạt thì không trả lại mã).
     */
    public function requestLocked(object $booking, string $by, ?int $byId, ?int $percent = null, ?string $reason = null, ?int $rescuePlanId = null): object
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

        $row = [
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
        ];
        if ($rescuePlanId !== null && self::hasPlanColumn()) {
            $row['rescuePlanId'] = $rescuePlanId;
        }
        $id = DB::table('tbl_refunds')->insertGetId($row);
        DB::table('tbl_checkout')->where('bookingId', $booking->bookingId)
            ->update(['paymentStatus' => $amount > 0 ? 'refund_pending' : 'refunded']);

        // BookingService::cancelLocked không nhả mã của đơn đã thanh toán; quyết định ở đây theo % hoàn.
        if ($percent >= (int) config('booking.coupon_release_min_percent', 100)) {
            app(CouponService::class)->release((int) $booking->bookingId);
        }

        return DB::table('tbl_refunds')->where('refundId', $id)->first();
    }

    /**
     * Ghi nhận đã hoàn tiền. $adminId null = hệ thống tự hoàn qua API cổng thanh toán ($viaGateway = true).
     * Ghi tay khi lệnh hoàn qua cổng đang xử lý bị chặn để không hoàn hai lần.
     * @return array{ok:bool, message:string, before?:object, after?:object}
     */
    public function markRefunded(int $refundId, ?int $adminId, string $method, ?string $reference, ?string $note, bool $viaGateway = false): array
    {
        return DB::transaction(function () use ($refundId, $adminId, $method, $reference, $note, $viaGateway) {
            $r = DB::table('tbl_refunds')->where('refundId', $refundId)->lockForUpdate()->first();
            if (!$r) {
                return ['ok' => false, 'message' => 'Không tìm thấy yêu cầu hoàn tiền.'];
            }
            if ($r->status === 'refunded') {
                return ['ok' => true, 'message' => 'Yêu cầu này đã được đánh dấu hoàn tiền trước đó.', 'before' => $r, 'after' => $r];
            }
            if (!$viaGateway && ($r->gatewayStatus ?? null) === 'processing') {
                return ['ok' => false, 'message' => 'Lệnh hoàn qua cổng thanh toán đang xử lý: bấm “Kiểm tra trạng thái” trước, không ghi nhận tay để tránh hoàn tiền hai lần.'];
            }
            if (!$viaGateway && $reference !== null && $reference !== ''
                && DB::table('tbl_refunds')->where('refundId', '!=', $refundId)->where('status', 'refunded')->where('method', mb_substr($method, 0, 40))->where('reference', mb_substr($reference, 0, 80))->exists()) {
                return ['ok' => false, 'message' => 'Mã giao dịch hoàn này đã được dùng cho một khoản hoàn khác: kiểm tra lại để tránh ghi nhận / hoàn trùng.'];
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
            $this->closePlanIfDone($r->rescuePlanId ?? null);

            return ['ok' => true, 'message' => 'Đã ghi nhận hoàn tiền.', 'before' => $r, 'after' => DB::table('tbl_refunds')->where('refundId', $refundId)->first()];
        });
    }

    /** Email "đã hoàn tiền" cho khách (dùng chung cho ghi tay và hoàn qua cổng). */
    public function mailRefunded(int $bookingId, object $refund): void
    {
        try {
            $b = BookingPii::decrypt(DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
                ->where('b.bookingId', $bookingId)->select('b.*', 't.title')->first());
            if ($b && filter_var($b->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($b->email)->queue(new TemplateMail('admin.emails.booking-status', ['b' => $b, 'event' => 'refunded', 'refund' => $refund], 'Travela đã hoàn tiền cho đơn ' . $b->bookingCode));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Phương án thiếu khách ở trạng thái refund_pending -> completed khi mọi hồ sơ hoàn của nó đã xong. */
    private function closePlanIfDone($planId): void
    {
        if (!$planId || !Schema::hasTable('tbl_departure_rescue_plans')) {
            return;
        }
        if (DB::table('tbl_refunds')->where('rescuePlanId', $planId)->where('status', 'pending')->exists()) {
            return;
        }
        DB::table('tbl_departure_rescue_plans')->where('planId', $planId)->where('status', 'refund_pending')
            ->update(['status' => 'completed', 'updatedAt' => now()]);
    }
}
