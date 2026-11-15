<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Services\TourStops;
use App\Support\BookingPii;
use App\Support\TourTicket;
use Illuminate\Support\Facades\DB;

/**
 * GET /ve-tour/{bookingId}: vé điện tử của đơn tour (mã QR, giờ và điểm tập trung, số khách, cách tham gia).
 * Trang nhẹ, không phụ thuộc mạng sau khi mở một lần: service worker lưu bản sao (sw.js), QR vẽ bằng JS tại chỗ.
 * Chỉ chủ đơn xem được; đơn khác / không tồn tại: 404 như trang chi tiết đơn.
 */
class TourTicketController extends Controller
{
    public function show(int $bookingId)
    {
        $uid = (int) session('userId');
        $b = DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->where('b.bookingId', $bookingId)->where('b.userId', $uid)
            ->first(['b.*', 't.title', 't.time', 't.durationDays', 't.startDate as tStart', 't.endDate as tEnd', 't.destination']);
        abort_unless($b, 404);
        BookingPii::decrypt($b);
        $b->startDate = BookingService::tripStartDate($b);
        $b->endDate = BookingService::tripEndDate($b);
        $ok = TourTicket::eligible($b);
        $day1 = app(TourStops::class)->forTour((int) $b->tourId)[1] ?? [];
        $meet = collect($day1)->firstWhere('kind', 'meet');

        return response()->view('clients.ticket', [
            'b' => $b, 'ok' => $ok, 'code' => $ok ? TourTicket::code($b) : null, 'meet' => $meet,
            'policy' => \App\Support\CancelPolicy::freeUntil($b->startDate),
            'phone' => config('site.phone'),
        ])->header('Cache-Control', 'private, no-store');   // trình duyệt không lưu; service worker giữ bản sao riêng cho lúc mất mạng
    }
}
