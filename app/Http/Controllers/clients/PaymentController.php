<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\QrTransferGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thanh toán trực tuyến phía khách (§7.1): MoMo, ZaloPay, QR chuyển khoản.
 *   pay     tạo giao dịch → chuyển sang cổng (MoMo / ZaloPay) hoặc hiện mã QR.
 *   notify  cổng gọi máy chủ (IPN / callback / webhook): nguồn xác nhận chính. Không phiên, không CSRF.
 *   return  khách quay về: chỉ hiển thị (đơn còn chờ thì hỏi lại cổng).
 *   check   khách bấm "Tôi đã thanh toán – kiểm tra lại".
 *   status  trang QR hỏi định kỳ xem tiền đã về chưa.
 */
class PaymentController extends Controller
{
    private function own(int $bookingId): object
    {
        $b = DB::table('tbl_booking as b')->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->where('b.bookingId', $bookingId)->where('b.userId', (int) $this->getUserId())
            ->select('b.bookingId', 'b.bookingCode', 'b.bookingStatus', 'b.holdExpiresAt', 'c.checkoutId', 'c.amount', 'c.paymentStatus', 'c.paymentMethod', 't.title')->first();
        abort_if(!$b, 404);

        return $b;
    }

    public function pay(Request $request, string $method, int $bookingId, OnlinePaymentService $payments)
    {
        $b = $this->own($bookingId);
        $r = $payments->start($bookingId, $method, $request->ip());
        if (!$r['ok']) {
            toastr()->error($r['message']);

            return $this->toBooking($bookingId);
        }
        if (!empty($r['url'])) {
            return redirect()->away($r['url']);
        }

        return view('clients.payment-qr', [
            'title' => 'Thanh toán bằng mã QR',
            'b' => $b,
            'qr' => $r['qr'],
            'holdAt' => $b->holdExpiresAt ? \Carbon\Carbon::parse($b->holdExpiresAt) : null,
        ]);
    }

    public function notify(Request $request, string $method, OnlinePaymentService $payments)
    {
        return $payments->handleNotify($method, $request);
    }

    public function return(Request $request, string $method, OnlinePaymentService $payments)
    {
        $r = $payments->handleReturn($method, $request->query(), $request->ip());
        $b = $r['bookingId'] ? DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->where('b.bookingId', $r['bookingId'])->select('b.bookingId', 'b.bookingCode', 'b.bookingStatus', 'b.holdExpiresAt', 'b.userId', 't.title')->first() : null;
        $title = 'Kết quả thanh toán';

        return view('clients.payment-result', compact('title', 'r', 'b'));
    }

    public function check(Request $request, int $bookingId, OnlinePaymentService $payments)
    {
        $this->own($bookingId);
        $r = $payments->queryAndSettle($bookingId, $request->ip());
        $r['ok'] && $r['status'] === 'paid' ? toastr()->success($r['message']) : toastr()->info($r['message']);

        return $this->toBooking($bookingId);
    }

    /** GET JSON cho trang QR: {paid, status, expired} */
    public function status(int $bookingId)
    {
        $b = $this->own($bookingId);
        $paid = in_array($b->paymentStatus, OnlinePaymentService::PAID, true);

        return response()->json([
            'paid' => $paid,
            'bookingStatus' => $b->bookingStatus,
            'expired' => !$paid && $b->bookingStatus === 'c',
            'redirect' => route('tour-booked', ['bookingId' => $b->bookingId, 'checkoutId' => $b->checkoutId]),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function toBooking(int $bookingId)
    {
        $checkoutId = (int) DB::table('tbl_checkout')->where('bookingId', $bookingId)->value('checkoutId');

        return $checkoutId ? redirect()->route('tour-booked', ['bookingId' => $bookingId, 'checkoutId' => $checkoutId]) : redirect()->route('my-tours');
    }
}
