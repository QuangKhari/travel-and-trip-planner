<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\clients\Tours;
use App\Support\CancelPolicy;
use App\Services\BookingService;

class TourBookedController extends Controller
{
    private $tour;

    public function __construct()
    {
        parent::__construct(); // khởi tạo $this->user cho getUserId()
        $this->tour = new Tours();
    }

    public function index(Request $req)
    {
        $title = "Tour đã đặt";

        $bookingId  = (int) $req->input('bookingId');
        $checkoutId = (int) $req->input('checkoutId');
        $userId     = (int) $this->getUserId();

        $tour_booked = $this->tour->tourBooked($bookingId, $checkoutId, $userId);

        // Không phải đơn của mình (hoặc không tồn tại): 404, không phân biệt hai trường hợp (L-B-04)
        if (!$tour_booked) {
            abort(404);
        }

        // Chính sách hủy 7 ngày 
        $canCancel = CancelPolicy::allows($tour_booked->bookingStatus, $tour_booked->startDate);

        return view("clients.tour-booked", compact('title', 'tour_booked', 'canCancel', 'bookingId'));
    }

    public function cancelBooking(Request $req, BookingService $bookings)
    {
        $bookingId = (int) $req->input('bookingId');
        $userId    = (int) $this->getUserId();
        $cancelled = false;
        $refusal   = 'Đơn này không thể hủy.';

        // Chỉ dùng bookingId từ trình duyệt. Tour, số lượng, trạng thái đều lấy từ database
        DB::transaction(function () use ($bookingId, $userId, $bookings, &$cancelled, &$refusal) {
            $booking = DB::table('tbl_booking')
                ->where('bookingId', $bookingId)
                ->where('userId', $userId)           // chỉ chủ đơn
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                abort(404);
            }

            // Chỉ hủy được đơn chưa xác nhận (n) hoặc đã xác nhận (y); đã hủy (c) hoặc hoàn tất (f) thì không
            if (!in_array($booking->bookingStatus, ['n', 'y'], true)) {
                return;
            }

            // Cùng chính sách với màn hình: gọi thẳng POST /cancel-booking cũng không qua mặt được
            $startDate = DB::table('tbl_tours')->where('tourId', $booking->tourId)->value('startDate');
            if (!CancelPolicy::allows($booking->bookingStatus, $startDate)) {
                $refusal = 'Chỉ hủy được tour trước ngày khởi hành ít nhất ' . CancelPolicy::MIN_DAYS . ' ngày.';
                return;
            }

            // Đổi trạng thái, trả chỗ đúng một lần và nhả mã giảm giá
            $bookings->cancelLocked($booking);

            $cancelled = true;
        });

        if ($cancelled) {
            toastr()->success('Hủy thành công!', ['positionClass' => 'toast-top-right']);
        } else {
            toastr()->error($refusal, ['positionClass' => 'toast-top-right']);
        }

        return redirect()->route('home');
    }
}
