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

        // Ngày đi / về của ĐÚNG ngày khởi hành khách đã đặt (tour có thể có nhiều ngày khởi hành)
        $tour_booked->startDate = BookingService::tripStartDate($tour_booked);
        $tour_booked->endDate = BookingService::tripEndDate($tour_booked);

        // Chính sách hủy và hoàn tiền theo bậc (config/booking.php)
        $policy = CancelPolicy::forCustomer($tour_booked->bookingStatus, $tour_booked->startDate, ($tour_booked->paymentStatus ?? 'n') === 'y');
        $canCancel = $policy['allowed'];
        $refund = \Illuminate\Support\Facades\Schema::hasTable('tbl_refunds')
            ? DB::table('tbl_refunds')->where('bookingId', $tour_booked->bookingId)->first() : null;

        // Ảnh bìa tour để trang chi tiết đơn nhìn giống thẻ tour ở các trang khác
        $cover = DB::table('tbl_images')
            ->where('tourId', $tour_booked->tourId)
            ->orderBy('sortOrder')
            ->value('imageURL');

        return view("clients.tour-booked", compact('title', 'tour_booked', 'canCancel', 'bookingId', 'cover', 'policy', 'refund'));
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
        $refundMsg = '';

        // Chỉ dùng bookingId từ trình duyệt. Tour, số lượng, trạng thái đều lấy từ database
        DB::transaction(function () use ($bookingId, $userId, $bookings, &$cancelled, &$refusal, &$refundMsg) {
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
            $tour = DB::table('tbl_tours')->where('tourId', $booking->tourId)->first(['startDate', 'endDate', 'durationDays']);
            $startDate = $booking->departureDate ?? $tour->startDate;
            $paid = DB::table('tbl_checkout')->where('bookingId', $bookingId)->value('paymentStatus') === 'y';
            $policy = CancelPolicy::forCustomer($booking->bookingStatus, $startDate, $paid);
            if (!$policy['allowed']) {
                $refusal = $policy['message'];
                return;
            }

            // Đổi trạng thái, trả chỗ đúng một lần và nhả mã giảm giá; đã thanh toán thì tạo yêu cầu hoàn tiền theo bậc
            $bookings->cancelLocked($booking, 'customer', !$paid);
            if ($paid) {
                $refund = app(\App\Services\RefundService::class)->requestLocked($booking, 'customer', $userId, null, 'Khách tự hủy trên web');
                $refundMsg = $refund->amount > 0
                    ? ' Travela sẽ hoàn ' . number_format((float) $refund->amount, 0, ',', '.') . 'đ (' . $refund->percent . '%) cho bạn.'
                    : '';
            }

            $cancelled = true;
        });

        if ($cancelled) {
            \App\Support\AdminBadges::forget();
            toastr()->success('Đã hủy đơn. Chỗ đã được trả lại.' . $refundMsg, ['positionClass' => 'toast-top-right']);
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
