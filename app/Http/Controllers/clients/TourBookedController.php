<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\clients\Tours;

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

        $hide = ''; // ràng buộc 7 ngày xử lý riêng ở L-B-07
        return view("clients.tour-booked", compact('title', 'tour_booked', 'hide', 'bookingId'));
    }

    public function cancelBooking(Request $req)
    {
        $bookingId = (int) $req->input('bookingId');
        $userId    = (int) $this->getUserId();
        $cancelled = false;

        // Chỉ dùng bookingId từ trình duyệt. Tour, số lượng, trạng thái đều lấy từ database (L-B-05)
        DB::transaction(function () use ($bookingId, $userId, &$cancelled) {
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

            DB::table('tbl_booking')
                ->where('bookingId', $bookingId)
                ->update(['bookingStatus' => 'c']);

            // Trả chỗ đúng số lượng đã đặt, đúng một lần
            DB::table('tbl_tours')
                ->where('tourId', $booking->tourId)
                ->increment('quantity', (int) $booking->numAdults + (int) $booking->numChildren);

            $cancelled = true;
        });

        if ($cancelled) {
            toastr()->success('Hủy thành công!', ['positionClass' => 'toast-top-right']);
        } else {
            toastr()->error('Đơn này không thể hủy.', ['positionClass' => 'toast-top-right']);
        }

        return redirect()->route('home');
    }
}
