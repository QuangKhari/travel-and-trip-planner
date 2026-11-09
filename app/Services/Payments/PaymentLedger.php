<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ tiền của MỘT đơn: một nguồn duy nhất cho câu hỏi "Travela đang giữ bao nhiêu tiền của khách, còn được hoàn bao nhiêu".
 *
 * Tiền hoàn của một đơn nằm ở HAI sổ có chủ đích:
 *   - tbl_booking_adjustments  hoàn phần dư khi giảm số khách (nhiều lần / đơn)   moneyStatus pending_refund | refunded
 *   - tbl_refunds              hoàn khi hủy đơn (một hồ sơ / đơn, UNIQUE)          status pending | refunded
 * Trước đây mỗi sổ tự tính "đã thu" riêng nên hủy đơn sau khi đã hoàn phần dư có thể hoàn vượt số đã thu.
 * Bất biến bắt buộc:  tổng hoàn (cả hai sổ, tính cả khoản đang chờ) <= số đã thu.
 *
 *   captured    = tbl_checkout.amount khi đơn đã thu (đã cộng các khoản thu thêm "collected")
 *   adjustOut   = tổng settleAmount điều chỉnh đang chờ hoàn / đã hoàn
 *   held        = captured - adjustOut            (tiền khách thực sự còn ở Travela; cơ sở tính % hoàn khi hủy)
 *   refundOut   = tổng tbl_refunds.amount đang chờ / đã hoàn
 *   refundable  = max(0, held - refundOut)
 *
 * Bồi hoàn khiếu nại (tbl_complaints) là chi phí dịch vụ, không phải hoàn tiền đơn, nên không tính ở đây.
 * Gọi bên trong transaction đã khóa dòng tbl_checkout của đơn để số liệu không đổi giữa lúc đọc và lúc ghi.
 */
class PaymentLedger
{
    public const PAID_STATUSES = ['y', 'refund_pending', 'refunded'];
    public const ADJUST_OUT = ['pending_refund', 'refunded'];
    public const REFUND_OUT = ['pending', 'refunded'];

    /** @return array{captured:int, adjustOut:int, held:int, refundOut:int, refundable:int} */
    public function forBooking(int $bookingId): array
    {
        $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->first(['amount', 'paymentStatus']);
        $captured = $c && in_array($c->paymentStatus, self::PAID_STATUSES, true) ? (int) round((float) $c->amount) : 0;
        $adjustOut = Schema::hasTable('tbl_booking_adjustments')
            ? (int) DB::table('tbl_booking_adjustments')->where('bookingId', $bookingId)->whereIn('moneyStatus', self::ADJUST_OUT)->sum('settleAmount')
            : 0;
        $refundOut = Schema::hasTable('tbl_refunds')
            ? (int) DB::table('tbl_refunds')->where('bookingId', $bookingId)->whereIn('status', self::REFUND_OUT)->sum('amount')
            : 0;
        $held = max(0, $captured - $adjustOut);

        return ['captured' => $captured, 'adjustOut' => $adjustOut, 'held' => $held, 'refundOut' => $refundOut, 'refundable' => max(0, $held - $refundOut)];
    }

    /**
     * Các đơn vi phạm bất biến (tổng hoàn hai sổ > đã thu). Dùng cho đối soát và lệnh kiểm tra dữ liệu.
     * @return list<object{bookingId:int, bookingCode:string, captured:float, adjustOut:float, refundOut:float}>
     */
    public function violations(int $limit = 500): array
    {
        if (!Schema::hasTable('tbl_refunds')) {
            return [];
        }
        $adj = Schema::hasTable('tbl_booking_adjustments')
            ? DB::table('tbl_booking_adjustments')->whereIn('moneyStatus', self::ADJUST_OUT)->groupBy('bookingId')->select('bookingId', DB::raw('SUM(settleAmount) AS s'))
            : null;
        $ref = DB::table('tbl_refunds')->whereIn('status', self::REFUND_OUT)->groupBy('bookingId')->select('bookingId', DB::raw('SUM(amount) AS s'));

        $q = DB::table('tbl_booking as b')->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
            ->leftJoinSub($ref, 'rr', 'rr.bookingId', '=', 'b.bookingId');
        if ($adj) {
            $q->leftJoinSub($adj, 'aa', 'aa.bookingId', '=', 'b.bookingId');
        }
        $adjCol = $adj ? 'COALESCE(aa.s,0)' : '0';

        return $q->whereRaw("COALESCE(rr.s,0) + $adjCol > CASE WHEN c.paymentStatus IN ('y','refund_pending','refunded') THEN c.amount ELSE 0 END")
            ->limit($limit)
            ->get(['b.bookingId', 'b.bookingCode', 'c.amount as captured', DB::raw("$adjCol AS adjustOut"), DB::raw('COALESCE(rr.s,0) AS refundOut')])
            ->all();
    }
}
