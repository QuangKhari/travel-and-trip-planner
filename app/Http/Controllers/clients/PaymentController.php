<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\VnpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * VNPay phía khách (§7.1): Create (chuyển sang cổng) → Return (chỉ hiển thị) ; IPN là nguồn xác nhận duy nhất.
 */
class PaymentController extends Controller
{
    /** Tạo link và chuyển khách sang VNPay. Chỉ chủ đơn. */
    public function pay(Request $request, int $bookingId, VnpayService $vnpay)
    {
        $own = DB::table('tbl_booking')->where('bookingId', $bookingId)->where('userId', (int) $this->getUserId())->exists();
        abort_if(!$own, 404);
        $r = $vnpay->createPaymentUrl($bookingId, $request->ip());
        if (!$r['ok']) {
            toastr()->error($r['message']);

            return $this->toBooking($bookingId);
        }

        return redirect()->away($r['url']);
    }

    /** VNPay gọi máy chủ (server-to-server). Trả JSON {RspCode, Message} theo quy định VNPay. */
    public function ipn(Request $request, VnpayService $vnpay)
    {
        return response()->json($vnpay->handleIpn($request->query(), $request->ip()));
    }

    /** Khách quay về từ VNPay: chỉ hiển thị kết quả, không đổi trạng thái đơn. */
    public function return(Request $request, VnpayService $vnpay)
    {
        $r = $vnpay->handleReturn($request->query(), $request->ip());
        $b = $r['bookingId'] ? DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->where('b.bookingId', $r['bookingId'])->select('b.bookingId', 'b.bookingCode', 'b.bookingStatus', 'b.holdExpiresAt', 'b.userId', 't.title')->first() : null;
        $title = 'Kết quả thanh toán';

        return view('clients.payment-result', compact('title', 'r', 'b'));
    }

    /** Khách bấm "Tôi đã thanh toán – kiểm tra lại": hỏi VNPay bằng querydr. */
    public function check(Request $request, int $bookingId, VnpayService $vnpay)
    {
        $own = DB::table('tbl_booking')->where('bookingId', $bookingId)->where('userId', (int) $this->getUserId())->exists();
        abort_if(!$own, 404);
        $r = $vnpay->queryAndSettle($bookingId, $request->ip());
        $r['ok'] && $r['status'] === '00' ? toastr()->success($r['message']) : toastr()->info($r['message']);

        return $this->toBooking($bookingId);
    }

    private function toBooking(int $bookingId)
    {
        $checkoutId = (int) DB::table('tbl_checkout')->where('bookingId', $bookingId)->value('checkoutId');

        return $checkoutId ? redirect()->route('tour-booked', ['bookingId' => $bookingId, 'checkoutId' => $checkoutId]) : redirect()->route('my-tours');
    }
}
