<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Services\Payments\MomoGateway;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Thanh toán trực tuyến MoMo / ZaloPay / QR chuyển khoản (§7.6, N-02):
 * chữ ký sai · số tiền lệch · thông báo gửi lặp (chỉ ghi nhận một lần) · tiền về sau khi hết hạn · trang quay về không đổi trạng thái ·
 * đối soát chủ động · webhook SePay · admin xác nhận tay. Khóa giả, cổng giả lập bằng Http::fake, không gọi cổng thật.
 */
class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const MOMO_SECRET = 'momo-secret-test';
    private const ZP_KEY1 = 'zalopay-key1-test';
    private const ZP_KEY2 = 'zalopay-key2-test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://travela.test',
            'payments.momo' => ['enabled' => true, 'partner_code' => 'MOMOTEST', 'access_key' => 'ACCESSTEST', 'secret_key' => self::MOMO_SECRET,
                'endpoint' => 'https://test-payment.momo.vn', 'request_type' => 'captureWallet', 'hold_minutes' => 15],
            'payments.zalopay' => ['enabled' => true, 'app_id' => '2553', 'key1' => self::ZP_KEY1, 'key2' => self::ZP_KEY2,
                'endpoint' => 'https://sb-openapi.zalopay.vn', 'hold_minutes' => 15],
            'payments.qr' => ['enabled' => true, 'bank_id' => 'MB', 'bank_name' => 'MB Bank', 'account_no' => '0123456789', 'account_name' => 'CONG TY TRAVELA',
                'image_url' => 'https://img.vietqr.io/image', 'template' => 'compact2', 'sepay_api_key' => 'sepay-key-test', 'hold_minutes' => 30],
        ]);
        Mail::fake();
        Http::fake([
            'test-payment.momo.vn/v2/gateway/api/create' => fn($r) => Http::response(['resultCode' => 0, 'message' => 'Thành công', 'orderId' => $r['orderId'],
                'payUrl' => 'https://test-payment.momo.vn/v2/gateway/pay?t=' . $r['orderId']]),
            'sb-openapi.zalopay.vn/v2/create' => fn($r) => Http::response(['return_code' => 1, 'return_message' => 'Giao dịch thành công',
                'order_url' => 'https://qcgateway.zalopay.vn/openinapp?order=' . $r['app_trans_id']]),
        ]);
    }

    private function user(): int
    {
        static $n = 0;
        $n++;

        return DB::table('tbl_users')->insertGetId(['fullName' => "K$n", 'username' => "op$n", 'password' => 'x', 'email' => "op$n@example.com", 'isActive' => 'y']);
    }

    private function tour(int $qty = 5): int
    {
        return DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour thanh toán', 'time' => '3 ngày', 'description' => 'x', 'quantity' => $qty, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Huế', 'domain' => 't', 'availability' => 1, 'durationDays' => 3,
            'startDate' => now()->addDays(20)->toDateString(), 'endDate' => now()->addDays(22)->toDateString(),
        ]);
    }

    /** Đặt tour với phương thức $method rồi tạo giao dịch; trả [bookingId, txnRef, user, start]. */
    private function book(string $method, int $adults = 2, ?int $tour = null): array
    {
        $user = $this->user();
        $r = app(BookingService::class)->create($user, ['tourId' => $tour ?? $this->tour(), 'fullName' => 'K', 'email' => 'k@example.com', 'tel' => '0900000000',
            'address' => 'HN', 'numAdults' => $adults, 'numChildren' => 0, 'couponCode' => '', 'payment' => $method]);
        $start = app(OnlinePaymentService::class)->start($r['bookingId'], $method, '127.0.0.1');
        $this->assertTrue($start['ok'], $start['message'] ?? '');

        return [$r['bookingId'], DB::table('tbl_checkout')->where('bookingId', $r['bookingId'])->value('providerTxnRef'), $user, $start];
    }

    private function momoIpn(string $orderId, int $amount, int $resultCode = 0, ?string $secret = null): array
    {
        $d = ['partnerCode' => 'MOMOTEST', 'orderId' => $orderId, 'requestId' => $orderId . '-r', 'amount' => $amount, 'orderInfo' => 'Thanh toan',
            'orderType' => 'momo_wallet', 'transId' => (string) random_int(1000000000, 9999999999), 'resultCode' => $resultCode,
            'message' => $resultCode === 0 ? 'Thành công.' : 'Giao dịch bị từ chối bởi người dùng.', 'payType' => 'qr', 'responseTime' => (int) (microtime(true) * 1000), 'extraData' => ''];
        $raw = MomoGateway::raw(['accessKey' => 'ACCESSTEST'] + $d, ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId']);
        $d['signature'] = hash_hmac('sha256', $raw, $secret ?? self::MOMO_SECRET);

        return $d;
    }

    private function zpCallback(string $appTransId, int $amount, ?string $key2 = null): array
    {
        $data = json_encode(['app_id' => 2553, 'app_trans_id' => $appTransId, 'app_time' => 1, 'app_user' => 'travela', 'amount' => $amount, 'embed_data' => '{}',
            'item' => '[]', 'zp_trans_id' => random_int(100000000, 999999999), 'server_time' => 1, 'channel' => 38, 'merchant_user_id' => 'x', 'user_fee_amount' => 0, 'discount_amount' => 0]);

        return ['data' => $data, 'mac' => hash_hmac('sha256', $data, $key2 ?? self::ZP_KEY2), 'type' => 1];
    }

    private function sepay(string $content, int $amount, string $key = 'sepay-key-test', string $type = 'in'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Apikey ' . $key])->postJson('/thanh-toan/thong-bao/qr', [
            'id' => random_int(1, 999999), 'gateway' => 'MBBank', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'accountNumber' => '0123456789',
            'code' => null, 'content' => $content, 'transferType' => $type, 'description' => '', 'transferAmount' => $amount, 'accumulated' => 0, 'referenceCode' => 'FT1',
        ]);
    }

    private function st(int $id): array
    {
        return [DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'), DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus')];
    }

    // ---------------------------------------------------------------- tạo giao dịch

    public function test_booking_form_lists_enabled_gateways_and_redirects_to_gateway(): void
    {
        $tour = $this->tour();
        $user = $this->user();
        $this->withSession(['userId' => $user, 'username' => 'op'])->get("/booking/$tour")->assertOk()
            ->assertSee('Thanh toán bằng ví MoMo')->assertSee('Thanh toán bằng ZaloPay')->assertSee('Chuyển khoản bằng mã QR');

        config(['payments.zalopay.enabled' => false]);
        $res = $this->withSession(['userId' => $user, 'username' => 'op'])->post('/create-booking', ['tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com',
            'tel' => '0900000000', 'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'payment' => 'momo', 'couponCode' => '']);
        $id = (int) DB::table('tbl_booking')->value('bookingId');
        $res->assertRedirect(route('payment.pay', ['method' => 'momo', 'bookingId' => $id]));
        $this->withSession(['userId' => $user, 'username' => 'op'])->get(route('payment.pay', ['method' => 'momo', 'bookingId' => $id]))
            ->assertRedirectContains('test-payment.momo.vn/v2/gateway/pay');
        // cổng đang tắt thì không nhận
        $this->withSession(['userId' => $user, 'username' => 'op'])->post('/create-booking', ['tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com',
            'tel' => '0900000000', 'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'payment' => 'zalopay', 'couponCode' => ''])->assertSessionHasErrors('payment');
    }

    public function test_momo_create_is_signed_and_sends_ipn_url_and_short_hold(): void
    {
        [$id, $txn] = $this->book('momo');
        $this->assertTrue(now()->addMinutes(16)->greaterThan(DB::table('tbl_booking')->where('bookingId', $id)->value('holdExpiresAt')));
        Http::assertSent(function ($r) {
            if (!str_contains($r->url(), '/v2/gateway/api/create')) {
                return false;
            }
            $raw = MomoGateway::raw(['accessKey' => 'ACCESSTEST'] + $r->data(), ['accessKey', 'amount', 'extraData', 'ipnUrl', 'orderId', 'orderInfo', 'partnerCode', 'redirectUrl', 'requestId', 'requestType']);

            return $r['amount'] === 2000000 && $r['ipnUrl'] === 'https://travela.test/thanh-toan/thong-bao/momo'
                && $r['redirectUrl'] === 'https://travela.test/thanh-toan/ket-qua/momo' && hash_equals(hash_hmac('sha256', $raw, self::MOMO_SECRET), $r['signature']);
        });
        // lần thử thứ hai có mã riêng (MoMo không nhận orderId trùng)
        app(OnlinePaymentService::class)->start($id, 'momo', null);
        $this->assertStringEndsWith('-2', DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnRef'));
    }

    public function test_public_url_overrides_app_url_for_notify(): void
    {
        config(['payments.public_url' => 'https://abc-8000.asse.devtunnels.ms/']);
        $this->book('zalopay');
        Http::assertSent(fn($r) => str_contains($r->url(), '/v2/create') && $r['callback_url'] === 'https://abc-8000.asse.devtunnels.ms/thanh-toan/thong-bao/zalopay'
            && str_starts_with($r['app_trans_id'], now('Asia/Ho_Chi_Minh')->format('ymd') . '_'));
    }

    // ---------------------------------------------------------------- MoMo IPN

    public function test_momo_ipn_confirms_once_even_if_sent_ten_times(): void
    {
        [$id, $txn] = $this->book('momo', 2);
        $ipn = $this->momoIpn($txn, 2000000);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/thanh-toan/thong-bao/momo', $ipn)->assertNoContent();
        }
        $this->assertSame(['y', 'y'], $this->st($id));
        $a = DB::table('tbl_tour_availability')->first();
        $this->assertSame([2, 0], [(int) $a->bookedSlots, (int) $a->heldSlots]);
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'payment.online')->count());
        $this->assertSame(1, DB::table('tbl_payment_logs')->where(['kind' => 'notify', 'result' => 'paid'])->count());
        $this->assertSame(9, DB::table('tbl_payment_logs')->where(['kind' => 'notify', 'result' => 'duplicate'])->count());
        $this->assertSame($ipn['transId'], DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnNo'));
    }

    public function test_momo_ipn_rejects_bad_signature_wrong_amount_unknown_order(): void
    {
        [$id, $txn] = $this->book('momo', 2);
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($txn, 2000000, 0, 'khoa-gia'))->assertNoContent();
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($txn, 1000, 0))->assertNoContent();
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn('TOUR00000000000000000000', 2000000))->assertNoContent();
        $this->assertSame(['n', 'n'], $this->st($id));
        $this->assertEqualsCanonicalizing(['bad_signature', 'bad_amount', 'not_found'],
            DB::table('tbl_payment_logs')->where('kind', 'notify')->pluck('result')->all());
    }

    public function test_momo_failed_payment_keeps_booking_pending_with_reason(): void
    {
        [$id, $txn] = $this->book('momo', 1);
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($txn, 1000000, 1006))->assertNoContent();
        $this->assertSame(['n', 'n'], $this->st($id));
        $this->assertSame('1006', DB::table('tbl_checkout')->where('bookingId', $id)->value('responseCode'));
    }

    public function test_momo_return_does_not_change_state_but_queries_gateway(): void
    {
        [$id, $txn, $user] = $this->book('momo', 1);
        $ret = $this->momoIpn($txn, 1000000);
        // cổng chưa xác nhận khi hỏi lại: trang chỉ hiển thị "đang chờ", đơn không đổi
        Http::fake(['test-payment.momo.vn/v2/gateway/api/query' => Http::response(['orderId' => $txn, 'resultCode' => 1000, 'amount' => 1000000, 'message' => 'Đang chờ'])]);
        $this->withSession(['userId' => $user])->get('/thanh-toan/ket-qua/momo?' . http_build_query($ret))->assertOk()->assertSee('Đang chờ Ví MoMo xác nhận');
        $this->assertSame(['n', 'n'], $this->st($id));

        // dữ liệu bị sửa trên trình duyệt: báo sai chữ ký, không gọi cổng
        $this->get('/thanh-toan/ket-qua/momo?' . http_build_query(['amount' => 1] + $ret))->assertOk()->assertSee('Không xác thực được');
    }

    public function test_momo_query_settles_when_ipn_never_arrives(): void
    {
        [$id, $txn, $user] = $this->book('momo', 1);
        Http::fake(['test-payment.momo.vn/v2/gateway/api/query' => Http::sequence()
            ->push(['orderId' => $txn, 'resultCode' => 1000, 'amount' => 1000000])
            ->push(['orderId' => $txn, 'resultCode' => 0, 'amount' => 1000000, 'transId' => 4088878653])]);
        $this->withSession(['userId' => $user])->post("/thanh-toan/$id/kiem-tra")->assertRedirect();
        $this->assertSame(['n', 'n'], $this->st($id));
        // lệnh đối soát mỗi phút (đơn sắp hết hạn giữ chỗ)
        $this->travel(14)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->assertSame('4088878653', DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnNo'));
    }

    public function test_money_after_expiry_revives_when_seats_left_else_refunds(): void
    {
        $tour = $this->tour(2);
        [$a, $txnA] = $this->book('momo', 2, $tour);
        $this->travel(20)->minutes();
        app(BookingService::class)->expireHolds();
        $this->assertSame(['c', 'n'], $this->st($a));
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($txnA, 2000000))->assertNoContent();
        $this->assertSame(['y', 'y'], $this->st($a));   // còn chỗ -> xác nhận lại

        // đơn khác hết hạn, chỗ đã bán cho người khác -> hoàn 100%
        $tour2 = $this->tour(2);
        [$b, $txnB] = $this->book('zalopay', 2, $tour2);
        $this->travel(20)->minutes();
        app(BookingService::class)->expireHolds();
        $this->book('momo', 2, $tour2);
        $this->postJson('/thanh-toan/thong-bao/zalopay', $this->zpCallback($txnB, 2000000))->assertJson(['return_code' => 1]);
        $this->assertSame(['c', 'refund_pending'], $this->st($b));
        $this->assertSame(100, (int) DB::table('tbl_refunds')->where('bookingId', $b)->value('percent'));
    }

    // ---------------------------------------------------------------- ZaloPay

    public function test_zalopay_callback_mac_amount_and_duplicates(): void
    {
        [$id, $txn] = $this->book('zalopay', 2);
        $this->assertMatchesRegularExpression('/^\d{6}_TOUR\d{18}$/', $txn);
        $this->postJson('/thanh-toan/thong-bao/zalopay', $this->zpCallback($txn, 2000000, 'khoa-sai'))->assertJson(['return_code' => -1]);
        $this->postJson('/thanh-toan/thong-bao/zalopay', $this->zpCallback($txn, 5))->assertJson(['return_code' => 2]);
        $this->assertSame(['n', 'n'], $this->st($id));
        $cb = $this->zpCallback($txn, 2000000);
        $this->postJson('/thanh-toan/thong-bao/zalopay', $cb)->assertJson(['return_code' => 1, 'return_message' => 'success']);
        $this->postJson('/thanh-toan/thong-bao/zalopay', $cb)->assertJson(['return_code' => 1]);
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'payment.online')->count());
    }

    public function test_zalopay_redirect_checksum_and_query(): void
    {
        [$id, $txn, $user] = $this->book('zalopay', 1);
        $q = ['appid' => '2553', 'apptransid' => $txn, 'pmcid' => '38', 'bankcode' => '', 'amount' => '1000000', 'discountamount' => '0', 'status' => '1'];
        $q['checksum'] = hash_hmac('sha256', implode('|', $q), self::ZP_KEY2);
        Http::fake(['sb-openapi.zalopay.vn/v2/query' => Http::response(['return_code' => 1, 'return_message' => 'Thành công', 'amount' => 1000000, 'zp_trans_id' => 230001])]);
        $this->withSession(['userId' => $user])->get('/thanh-toan/ket-qua/zalopay?' . http_build_query($q))->assertOk()->assertSee('Thanh toán thành công');
        $this->assertSame(['y', 'y'], $this->st($id));
        Http::assertSent(fn($r) => str_contains($r->url(), '/v2/query') && $r['mac'] === hash_hmac('sha256', "2553|$txn|" . self::ZP_KEY1, self::ZP_KEY1));
    }

    // ---------------------------------------------------------------- QR chuyển khoản

    public function test_qr_page_shows_vietqr_and_sepay_webhook_confirms(): void
    {
        [$id, $txn, $user, $start] = $this->book('qr', 2);
        $code = DB::table('tbl_booking')->where('bookingId', $id)->value('bookingCode');
        $this->assertSame($code, $txn);
        $this->assertStringContainsString('img.vietqr.io/image/MB-0123456789-compact2.png?amount=2000000&addInfo=' . $code, $start['qr']['image']);
        $this->assertTrue(now()->addMinutes(31)->greaterThan(DB::table('tbl_booking')->where('bookingId', $id)->value('holdExpiresAt')));
        $this->withSession(['userId' => $user])->get(route('payment.pay', ['method' => 'qr', 'bookingId' => $id]))->assertOk()
            ->assertSee('Chuyển khoản bằng mã QR')->assertSee($code)->assertSee('0123456789');
        $this->withSession(['userId' => $user])->getJson("/thanh-toan/$id/trang-thai")->assertJson(['paid' => false]);

        // sai khóa, tiền ra, nội dung không có mã đơn, thiếu tiền: không xác nhận
        $this->sepay("CK $code", 2000000, 'khoa-sai')->assertStatus(401);
        $this->sepay("CK $code", 2000000, 'sepay-key-test', 'out')->assertJson(['success' => true]);
        $this->sepay('chuyen tien tour', 2000000)->assertJson(['success' => true]);
        $this->sepay("CK $code", 1500000)->assertJson(['success' => true]);
        $this->assertSame(['n', 'n'], $this->st($id));

        // ngân hàng đổi chữ thường / thêm chữ quanh mã: vẫn khớp
        $this->sepay('NGUYEN VAN A chuyen tien ' . strtolower($code) . ' FT2611', 2000000)->assertJson(['success' => true]);
        $this->sepay("CK $code", 2000000)->assertJson(['success' => true]);   // gửi lại: không ghi nhận lần nữa
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->withSession(['userId' => $user])->getJson("/thanh-toan/$id/trang-thai")->assertJson(['paid' => true]);
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'payment.online')->count());
    }

    public function test_qr_manual_confirm_by_admin_and_simulate_command(): void
    {
        [$id] = $this->book('qr', 1);
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ql', 'passWord' => 'x', 'email' => 'ql@example.com', 'fullName' => 'QL', 'address' => 'HN', 'role' => 'manager']);
        $this->withSession(['adminId' => $admin])->get('/admin/payments')->assertOk()->assertSee('Chuyển khoản QR đang chờ')->assertSee('Thanh toán trực tuyến');
        $this->withSession(['adminId' => $admin])->post("/admin/payments/$id/confirm", [])->assertSessionHasErrors('reference');
        $this->withSession(['adminId' => $admin])->post("/admin/payments/$id/confirm", ['reference' => 'FT26110312345'])->assertRedirect();
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->assertSame('FT26110312345', DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnNo'));

        [$id2] = $this->book('qr', 1);
        $code = DB::table('tbl_booking')->where('bookingId', $id2)->value('bookingCode');
        $this->artisan('payments:simulate-transfer', ['bookingCode' => $code])->assertSuccessful();
        $this->assertSame(['y', 'y'], $this->st($id2));
    }

    public function test_cannot_pay_others_booking_or_paid_booking(): void
    {
        [$id, $txn, $user] = $this->book('momo', 1);
        $this->withSession(['userId' => $this->user()])->get(route('payment.pay', ['method' => 'momo', 'bookingId' => $id]))->assertNotFound();
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($txn, 1000000))->assertNoContent();
        $this->assertFalse(app(OnlinePaymentService::class)->start($id, 'zalopay', null)['ok']);
    }
}
