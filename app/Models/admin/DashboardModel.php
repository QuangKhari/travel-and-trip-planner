<?php

namespace App\Models\admin;

use App\Support\BookingPii;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Số liệu dashboard theo đúng định nghĩa §12.3 của đặc tả (D-07).
 * Tiền "đã thu" = đơn có paymentStatus y / refund_pending / refunded (tiền đã vào), tính theo ngày thanh toán;
 * doanh thu thuần = đã thu − số tiền đã hoàn (refunded) tính theo ngày hoàn.
 */
class DashboardModel extends Model
{
    use HasFactory;

    private const RECEIVED = ['y', 'refund_pending', 'refunded'];

    public function getSummary(): array
    {
        // L-F-12: "đang hoạt động" = khách đặt được ngay (cùng điều kiện với danh sách tour phía khách)
        $tourWorking = DB::table('tbl_tours')->where('availability', 1)->where('quantity', '>', 0)
            ->whereDate('startDate', '>', now()->toDateString())->count();
        $countBooking = DB::table('tbl_booking')->where('bookingStatus', '!=', 'c')->count();
        $received = (float) DB::table('tbl_checkout')->whereIn('paymentStatus', self::RECEIVED)->sum('amount');
        $refunded = Schema::hasTable('tbl_refunds') ? (float) DB::table('tbl_refunds')->where('status', 'refunded')->sum('amount') : 0;

        return [
            'tourWorking' => $tourWorking,
            'countBooking' => $countBooking,
            'totalAmount' => $received - $refunded,
            'received' => $received,
            'refunded' => $refunded,
        ];
    }

    /** Doanh thu thuần từng tháng của MỘT năm (có năm, không cộng dồn các năm). @return array{net:float[], gross:float[], refund:float[]} */
    public function getRevenue(int $year): array
    {
        $gross = array_fill(0, 12, 0.0);
        $refund = array_fill(0, 12, 0.0);
        $rows = DB::table('tbl_checkout')->whereIn('paymentStatus', self::RECEIVED)->whereNotNull('paymentDate')
            ->whereYear('paymentDate', $year)
            ->selectRaw('MONTH(paymentDate) m, SUM(amount) v')->groupByRaw('MONTH(paymentDate)')->get();
        foreach ($rows as $r) {
            $gross[$r->m - 1] = (float) $r->v;
        }
        if (Schema::hasTable('tbl_refunds')) {
            $rows = DB::table('tbl_refunds')->where('status', 'refunded')->whereYear('processedAt', $year)
                ->selectRaw('MONTH(processedAt) m, SUM(amount) v')->groupByRaw('MONTH(processedAt)')->get();
            foreach ($rows as $r) {
                $refund[$r->m - 1] = (float) $r->v;
            }
        }
        $net = array_map(fn($g, $r) => $g - $r, $gross, $refund);

        return ['net' => $net, 'gross' => $gross, 'refund' => $refund];
    }

    /** Giữ hàm cũ cho chỗ khác còn gọi */
    public function getRevenuePerMonth(?int $year = null)
    {
        return $this->getRevenue($year ?? (int) now()->year)['net'];
    }

    /** Số đơn theo trạng thái n / y / f / c (khách hủy, admin hủy) / e (hết hạn giữ chỗ). */
    public function statusCounts(): array
    {
        $out = ['n' => 0, 'y' => 0, 'f' => 0, 'c' => 0, 'e' => 0];
        $rows = DB::table('tbl_booking')->selectRaw("bookingStatus s, COALESCE(cancelReason,'') r, COUNT(*) n")->groupBy('bookingStatus', 'cancelReason')->get();
        foreach ($rows as $r) {
            $k = $r->s === 'c' && $r->r === 'expired' ? 'e' : $r->s;
            $out[$k] = ($out[$k] ?? 0) + (int) $r->n;
        }

        return $out;
    }

    public function getValueDomain()
    {
        return DB::table('tbl_tours')->select(DB::raw('domain, COUNT(*) as count'))
            ->whereIn('domain', ['b', 't', 'n'])->where('availability', 1)
            ->groupBy('domain')->get()->pluck('count', 'domain');
    }

    /** Top 5 tour theo số khách của đơn đã xác nhận / hoàn tất (loại hủy, hết hạn). */
    public function getMostTourBooked()
    {
        return DB::table('tbl_tours')
            ->join('tbl_booking', 'tbl_tours.tourId', '=', 'tbl_booking.tourId')
            ->whereIn('tbl_booking.bookingStatus', ['y', 'f'])
            ->select('tbl_tours.tourId', 'tbl_tours.title', DB::raw('SUM(tbl_booking.numAdults + tbl_booking.numChildren) as booked_quantity'), DB::raw('SUM(tbl_booking.totalPrice) as revenue'))
            ->groupBy('tbl_tours.tourId', 'tbl_tours.title')
            ->orderByDesc('booked_quantity')->take(5)->get();
    }

    /** Tỷ lệ lấp đầy các ngày khởi hành sắp tới (đã bán + đang giữ) / tổng chỗ; và theo từng tour. */
    public function fillRate(): array
    {
        if (!Schema::hasTable('tbl_tour_availability')) {
            return ['avg' => null, 'tours' => collect(), 'departures' => 0];
        }
        $today = now()->toDateString();
        $all = DB::table('tbl_tour_availability')->where('departureDate', '>', $today)->where('status', 'open')
            ->selectRaw('COUNT(*) n, SUM(totalSlots) total, SUM(bookedSlots) booked, SUM(heldSlots) held')->first();
        $tours = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->where('a.departureDate', '>', $today)->where('a.status', 'open')
            ->groupBy('a.tourId', 't.title')
            ->selectRaw('a.tourId, t.title, COUNT(*) deps, SUM(a.totalSlots) total, SUM(a.bookedSlots) booked, SUM(a.heldSlots) held, AVG((a.bookedSlots + a.heldSlots) / NULLIF(a.totalSlots,0)) avgFill')
            ->orderByDesc('avgFill')->limit(6)->get();

        return [
            'avg' => ($all && $all->total > 0) ? round(($all->booked + $all->held) / $all->total * 100, 1) : null,
            'booked' => (int) ($all->booked ?? 0),
            'held' => (int) ($all->held ?? 0),
            'total' => (int) ($all->total ?? 0),
            'departures' => (int) ($all->n ?? 0),
            'tours' => $tours,
        ];
    }

    /** Đánh giá: trung bình và phân bố sao, chỉ tính đánh giá đang hiện. */
    public function ratings(): array
    {
        $q = DB::table('tbl_reviews');
        if (Schema::hasColumn('tbl_reviews', 'isHidden')) {
            $q->where('isHidden', 0);
        }
        $dist = (clone $q)->selectRaw('rating, COUNT(*) n')->groupBy('rating')->pluck('n', 'rating');
        $count = (int) $dist->sum();
        $avg = $count ? round((clone $q)->avg('rating'), 2) : null;
        $out = [];
        for ($s = 5; $s >= 1; $s--) {
            $out[$s] = (int) ($dist[$s] ?? 0);
        }

        return ['avg' => $avg, 'count' => $count, 'dist' => $out];
    }

    public function getNewBooking()
    {
        $rows = DB::table('tbl_booking')
            ->join('tbl_tours', 'tbl_booking.tourId', '=', 'tbl_tours.tourId')
            ->where('tbl_booking.bookingStatus', 'n')
            ->orderByDesc('tbl_booking.bookingDate')
            ->select('tbl_booking.*', 'tbl_tours.title as tour_name')
            ->take(6)->get();

        return BookingPii::decryptAll($rows);
    }

    /** Đơn mới (n) trong 24 giờ gần nhất */
    public function newIn24h(): int
    {
        return DB::table('tbl_booking')->where('bookingStatus', 'n')->where('bookingDate', '>=', now()->subDay())->count();
    }

    /** Chuyến khởi hành trong 7 ngày tới (để chuẩn bị điều hành) */
    public function upcomingDepartures()
    {
        if (!Schema::hasTable('tbl_tour_availability')) {
            return collect();
        }

        return DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->whereBetween('a.departureDate', [now()->addDay()->toDateString(), now()->addDays(7)->toDateString()])
            ->where('a.status', 'open')->orderBy('a.departureDate')->limit(6)
            ->get(['a.*', 't.title']);
    }
}
