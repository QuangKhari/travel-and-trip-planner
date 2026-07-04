<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;
use App\Models\clients\Booking;
use App\Models\clients\Checkout;
use App\Models\admin\PromotionModel;
use Illuminate\Support\Facades\Http;



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
    public function index($id)
    {
        $title = 'Đặt tour';
        $tour = $this->tour->getTourDetail($id);
        $transIdMomo = null;
        //dd($tour);
        return view('clients.booking', compact('title', 'tour', 'transIdMomo'));
    }

    private function generateBookingCode()
    {
    return 'TOUR' . date('YmdHis') . rand(100,999);
    }
    public function createBooking(Request $req)
    {
    //     dd([
    //     'couponCode' => $req->input('couponCode'),
    //     'all' => $req->all()
    // ]);
        //dd($req->all());
        $address = $req->input('address');
        $email = $req->input('email');
        $fullName = $req->input('fullName');
        $numAdults = $req->input('numAdults');
        $numChildren = $req->input('numChildren');
        $paymentMethod = $req->payment;
        $tel = $req->input('tel');
        $totalPrice = $req->input('totalPrice');
        $tourId = $req->input('tourId');
        $userId = $this->getUserId();
        $bookingCode = $this->generateBookingCode();

        $dataBooking = [
            'tourId' => $tourId,
            'userId' => $userId,
            'address' => $address,
            'fullName' => $fullName,
            'email' => $email,
            'numAdults' => $numAdults,
            'numChildren' => $numChildren,
            'phoneNumber' => $tel,
            'totalPrice' => $totalPrice,
            'bookingCode' => $bookingCode
        ];

        $bookingId = $this->booking->createBooking($dataBooking);

        session([
            'bookingId' => $bookingId,
            'bookingCode' => $bookingCode
        ]);

        $dataCheckout = [
            'bookingId' => $bookingId,
            'paymentMethod' => $paymentMethod,
            'amount' => $totalPrice,
            'paymentStatus' => 'n',
        ];
    
        $checkout = $this->checkout->createCheckout($dataCheckout);

        if(empty($bookingId) || empty($checkout)){
            toastr()->error('Đặt tour thất bại. Vui lòng thử lại.');
            return redirect()->back();
        }

        // Trừ số lượng promotion
        $promotionId = $req->input('promotionId');
        if (!empty($promotionId)) {
            $this->promotion->decreaseQuantity($promotionId);
        }

        // Update quantity tour
        $tour = $this->tour->getTourDetail($tourId);
        $dataUpdate = [
            'quantity' => $tour->quantity - ($numAdults + $numChildren)
        ];
        $this->tour->updateTours($tourId, $dataUpdate);

        if($paymentMethod == 'banking'){
         return view('clients.qr-payment',[
                'title' => 'Thanh toán QR',
                'bookingCode' => $bookingCode,
                'amount' => $totalPrice,
                'bookingId' => $bookingId
            ]);
        }

        toastr()->success('Đặt tour thành công!');
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
    public function uploadTransferProof(Request $request)
    {
        $request->validate([
            'transferProof' => 'required|image|max:5120', // tối đa 5MB
        ]);

        $bookingId = session('bookingId');

        if (!$bookingId) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn đặt tour, vui lòng đặt lại.'
            ]);
        }

        $file = $request->file('transferProof');
        $filename = 'proof_' . $bookingId . '_' . time() . '.' . $file->getClientOriginalExtension();
        $destinationPath = public_path('clients/assets/images/transfer-proofs/');

        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $file->move($destinationPath, $filename);

        $this->booking->updateTransferProof($bookingId, $filename);

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
    public function checkBooking(Request $req){
        $tourId = $req->tourId;
        $userId = $this->getUserId();
        $check = $this->booking->checkBooking($tourId,$userId);
        if (!$check) {
            return response()->json(['success' => false]);
        }
        return response()->json(['success' => true]);
    }
    public function applyCoupon(Request $request)
{
    $code = strtoupper(trim($request->input('code')));
    $totalPrice = (int) $request->input('totalPrice');

    $promotion = $this->promotion->getPromotionByCode($code);

    // Kiểm tra tồn tại
    if (!$promotion) {
        return response()->json([
            'success' => false,
            'message' => 'Mã giảm giá không tồn tại'
        ]);
    }

    // Kiểm tra còn hiệu lực
    if ($promotion->status !== 'y') {
        return response()->json([
            'success' => false,
            'message' => 'Mã giảm giá đã bị vô hiệu hóa'
        ]);
    }

    // Kiểm tra số lượng
    if ($promotion->quantity <= 0) {
        return response()->json([
            'success' => false,
            'message' => 'Mã giảm giá đã hết lượt sử dụng'
        ]);
    }

    // Kiểm tra thời hạn
    $now = now();
    if ($now->lt($promotion->startDate) || $now->gt($promotion->endDate)) {
        return response()->json([
            'success' => false,
            'message' => 'Mã giảm giá chưa đến hạn hoặc đã hết hạn'
        ]);
    }

    $discountAmount = $totalPrice * ($promotion->discount / 100);
    $newTotal = $totalPrice - $discountAmount;

    return response()->json([
        'success'        => true,
        'promotionId'    => $promotion->promotionId,
        'discount'       => $promotion->discount,
        'discountAmount' => $discountAmount,
        'newTotal'       => $newTotal,
        'message'        => "Áp dụng thành công! Giảm {$promotion->discount}%"
    ]);
}
}