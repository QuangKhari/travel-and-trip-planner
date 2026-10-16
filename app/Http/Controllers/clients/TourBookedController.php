<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\clients\Tours;
use App\Support\CancelPolicy;
use App\Services\BookingService;
use Illuminate\Support\Facades\Validator;

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

        // Chính sách hủy (CancelPolicy::MIN_DAYS ngày)
        $canCancel = CancelPolicy::allows($tour_booked->bookingStatus, $tour_booked->startDate);

        // Ảnh bìa tour để trang chi tiết đơn nhìn giống thẻ tour ở các trang khác
        $cover = DB::table('tbl_images')
            ->where('tourId', $tour_booked->tourId)
            ->orderBy('sortOrder')
            ->value('imageURL');

        return view("clients.tour-booked", compact('title', 'tour_booked', 'canCancel', 'bookingId', 'cover'));
    }

    public function cancelBooking(Request $req, BookingService $bookings)
    {
        $validator = Validator::make($req->all(), [
            'bookingId' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            toastr()->error($validator->errors()->first());
            return redirect()->route('home');
        }

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
            toastr()->success('Đã hủy đơn. Chỗ đã được trả lại.', ['positionClass' => 'toast-top-right']);
            // UX: về danh sách "Tour đã đặt" (trước đây về trang chủ nên người dùng mất ngữ cảnh)
            return redirect()->route('my-tours');
        }

        toastr()->error($refusal, ['positionClass' => 'toast-top-right']);

        // Không hủy được: ở lại đúng đơn đó để đọc lý do, thay vì đẩy về trang chủ
        $checkoutId = (int) DB::table('tbl_checkout')->where('bookingId', $bookingId)->value('checkoutId');
        return $checkoutId > 0
            ? redirect()->route('tour-booked', ['bookingId' => $bookingId, 'checkoutId' => $checkoutId])
            : redirect()->route('my-tours');
    }
}
