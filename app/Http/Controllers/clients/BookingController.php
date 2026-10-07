<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;
use App\Models\clients\Booking;
use App\Models\clients\Checkout;
use App\Models\admin\PromotionModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Services\InvalidImageException;
use App\Services\UserMediaService;
use App\Services\BookingException;
use App\Services\BookingService;
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

        $transIdMomo = null;
        return view('clients.booking', compact('title', 'tour', 'transIdMomo'));
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

        toastr()->success('Đặt tour thành công! Mã đơn: ' . $result['bookingCode']);
        return redirect()->route('tours');
    }

    public function confirmQrPayment()
    {
        $bookingId = session('bookingId');

        $dataUpdate = [
            'paymentStatus' => 'w'
        ];

        $this->checkout->updateCheckout($bookingId, $dataUpdate);

        return redirect()->route('tours')
            ->with('success', 'Đã gửi yêu cầu xác nhận thanh toán.');
    }

    // Nhận ảnh biên lai chuyển khoản từ khách hàng, gắn vào đúng đơn booking đang chờ
    public function uploadTransferProof(Request $request, UserMediaService $media)
    {
        // Loại file thật được kiểm tra trong service (không tin đuôi file)
        $request->validate([
            'transferProof' => 'required|file|max:5120', // tối đa 5MB
        ]);

        $bookingId = session('bookingId');

        if (!$bookingId) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn đặt tour, vui lòng đặt lại.'
            ]);
        }

        try {
            // Lưu ở kho RIÊNG TƯ (storage/app/private), không có URL công khai
            $path = $media->storeProof($request->file('transferProof'), (int) $bookingId);
        } catch (InvalidImageException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $old = DB::table('tbl_booking')->where('bookingId', $bookingId)->value('transferProofImage');

        $this->booking->updateTransferProof($bookingId, $path);

        // Khách gửi lại biên lai mới → xóa biên lai cũ của đơn này
        if ($old && $old !== $path) {
            $media->deleteProof($old);
        }

        // Đồng thời đánh dấu đơn đang chờ admin xác nhận
        $this->checkout->updateCheckout($bookingId, ['paymentStatus' => 'w']);

        return response()->json([
            'success' => true,
            'message' => 'Đã gửi ảnh chuyển khoản. Vui lòng chờ quản trị viên xác nhận.'
        ]);
    }


    public function createMomoPayment(Request $request)
    {
        session()->put('tourId', $request->tourId);

        try {
            // $amount = $request->amount;
            $amount = 10000;

            // Các thông tin cần thiết của MoMo
            $endpoint = "https://test-payment.momo.vn/v2/gateway/api/create";
            $partnerCode = "MOMOBKUN20180529"; // mã partner của bạn
            $accessKey = "klm05TvNBzhg7h7j"; // access key của bạn
            $secretKey = "at67qH6mk8w5Y1nAyMoYKMWACiEi2bsa"; // secret key của bạn

            $orderInfo = "Thanh toán đơn hàng";
            $requestId = time();
            $orderId = time();
            $extraData = "";
            $redirectUrl = "http://127.0.0.1:8000/booking"; // URL chuyển hướng
            $ipnUrl = "http://127.0.0.1:8000/booking"; // URL IPN
            $requestType = 'payWithATM'; // Kiểu yêu cầu

            // Tạo rawHash và chữ ký theo cách thủ công
            $rawHash = "accessKey=" . $accessKey .
                "&amount=" . $amount .
                "&extraData=" . $extraData .
                "&ipnUrl=" . $ipnUrl .
                "&orderId=" . $orderId .
                "&orderInfo=" . $orderInfo .
                "&partnerCode=" . $partnerCode .
                "&redirectUrl=" . $redirectUrl .
                "&requestId=" . $requestId .
                "&requestType=" . $requestType;

            // Tạo chữ ký
            $signature = hash_hmac("sha256", $rawHash, $secretKey);

            // Dữ liệu gửi đến MoMo
            $data = [
                'partnerCode' => $partnerCode,
                'partnerName' => "Test", // Tên đối tác
                'storeId' => "MomoTestStore", // ID cửa hàng
                'requestId' => $requestId,
                'amount' => $amount,
                'orderId' => $orderId,
                'orderInfo' => $orderInfo,
                'redirectUrl' => $redirectUrl,
                'ipnUrl' => $ipnUrl,
                'lang' => 'vi',
                'extraData' => $extraData,
                'requestType' => $requestType,
                'signature' => $signature
            ];

            // Gửi yêu cầu POST đến MoMo để tạo yêu cầu thanh toán
            $response = Http::post($endpoint, $data);

            if ($response->successful()) {
                $body = $response->json();
                if (isset($body['payUrl'])) {
                    return response()->json(['payUrl' => $body['payUrl']]);
                } else {
                    // Trả về thông tin lỗi trong response nếu không có 'payUrl'
                    return response()->json(['error' => 'Invalid response from MoMo', 'details' => $body], 400);
                }
            } else {
                // Trả về thông tin lỗi trong response nếu lỗi kết nối
                return response()->json(['error' => 'Lỗi kết nối với MoMo', 'details' => $response->body()], 500);
            }
        } catch (\Exception $e) {
            // Trả về chi tiết ngoại lệ trong response
            return response()->json(['error' => 'Đã xảy ra lỗi', 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    public function handlePaymentMomoCallback(Request $request)
    {
        $resultCode = $request->input('resultCode');
        $transIdMomo = $request->query('transId');
        // dd(session()->get('tourId'));
        $tourId = session()->get('tourId');
        $tour = $this->tour->getTourDetail($tourId);
        session()->forget('tourId');
        // Handle the payment response
        if ($resultCode == '0') {
            $title = 'Đã thanh toán';
            return view('clients.booking', compact('title', 'tour', 'transIdMomo'));
        } else {
            // Payment failed, handle the error accordingly
            $title = 'Thanh toán thất bại';
            return view('clients.booking', compact('title', 'tour'));
        }
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
    public function applyCoupon(Request $request)
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
            ->first();

        if (!$tour) {
            return response()->json(['success' => false, 'message' => 'Tour không tồn tại hoặc đã ngừng nhận đặt.']);
        }

        $subtotal = (int) round(
            $tour->priceAdult * (int) $request->input('numAdults')
                + $tour->priceChild * (int) $request->input('numChildren')
        );

        $code      = strtoupper(trim((string) $request->input('code')));
        $promotion = $this->promotion->getPromotionByCode($code);

        if (!$promotion) {
            return response()->json(['success' => false, 'message' => 'Mã giảm giá không tồn tại']);
        }

        if ($promotion->status !== 'y') {
            return response()->json(['success' => false, 'message' => 'Mã giảm giá đã bị vô hiệu hóa']);
        }

        if ($promotion->quantity <= 0) {
            return response()->json(['success' => false, 'message' => 'Mã giảm giá đã hết lượt sử dụng']);
        }

        // startDate/endDate là kiểu DATE ('Y-m-d'): so sánh theo ngày, ngày cuối còn dùng được trọn ngày.
        // Cùng cách so với BookingService để xem trước và đặt thật không lệch nhau.
        $today = now()->toDateString();
        if ($promotion->startDate > $today || $promotion->endDate < $today) {
            return response()->json(['success' => false, 'message' => 'Mã giảm giá chưa đến hạn hoặc đã hết hạn']);
        }

        $percent        = min(100, max(0, (float) $promotion->discount));
        $discountAmount = (int) round($subtotal * $percent / 100);

        return response()->json([
            'success'        => true,
            'promotionId'    => $promotion->promotionId,
            'discount'       => $percent,                    // phần trăm; JS tự tính lại khi đổi số khách
            'discountAmount' => $discountAmount,
            'newTotal'       => max(0, $subtotal - $discountAmount),
            'message'        => "Áp dụng thành công! Giảm {$percent}%",
        ]);
    }
}
