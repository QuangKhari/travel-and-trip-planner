<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chi phí và lợi nhuận từng ngày khởi hành (đoàn).
 *   Doanh thu thực thu  = tiền đã thu của các đơn (kể cả đơn đã hủy mà Travela giữ lại một phần) + khoản thu thêm đã thu khi tăng người
 *   trừ hoàn tiền đã chi, hoàn phần dư khi giảm người đã chi, bồi hoàn khiếu nại đã chi.
 *   Lợi nhuận = doanh thu thực thu − tổng chi phí (dòng chi phí "dự kiến" và "thực tế" đều tính; trạng thái cho biết độ chắc chắn).
 *   Lợi nhuận dự kiến = lợi nhuận + khoản còn phải thu − khoản còn phải hoàn / bồi hoàn.
 */
class ProfitabilityService
{
    public const CATEGORIES = ['transport' => 'Vận chuyển', 'hotel' => 'Khách sạn', 'guide' => 'Hướng dẫn viên', 'tickets' => 'Vé tham quan', 'meals' => 'Ăn uống', 'insurance' => 'Bảo hiểm', 'other' => 'Khác'];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_departure_costs');
    }

    /** @return array<string,mixed> */
    public function summary(int $availabilityId): array
    {
        $ids = DB::table('tbl_booking')->where('availabilityId', $availabilityId)->pluck('bookingId')->all();
        $live = DB::table('tbl_booking')->where('availabilityId', $availabilityId)->whereIn('bookingStatus', ['y', 'f']);
        $guests = (int) (clone $live)->sum(DB::raw('numAdults + numChildren'));
        $booked = (int) round((float) (clone $live)->sum('totalPrice'));
        $cashIn = $ids ? (int) round((float) DB::table('tbl_checkout')->whereIn('bookingId', $ids)->whereIn('paymentStatus', ['y', 'refund_pending', 'refunded'])->sum('amount')) : 0;
        $refunded = $refundPending = 0;
        if ($ids && Schema::hasTable('tbl_refunds')) {
            $refunded = (int) round((float) DB::table('tbl_refunds')->whereIn('bookingId', $ids)->where('status', 'refunded')->sum('amount'));
            $refundPending = (int) round((float) DB::table('tbl_refunds')->whereIn('bookingId', $ids)->where('status', 'pending')->sum('amount'));
        }
        $adjRefunded = $adjRefundPending = $adjCollectPending = 0;
        if ($ids && Schema::hasTable('tbl_booking_adjustments')) {
            $adj = DB::table('tbl_booking_adjustments')->whereIn('bookingId', $ids);
            $adjRefunded = (int) (clone $adj)->where('moneyStatus', 'refunded')->sum('settleAmount');
            $adjRefundPending = (int) (clone $adj)->where('moneyStatus', 'pending_refund')->sum('settleAmount');
            $adjCollectPending = (int) (clone $adj)->where('moneyStatus', 'pending_collect')->sum('settleAmount');
        }
        $compPaid = $compPending = 0;
        if ($ids && Schema::hasTable('tbl_complaints')) {
            $cp = DB::table('tbl_complaints')->whereIn('bookingId', $ids);
            $compPaid = (int) (clone $cp)->where('compensationStatus', 'paid')->sum('compensationAmount');
            $compPending = (int) (clone $cp)->where('compensationStatus', 'pending')->sum('compensationAmount');
        }
        $costs = self::ready() ? DB::table('tbl_departure_costs')->where('availabilityId', $availabilityId)->get() : collect();
        $costTotal = (int) $costs->sum('amount');
        $costActual = (int) $costs->where('status', 'actual')->sum('amount');
        $byCat = [];
        foreach ($costs as $c) {
            $byCat[$c->category] = ($byCat[$c->category] ?? 0) + (int) $c->amount;
        }
        $net = $cashIn - $refunded - $adjRefunded - $compPaid;
        $profit = $net - $costTotal;
        $projected = $profit + $adjCollectPending - $refundPending - $adjRefundPending - $compPending;

        return [
            'guests' => $guests, 'bookedRevenue' => $booked, 'cashIn' => $cashIn, 'refunded' => $refunded + $adjRefunded, 'compensationPaid' => $compPaid,
            'netRevenue' => $net, 'costTotal' => $costTotal, 'costActual' => $costActual, 'costEstimated' => $costTotal - $costActual, 'costByCategory' => $byCat,
            'profit' => $profit, 'margin' => $net > 0 ? round($profit / $net * 100, 1) : null,
            'pendingIn' => $adjCollectPending, 'pendingOut' => $refundPending + $adjRefundPending + $compPending, 'projectedProfit' => $projected,
            'loss' => $profit < 0, 'projectedLoss' => $projected < 0, 'hasCosts' => $costs->isNotEmpty(),
            'estimated' => $costTotal > $costActual,
            'costPerGuest' => $guests > 0 ? (int) round($costTotal / $guests) : null,
        ];
    }

    /** Danh sách đoàn trong khoảng ngày kèm lợi nhuận. @return list<object> */
    public function listing(string $range, ?int $tourId, int $limit = 80): array
    {
        $q = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')->select('a.*', 't.title');
        $today = today()->toDateString();
        if ($range === 'future') {
            $q->where('a.departureDate', '>', $today)->orderBy('a.departureDate');
        } elseif ($range === 'past') {
            $q->where('a.departureDate', '<=', $today)->orderByDesc('a.departureDate');
        } else {
            $q->orderByDesc('a.departureDate');
        }
        if ($tourId) {
            $q->where('a.tourId', $tourId);
        }
        $rows = $q->limit($limit)->get();
        foreach ($rows as $r) {
            $r->p = $this->summary((int) $r->availabilityId);
        }

        return $rows->all();
    }
}
