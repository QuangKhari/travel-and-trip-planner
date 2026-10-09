<?php

namespace App\Models\admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use App\Support\BookingPii;

class DashboardModel extends Model
{
    use HasFactory;

    public function getSummary()
    {
        $tourWorking = DB::table('tbl_tours')
            ->where('availability', 1)
            ->count();
        $countBooking = DB::table('tbl_booking')
            ->where('bookingStatus', '!=', 'c')
            ->count();
        // Chỉ tính đơn đã thanh toán VÀ chưa bị hủy
        $totalAmount = DB::table('tbl_checkout')
            ->join('tbl_booking', 'tbl_booking.bookingId', '=', 'tbl_checkout.bookingId')
            ->where('tbl_checkout.paymentStatus', 'y')
            ->where('tbl_booking.bookingStatus', '!=', 'c')
            ->sum('tbl_checkout.amount');

        // Trả về mảng chứa các dữ liệu tổng hợp
        return [
            'tourWorking' => $tourWorking,
            'countBooking' => $countBooking,
            'totalAmount' => $totalAmount,
        ];
    }

    public function getValueDomain()
    {
        // Lấy số lượng tours cho mỗi miền (b, t, n)
        return DB::table('tbl_tours')
            ->select(DB::raw('domain, COUNT(*) as count'))
            ->whereIn('domain', ['b', 't', 'n'])  // Chỉ lấy các miền có domain b, t, n
            ->groupBy('domain')  // Nhóm theo domain
            ->get()
            ->pluck('count', 'domain');  // Trả về mảng với key là domain và value là count
    }

    public function getValuePayment()
    {
        return DB::table('tbl_checkout')
            ->join('tbl_booking', 'tbl_booking.bookingId', '=', 'tbl_checkout.bookingId')
            ->where('tbl_checkout.paymentStatus', 'y')
            ->where('tbl_booking.bookingStatus', '!=', 'c')
            ->select('tbl_checkout.paymentMethod', DB::raw('COUNT(*) as count'))
            ->groupBy('tbl_checkout.paymentMethod')
            ->get()
            ->toArray();
    }

    public function getMostTourBooked()
    {
        return DB::table('tbl_tours')
            ->join('tbl_booking', 'tbl_tours.tourId', '=', 'tbl_booking.tourId')
            ->where('tbl_booking.bookingStatus', '!=', 'c')   // không đếm đơn đã hủy
            ->select('tbl_tours.tourId', 'tbl_tours.title', 'tbl_tours.quantity', DB::raw('SUM(tbl_booking.numAdults + tbl_booking.numChildren) as booked_quantity'))
            ->groupBy('tbl_tours.tourId', 'tbl_tours.quantity', 'tbl_tours.title')
            ->orderByDesc(DB::raw('SUM(tbl_booking.numAdults + tbl_booking.numChildren)')) // Sắp xếp theo số lượng đặt tour giảm dần
            ->take(5) // Top 5 tour được đặt nhiều nhất (spec)
            ->get();
    }

    public function getNewBooking()
    {
        $rows = DB::table('tbl_booking')
            ->join('tbl_tours', 'tbl_booking.tourId', '=', 'tbl_tours.tourId')
            ->where('tbl_booking.bookingStatus', 'n')
            ->orderByDesc('tbl_booking.bookingDate')
            ->select('tbl_booking.*', 'tbl_tours.title as tour_name')
            ->take(5)
            ->get();

        return BookingPii::decryptAll($rows);
    }

    /**
     * Doanh thu 12 tháng của MỘT năm: tính theo đơn đã thanh toán, chưa hủy,
     * nhóm theo tháng của ngày thanh toán; không cộng dồn các năm với nhau.
     */
    public function getRevenuePerMonth(?int $year = null)
    {
        $year = $year ?? (int) now()->year;

        $monthlyRevenue = DB::table('tbl_checkout')
            ->join('tbl_booking', 'tbl_booking.bookingId', '=', 'tbl_checkout.bookingId')
            ->where('tbl_checkout.paymentStatus', 'y')
            ->where('tbl_booking.bookingStatus', '!=', 'c')
            ->whereYear('tbl_checkout.paymentDate', $year)
            ->selectRaw('MONTH(tbl_checkout.paymentDate) AS month, SUM(tbl_checkout.amount) AS revenue')
            ->groupByRaw('MONTH(tbl_checkout.paymentDate)')
            ->get();

        // Mảng 12 phần tử, tháng không có doanh thu = 0
        $revenueData = array_fill(0, 12, 0);

        foreach ($monthlyRevenue as $data) {
            $revenueData[$data->month - 1] = (float) $data->revenue;
        }

        return $revenueData;
    }
}
