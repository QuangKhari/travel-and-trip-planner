<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Services\VnpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * VNPay (§7.6): chữ ký sai · số tiền lệch · IPN gửi lặp 10 lần (N-02) · IPN sau khi hết hạn · đơn không tồn tại ·
 * Return không đổi trạng thái · querydr đối soát. Khóa giả, không gọi VNPay thật.
 */
class VnpayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vnpay.enabled' => true, 'vnpay.tmn_code' => 'TESTCODE', 'vnpay.hash_secret' => 'TESTSECRETTESTSECRETTESTSECRET12', 'vnpay.hold_minutes' => 15]);
        Mail::fake();
    }

    private function user(): int
    {
        static $n = 0;
        $n++;

        return DB::table('tbl_users')->insertGetId(['fullName' => "K$n", 'username' => "vn$n", 'password' => 'x', 'email' => "vn$n@example.com", 'isActive' => 'y']);
    }

    private function tour(int $qty = 5): int
    {
        return DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour VNPay', 'time' => '3 ngày', 'description' => 'x', 'quantity' => $qty, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Huế', 'domain' => 't', 'availability' => 1, 'durationDays' => 3,
            'startDate' => now()->addDays(20)->toDateString(), 'endDate' => now()->addDays(22)->toDateString(),
        ]);
    }

    /** Đặt tour VNPay rồi tạo link thanh toán; trả [bookingId, txnRef, user]. */
    private function vnpayBooking(int $adults = 2, ?int $tour = null): array
    {
        $user = $this->user();
        $r = app(BookingService::class)->create($user, ['tourId' => $tour ?? $this->tour(), 'fullName' => 'K', 'email' => 'k@example.com', 'tel' => '0900000000',
            'address' => 'HN', 'numAdults' => $adults, 'numChildren' => 0, 'couponCode' => '', 'payment' => 'vnpay']);
        $link = app(VnpayService::class)->createPaymentUrl($r['bookingId'], '127.0.0.1');
        $this->assertTrue($link['ok']);

        return [$r['bookingId'], DB::table('tbl_checkout')->where('bookingId', $r['bookingId'])->value('providerTxnRef'), $user];
    }

    private function ipnParams(string $txnRef, int $amountVnd, string $code = '00'): array
    {
        $p = [
            'vnp_Amount' => $amountVnd * 100, 'vnp_BankCode' => 'NCB', 'vnp_CardType' => 'ATM', 'vnp_OrderInfo' => 'Thanh toan',
            'vnp_PayDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'), 'vnp_ResponseCode' => $code, 'vnp_TmnCode' => 'TESTCODE',
            'vnp_TransactionNo' => '14' . random_int(100000, 999999), 'vnp_TransactionStatus' => $code, 'vnp_TxnRef' => $txnRef,
        ];
        $p['vnp_SecureHash'] = VnpayService::sign($p);

        return $p;
    }

    public function test_payment_url_is_signed_and_uses_short_hold(): void
    {
        [$id, $txn] = $this->vnpayBooking();
        $hold = DB::table('tbl_booking')->where('bookingId', $id)->value('holdExpiresAt');
        $this->assertTrue(now()->addMinutes(16)->greaterThan($hold));
        $url = app(VnpayService::class)->createPaymentUrl($id, '127.0.0.1')['url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(200000000, (int) $q['vnp_Amount']);
        $this->assertTrue(VnpayService::verify($q));
        $this->assertStringEndsWith('-2', $q['vnp_TxnRef']);            // lần thử thứ hai có mã riêng
        $this->assertDatabaseHas('tbl_payment_logs', ['bookingId' => $id, 'kind' => 'create']);
    }

    public function test_ipn_success_confirms_once_even_if_sent_ten_times(): void
    {
        [$id, $txn] = $this->vnpayBooking(2);
        $params = $this->ipnParams($txn, 2000000);
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = $this->getJson('/thanh-toan/vnpay/ipn?' . http_build_query($params))->json('RspCode');
        }
        $this->assertSame('00', $codes[0]);
        $this->assertSame(array_fill(0, 9, '02'), array_slice($codes, 1));
        $this->assertSame('y', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));
        $this->assertSame('y', DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus'));
        $a = DB::table('tbl_tour_availability')->first();
        $this->assertSame(2, (int) $a->bookedSlots);
        $this->assertSame(0, (int) $a->heldSlots);
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'payment.vnpay')->count());
        $this->assertSame(10, DB::table('tbl_payment_logs')->where('kind', 'ipn')->count());
    }

    public function test_ipn_rejects_bad_signature_wrong_amount_and_unknown_order(): void
    {
        [$id, $txn] = $this->vnpayBooking(2);
        $bad = $this->ipnParams($txn, 2000000);
        $bad['vnp_Amount'] = 100;                                       // sửa sau khi ký -> sai chữ ký
        $this->getJson('/thanh-toan/vnpay/ipn?' . http_build_query($bad))->assertJson(['RspCode' => '97']);
        $this->getJson('/thanh-toan/vnpay/ipn?' . http_build_query($this->ipnParams($txn, 1999000)))->assertJson(['RspCode' => '04']);
        $this->getJson('/thanh-toan/vnpay/ipn?' . http_build_query($this->ipnParams('KHONGCO123', 2000000)))->assertJson(['RspCode' => '01']);
        $this->assertSame('n', DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus'));
    }

    public function test_failed_payment_keeps_booking_pending(): void
    {
        [$id, $txn] = $this->vnpayBooking();
        $this->getJson('/thanh-toan/vnpay/ipn?' . http_build_query($this->ipnParams($txn, 2000000, '24')))->assertJson(['RspCode' => '00']);
        $this->assertSame('n', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));
        $this->assertSame('24', DB::table('tbl_checkout')->where('bookingId', $id)->value('responseCode'));
    }

    public function test_ipn_after_expiry_revives_when_seats_left_else_refunds(): void
    {
        $tour = $this->tour(3);
        [$id, $txn] = $this->vnpayBooking(2, $tour);
        DB::table('tbl_booking')->where('bookingId', $id)->update(['holdExpiresAt' => now()->subMinute()]);
        app(BookingService::class)->expireHolds();
        $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));

        $this->getJson('/thanh-toan/vnpay/ipn?' . http_build_query($this->ipnParams($txn, 2000000)))->assertJson(['RspCode' => '00']);
        $this->assertSame('y', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));   // còn chỗ: xác nhận lại

        // Đơn thứ hai hết hạn, chỗ bị người khác lấy hết, tiền về muộn -> hoàn 100%
        [$id2, $txn2] = $this->vnpayBooking(1, $tour);
        DB::table('tbl_booking')->where('bookingId', $id2)->update(['holdExpiresAt' => now()->subMinute()]);
        app(BookingService::class)->expireHolds();
        app(BookingService::class)->create($this->user(), ['tourId' => $tour, 'fullName' => 'X', 'email' => 'x@example.com', 'tel' => '0900000000', 'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'couponCode' => '']);
        $this->getJson('/thanh-toan/vnpay/ipn?' . http_build_query($this->ipnParams($txn2, 1000000)))->assertJson(['RspCode' => '00']);
        $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $id2)->value('bookingStatus'));
        $this->assertSame('refund_pending', DB::table('tbl_checkout')->where('bookingId', $id2)->value('paymentStatus'));
        $this->assertSame(100, (int) DB::table('tbl_refunds')->where('bookingId', $id2)->value('percent'));
    }

    public function test_return_never_changes_state_and_querydr_settles(): void
    {
        [$id, $txn, $user] = $this->vnpayBooking(2);
        $first = ['vnp_ResponseCode' => '91', 'vnp_Message' => 'not found', 'vnp_SecureHash' => 'x'];

        // querydr trả "đã thanh toán" có chữ ký hợp lệ -> ghi nhận
        $res = ['vnp_ResponseId' => 'r1', 'vnp_Command' => 'querydr', 'vnp_ResponseCode' => '00', 'vnp_Message' => 'OK', 'vnp_TmnCode' => 'TESTCODE',
            'vnp_TxnRef' => $txn, 'vnp_Amount' => '200000000', 'vnp_BankCode' => 'NCB', 'vnp_PayDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'),
            'vnp_TransactionNo' => '1499', 'vnp_TransactionType' => '01', 'vnp_TransactionStatus' => '00', 'vnp_OrderInfo' => 'x', 'vnp_PromotionCode' => '', 'vnp_PromotionAmount' => ''];
        $res['vnp_SecureHash'] = hash_hmac('sha512', implode('|', array_values($res)), config('vnpay.hash_secret'));
        Http::fake(['*' => Http::sequence()->push($first)->push($res)]);
        $this->withSession(['userId' => $user, 'username' => 'u'])
            ->get('/thanh-toan/vnpay/ket-qua?' . http_build_query($this->ipnParams($txn, 2000000)))->assertOk()->assertSee('Đang chờ VNPay xác nhận');
        $this->assertSame('n', DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus'));
        $r = app(VnpayService::class)->queryAndSettle($id);
        $this->assertTrue($r['ok']);
        $this->assertSame('y', DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus'));
        $this->assertSame('1499', DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnNo'));
    }

    public function test_booking_form_redirects_to_vnpay(): void
    {
        $user = $this->user();
        $tour = $this->tour();
        $res = $this->withSession(['userId' => $user, 'username' => 'u'])->post('/create-booking', [
            'tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com', 'tel' => '0900000000', 'address' => 'HN',
            'numAdults' => 1, 'numChildren' => 0, 'payment' => 'vnpay',
        ]);
        $id = (int) DB::table('tbl_booking')->where('userId', $user)->value('bookingId');
        $res->assertRedirect(route('payment.vnpay.pay', ['bookingId' => $id]));
        $this->withSession(['userId' => $user, 'username' => 'u'])->get("/thanh-toan/vnpay/$id")
            ->assertRedirect()->assertRedirectContains('sandbox.vnpayment.vn');
        // người khác không tạo được link cho đơn này
        $this->withSession(['userId' => $this->user(), 'username' => 'o'])->get("/thanh-toan/vnpay/$id")->assertNotFound();
    }
}
