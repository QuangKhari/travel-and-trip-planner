<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Chính sách hủy đơn và hoàn tiền (đặc tả §7.5, cấu hình config/booking.php).
 *   - Chỉ hủy được đơn đang chờ (n) hoặc đã xác nhận (y), trước ngày khởi hành.
 *   - Chưa thanh toán: khách hủy tự do đến trước ngày đi `unpaid_cancel_min_days` ngày, không có hoàn tiền.
 *   - Đã thanh toán: hoàn theo bậc (>= 14 ngày 100%, 7–13 ngày 50%); dưới `paid_cancel_min_days` ngày thì không tự hủy trên web.
 * Số ngày = (0h ngày khởi hành − 0h hôm nay) tính bằng ngày nguyên theo múi giờ ứng dụng, luôn là số nguyên, có thể âm.
 */
class CancelPolicy
{
    /** Giữ hằng cũ cho các chỗ còn tham chiếu: số ngày tối thiểu với đơn đã thanh toán. */
    public const MIN_DAYS = 7;

    public static function daysUntil(?string $startDate): int
    {
        if (!$startDate) {
            return -1;
        }
        $start = Carbon::parse($startDate)->startOfDay();
        $today = now()->startOfDay();

        return (int) round(($start->getTimestamp() - $today->getTimestamp()) / 86400);
    }

    /** % hoàn cho đơn đã thanh toán theo số ngày còn lại (0 nếu dưới mọi bậc). */
    public static function refundPercent(int $days): int
    {
        $tiers = (array) config('booking.refund_tiers', [14 => 100, 7 => 50]);
        krsort($tiers);
        foreach ($tiers as $min => $pct) {
            if ($days >= (int) $min) {
                return max(0, min(100, (int) $pct));
            }
        }

        return 0;
    }

    /**
     * Khách tự hủy trên web được không, và được hoàn bao nhiêu.
     * @return array{allowed:bool, paid:bool, days:int, percent:int, message:string}
     */
    public static function forCustomer(?string $bookingStatus, ?string $startDate, bool $paid): array
    {
        $days = self::daysUntil($startDate);
        $out = ['allowed' => false, 'paid' => $paid, 'days' => $days, 'percent' => 0, 'message' => ''];

        if (!in_array($bookingStatus, ['n', 'y'], true)) {
            $out['message'] = 'Đơn này không còn hủy được.';

            return $out;
        }
        if (!$paid) {
            $min = max(0, (int) config('booking.unpaid_cancel_min_days', 1));
            $out['allowed'] = $days >= max(1, $min);
            $out['message'] = $out['allowed'] ? 'Đơn chưa thanh toán: hủy miễn phí, chỗ được trả lại ngay.' : 'Đã sát ngày khởi hành, vui lòng liên hệ Travela để hủy.';

            return $out;
        }
        $min = (int) config('booking.paid_cancel_min_days', 7);
        if ($days < $min) {
            $out['message'] = "Còn dưới {$min} ngày đến ngày khởi hành nên không hủy trên web được. Vui lòng liên hệ Travela.";

            return $out;
        }
        $out['allowed'] = true;
        $out['percent'] = self::refundPercent($days);
        $out['message'] = "Hủy lúc này được hoàn {$out['percent']}% số tiền đã thanh toán.";

        return $out;
    }

    /** Tương thích code cũ: có cho khách hủy không (bỏ qua chuyện đã thanh toán hay chưa). */
    public static function allows(?string $bookingStatus, ?string $startDate, bool $paid = false): bool
    {
        return self::forCustomer($bookingStatus, $startDate, $paid)['allowed'];
    }
}
