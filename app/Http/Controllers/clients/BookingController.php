<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;
use App\Models\clients\Booking;
use App\Models\clients\Checkout;
use App\Models\admin\PromotionModel;
use Illuminate\Support\Facades\DB;
use App\Services\BookingException;
use App\Services\BookingService;
use App\Services\CouponService;
use Illuminate\Support\Facades\Validator;



class BookingController extends Controller
{
    private $tour;
    private $booking;
    private $checkout;
    private $promotion;

    public function __construct()
    {
        parent::__construct(); // Gọi constructor của Controller để khởi tạo $user
        $this->tour = new Tours();
        $this->booking = new Booking();
        $this->checkout = new Checkout();
        $this->promotion = new PromotionModel();
    }

    public function index($id = null)
    {
        $title = 'Đặt tour';
        $tour = $id ? $this->tour->getTourDetail($id) : null;

        // Không có tour, hoặc tour đã ẩn: 404 (không tiết lộ tour ẩn có tồn tại hay không) (L-B-08)
        if (!$tour || (int) $tour->availability !== 1) {
            abort(404);
        }

        $today = now()->toDateString();
        if ($tour->startDate <= $today) {
            toastr()->error('Tour đã khởi hành, không thể đặt.');
            return redirect()->route('tour-detail', ['id' => $tour->tourId]);
        }
        if ((int) $tour->quantity <= 0) {
            toastr()->error('Tour đã hết chỗ.');
            return redirect()->route('tour-detail', ['id' => $tour->tourId]);
        }

        return view('clients.booking', compact('title', 'tour'));
    }

    public function createBooking(Request $req, BookingService $bookings)
    {
        $validator = Validator::make($req->all(), [
            'tourId'      => 'required|integer|min:1',
            'fullName'    => 'required|string|max:255',
            'email'       => ['required', 'email:filter', 'max:50', 'regex:/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/'],
            'tel'         => ['required', 'regex:/^[0-9]{10,11}$/'],
            'address'     => 'required|string|max:255',
            'numAdults'   => 'required|integer|min:1|max:50',
            'numChildren' => 'required|integer|min:0|max:50',
            'payment'     => 'required|in:office-payment',
            'couponCode'  => 'nullable|string|max:50',
        ], [
            'fullName.required'    => 'Vui lòng nhập họ và tên.',
            'email.required'       => 'Vui lòng nhập email.',
            'email.email'          => 'Email không hợp lệ.',
            'email.regex'          => 'Email không hợp lệ (cần có dạng ten@ten-mien.com).',
            'email.max'            => 'Email tối đa 50 ký tự.',
            'tel.required'         => 'Vui lòng nhập số điện thoại.',
            'tel.regex'            => 'Số điện thoại phải có 10-11 chữ số.',
            'address.required'     => 'Vui lòng nhập địa chỉ.',
            'numAdults.min'        => 'Phải có ít nhất 1 người lớn.',
            'numAdults.max'        => 'Tối đa 50 người lớn mỗi đơn.',
            'numChildren.max'      => 'Tối đa 50 trẻ em mỗi đơn.',
            'payment.required'     => 'Vui lòng chọn phương thức thanh toán.',
            'payment.in'           => 'Hiện chỉ hỗ trợ thanh toán tại văn phòng.',
        ]);

        $tourId = (int) $req->input('tourId');

        if ($validator->fails()) {
            toastr()->error($validator->errors()->first());
            return $tourId
                ? redirect()->route('tour-detail', ['id' => $tourId])
                : redirect()->route('tours');
        }

        try {
            $result = $bookings->create((int) $this->getUserId(), $validator->validated());
        } catch (BookingException $e) {
            toastr()->error($e->getMessage());
            return redirect()->route('tour-detail', ['id' => $tourId]);
        }

        toastr()->success('Đặt tour thành công! Mã đơn: ' . $result['bookingCode']
            . '. Chỗ được giữ trong ' . (int) config('travela.hold_hours', 48) . ' giờ, vui lòng đến văn phòng để xác nhận.');
        return redirect()->route('tours');
    }

    public function checkBooking(Request $req)
    {
        $tourId = $req->tourId;
        $userId = $this->getUserId();
        $check = $this->booking->checkBooking($tourId, $userId);
        if (!$check) {
            return response()->json(['success' => false]);
        }
        return response()->json(['success' => true]);
    }

    /**
     * Chỉ để hiển thị: số tiền thật vẫn do BookingService tính lại khi đặt.
     * Server tự tính tổng tiền từ tourId + số khách, KHÔNG tin totalPrice từ trình duyệt.
     */
    public function applyCoupon(Request $request, CouponService $coupons)
    {
        $validator = Validator::make($request->all(), [
            'code'        => 'required|string|max:50',
            'tourId'      => 'required|integer|min:1',
            'numAdults'   => 'required|integer|min:1|max:50',
            'numChildren' => 'required|integer|min:0|max:50',
        ], [
            'code.required' => 'Vui lòng nhập mã giảm giá.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
        }

        $tour = DB::table('tbl_tours')
            ->where('tourId', (int) $request->input('tourId'))
            ->where('availability', 1)
            ->where('quantity', '>', 0)
            ->whereDate('startDate', '>', now()->toDateString())
            ->first();

        if (!$tour) {
            return response()->json(['success' => false, 'message' => 'Tour không tồn tại hoặc đã ngừng nhận đặt.']);
        }

        $subtotal = (int) round(
            $tour->priceAdult * (int) $request->input('numAdults')
                + $tour->priceChild * (int) $request->input('numChildren')
        );

        // Mọi luật (còn lượt, còn hạn, giới hạn lượt/người) nằm ở CouponService
        try {
            $quote = $coupons->quote((string) $request->input('code'), $subtotal, (int) $this->getUserId());
        } catch (BookingException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }

        return response()->json([
            'success'        => true,
            'promotionId'    => $quote['promotionId'],
            'discount'       => $quote['percent'],            // phần trăm; JS tự tính lại khi đổi số khách
            'discountAmount' => $quote['discount'],
            'newTotal'       => max(0, $subtotal - $quote['discount']),
            'message'        => 'Áp dụng thành công! Giảm ' . $quote['percent'] . '%',
        ]);
    }
}
