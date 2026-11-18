<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Services\Payments\MomoGateway;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\VnpayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Thanh toán trực tuyến VNPay / MoMo / QR chuyển khoản (§7.6, N-02):
 * chữ ký sai · số tiền lệch · thông báo gửi lặp (chỉ ghi nhận một lần) · tiền về sau khi hết hạn · trang quay về không đổi trạng thái ·
 * đối soát chủ động · webhook SePay · admin xác nhận tay. Khóa giả, cổng giả lập bằng Http::fake, không gọi cổng thật.
 */
class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const MOMO_SECRET = 'momo-secret-test';
    private const VNP_SECRET = 'VNPAYSECRETTESTKEY0123456789ABCD';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://travela.test',
            // máy dev đặt PAYMENT_PUBLIC_URL (Dev Tunnels) trong .env: test không được phụ thuộc vào đó
            'payments.public_url' => null,
            'payments.momo' => ['enabled' => true, 'partner_code' => 'MOMOTEST', 'access_key' => 'ACCESSTEST', 'secret_key' => self::MOMO_SECRET,
                'endpoint' => 'https://test-payment.momo.vn', 'request_type' => 'captureWallet', 'hold_minutes' => 15],
            'payments.vnpay' => ['enabled' => true, 'tmn_code' => 'TRAVELA1', 'hash_secret' => self::VNP_SECRET, 'bank_code' => '',
                'pay_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html', 'api_url' => 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction', 'hold_minutes' => 15],
            'payments.qr' => ['enabled' => true, 'bank_id' => 'MB', 'bank_name' => 'MB Bank', 'account_no' => '0123456789', 'account_name' => 'CONG TY TRAVELA',
                'image_url' => 'https://img.vietqr.io/image', 'template' => 'compact2', 'sepay_api_key' => 'sepay-key-test', 'hold_minutes' => 30],
        ]);
        Mail::fake();
        Http::fake([
            'test-payment.momo.vn/v2/gateway/api/create' => fn($r) => Http::response(['resultCode' => 0, 'message' => 'Thành công', 'orderId' => $r['orderId'],
                'payUrl' => 'https://test-payment.momo.vn/v2/gateway/pay?t=' . $r['orderId']]),
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

    /** Tham số IPN / return của VNPay, ký HMAC-SHA512 như VNPay. */
    private function vnp(string $txnRef, int $amount, string $code = '00', ?string $secret = null, string $status = '00'): array
    {
        $q = ['vnp_Amount' => $amount * 100, 'vnp_BankCode' => 'NCB', 'vnp_BankTranNo' => 'VNP' . random_int(1000, 9999), 'vnp_CardType' => 'ATM',
            'vnp_OrderInfo' => 'Thanh toan don dat tour', 'vnp_PayDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'), 'vnp_ResponseCode' => $code,
            'vnp_TmnCode' => 'TRAVELA1', 'vnp_TransactionNo' => (string) random_int(14000000, 14999999), 'vnp_TransactionStatus' => $status, 'vnp_TxnRef' => $txnRef];
        $q['vnp_SecureHash'] = hash_hmac('sha512', VnpayGateway::hashData($q), $secret ?? self::VNP_SECRET);

        return $q;
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
            ->assertSee('Thanh toán bằng ví MoMo')->assertSee('Thanh toán qua VNPay')->assertSee('Chuyển khoản bằng mã QR');

        config(['payments.vnpay.enabled' => false]);
        $res = $this->withSession(['userId' => $user, 'username' => 'op'])->post('/create-booking', ['tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com',
            'tel' => '0900000000', 'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'payment' => 'momo', 'couponCode' => '']);
        $id = (int) DB::table('tbl_booking')->value('bookingId');
        $res->assertRedirect(route('payment.pay', ['method' => 'momo', 'bookingId' => $id]));
        $this->withSession(['userId' => $user, 'username' => 'op'])->get(route('payment.pay', ['method' => 'momo', 'bookingId' => $id]))
            ->assertRedirectContains('test-payment.momo.vn/v2/gateway/pay');
        // cổng đang tắt thì không nhận
        $this->withSession(['userId' => $user, 'username' => 'op'])->post('/create-booking', ['tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com',
            'tel' => '0900000000', 'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'payment' => 'vnpay', 'couponCode' => ''])->assertSessionHasErrors('payment');
        // ZaloPay đã bỏ
        $this->withSession(['userId' => $user, 'username' => 'op'])->post('/create-booking', ['tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com',
            'tel' => '0900000000', 'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'payment' => 'zalopay', 'couponCode' => ''])->assertSessionHasErrors('payment');
        $this->get('/thanh-toan/ket-qua/zalopay')->assertNotFound();
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

    public function test_public_url_overrides_app_url_for_return(): void
    {
        config(['payments.public_url' => 'https://abc-8000.asse.devtunnels.ms/']);
        [, , , $start] = $this->book('vnpay');
        parse_str((string) parse_url($start['url'], PHP_URL_QUERY), $q);
        $this->assertSame('https://abc-8000.asse.devtunnels.ms/thanh-toan/ket-qua/vnpay', $q['vnp_ReturnUrl']);
    }

    public function test_vnpay_url_is_signed_with_hmac_sha512(): void
    {
        [$id, $txn, , $start] = $this->book('vnpay', 2);
        $this->assertStringStartsWith('https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?', $start['url']);
        parse_str((string) parse_url($start['url'], PHP_URL_QUERY), $q);
        $hash = $q['vnp_SecureHash'];
        unset($q['vnp_SecureHash']);
        $this->assertSame(hash_hmac('sha512', VnpayGateway::hashData($q), self::VNP_SECRET), $hash);
        $this->assertSame('200000000', (string) $q['vnp_Amount']);   // × 100
        $this->assertSame($txn, $q['vnp_TxnRef']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 ]+$/', $q['vnp_OrderInfo']);
        $this->assertSame('2.1.0', $q['vnp_Version']);
        $this->assertTrue($q['vnp_ExpireDate'] > $q['vnp_CreateDate']);
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
        $this->assertEqualsCanonicalizing(
            ['bad_signature', 'bad_amount', 'not_found'],
            DB::table('tbl_payment_logs')->where('kind', 'notify')->pluck('result')->all(),
        );
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
        [$b, $txnB] = $this->book('vnpay', 2, $tour2);
        $this->travel(20)->minutes();
        app(BookingService::class)->expireHolds();
        $this->book('momo', 2, $tour2);
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp($txnB, 2000000)))->assertJson(['RspCode' => '00']);
        $this->assertSame(['c', 'refund_pending'], $this->st($b));
        $this->assertSame(100, (int) DB::table('tbl_refunds')->where('bookingId', $b)->value('percent'));
    }

    // ---------------------------------------------------------------- VNPay

    public function test_vnpay_ipn_signature_amount_duplicates_and_failures(): void
    {
        [$id, $txn] = $this->book('vnpay', 2);
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp($txn, 2000000, '00', 'khoa-sai')))->assertJson(['RspCode' => '97']);
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp($txn, 5)))->assertJson(['RspCode' => '04']);
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp('KHONGCO123', 2000000)))->assertJson(['RspCode' => '01']);
        // khách hủy (24): đơn vẫn chờ, VNPay nhận 00
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp($txn, 2000000, '24', null, '02')))->assertJson(['RspCode' => '00']);
        $this->assertSame(['n', 'n'], $this->st($id));
        $this->assertSame('24', DB::table('tbl_checkout')->where('bookingId', $id)->value('responseCode'));

        $ok = $this->vnp($txn, 2000000);
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($ok))->assertJson(['RspCode' => '00', 'Message' => 'Confirm Success']);
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($ok))->assertJson(['RspCode' => '02']);   // gửi lại: đã xác nhận
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->assertSame($ok['vnp_TransactionNo'], DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnNo'));
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'payment.online')->count());
    }

    public function test_vnpay_return_checks_hash_and_queries_when_ipn_missing(): void
    {
        [$id, $txn, $user] = $this->book('vnpay', 1);
        Http::fake(['sandbox.vnpayment.vn/merchant_webapi/*' => Http::response(['vnp_ResponseId' => 'x', 'vnp_Command' => 'querydr', 'vnp_ResponseCode' => '00',
            'vnp_Message' => 'OK', 'vnp_TmnCode' => 'TRAVELA1', 'vnp_TxnRef' => $txn, 'vnp_Amount' => 100000000, 'vnp_TransactionNo' => '14112233', 'vnp_TransactionType' => '01', 'vnp_TransactionStatus' => '00'])]);
        $this->withSession(['userId' => $user])->get('/thanh-toan/ket-qua/vnpay?' . http_build_query(['vnp_Amount' => 1] + $this->vnp($txn, 1000000)))->assertOk()->assertSee('Không xác thực được');
        $this->assertSame(['n', 'n'], $this->st($id));
        $this->withSession(['userId' => $user])->get('/thanh-toan/ket-qua/vnpay?' . http_build_query($this->vnp($txn, 1000000)))->assertOk()->assertSee('Thanh toán thành công');
        $this->assertSame(['y', 'y'], $this->st($id));
        $create = DB::table('tbl_checkout')->where('bookingId', $id)->value('providerCreateDate');
        Http::assertSent(function ($r) use ($txn, $create) {
            if (($r['vnp_Command'] ?? null) !== 'querydr') {
                return false;
            }
            $data = implode('|', [$r['vnp_RequestId'], '2.1.0', 'querydr', 'TRAVELA1', $txn, $create, $r['vnp_CreateDate'], $r['vnp_IpAddr'], $r['vnp_OrderInfo']]);

            return $r['vnp_TransactionDate'] === $create && hash_equals(hash_hmac('sha512', $data, self::VNP_SECRET), $r['vnp_SecureHash']);
        });
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
        $this->assertFalse(app(OnlinePaymentService::class)->start($id, 'vnpay', null)['ok']);
    }

    // ---------------------------------------------------------------- nhiều lần thử thanh toán của cùng một đơn

    /** Bấm thanh toán 3 lần: mã CODE, CODE-2, CODE-3. Trả [bookingId, [mã lần 1, 2, 3]]. */
    private function threeAttempts(string $method): array
    {
        [$id, $first] = $this->book($method, 2);
        $refs = [$first];
        for ($i = 0; $i < 2; $i++) {
            $this->assertTrue(app(OnlinePaymentService::class)->start($id, $method, '127.0.0.1')['ok']);
            $refs[] = DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnRef');
        }
        $this->assertSame([$first, $first . '-2', $first . '-3'], $refs);

        return [$id, $refs];
    }

    public function test_ipn_of_an_older_attempt_still_finds_the_booking(): void
    {
        [$id, [, $second, $third]] = $this->threeAttempts('momo');
        // khách trả ở tab của lần thử thứ 2 sau khi đã tạo lần thử thứ 3: trước đây webhook trả not_found, tiền thu mà đơn không ghi nhận
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($second, 2000000))->assertNoContent();
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->assertSame($second, DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnRef'));
        $this->assertSame(0, DB::table('tbl_payment_logs')->where('kind', 'notify')->where('result', 'not_found')->count());
    }

    public function test_vnpay_old_attempt_ipn_and_return_page_find_the_booking(): void
    {
        [$id, [, $second]] = $this->threeAttempts('vnpay');
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp($second, 2000000)))->assertJson(['RspCode' => '00']);
        $this->assertSame(['y', 'y'], $this->st($id));
        $r = app(OnlinePaymentService::class)->handleReturn('vnpay', $this->vnp($second, 2000000), '127.0.0.1');
        $this->assertTrue($r['paid']);
        $this->assertSame($id, $r['bookingId']);
    }

    public function test_paying_two_attempts_is_flagged_as_double_charge_not_settled_twice(): void
    {
        [$id, [, $second, $third]] = $this->threeAttempts('momo');
        // webhook đảo thứ tự: lần 3 về trước, rồi lần 2 (khách trả ở cả hai tab)
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($third, 2000000))->assertNoContent();
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($second, 2000000))->assertNoContent();
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'payment.online')->count());   // chỉ ghi nhận MỘT lần
        $this->assertSame(1, DB::table('tbl_payment_logs')->where(['kind' => 'notify', 'result' => 'double_paid'])->count());
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'payment.double')->count());
        $types = array_column(app(\App\Services\Payments\PaymentReconciliationService::class)->scan(), 'type');
        $this->assertContains('payment_excess_unrefunded', $types);
        // lần thử thứ 2 là khoản thu trùng, tự có yêu cầu hoàn chờ duyệt
        $ex = DB::table('tbl_payment_attempts')->where(['provider' => 'momo', 'txnRef' => $second])->first();
        $this->assertSame('excess', $ex->status);
        $this->assertSame('pending', $ex->refundStatus);
        $this->assertSame(2000000, (int) $ex->amount);

        // gửi lặp webhook của chính lần đã ghi nhận: chỉ là trùng, không phải thu hai lần
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($third, 2000000))->assertNoContent();
        $this->assertSame(1, DB::table('tbl_payment_logs')->where(['kind' => 'notify', 'result' => 'double_paid'])->count());
    }

    public function test_query_asks_every_attempt_not_only_the_latest(): void
    {
        [$id, [$first, $second, $third]] = $this->threeAttempts('momo');
        $asked = [];
        Http::fake(['test-payment.momo.vn/v2/gateway/api/query' => function ($r) use (&$asked, $second) {
            $asked[] = $r['orderId'];

            return $r['orderId'] === $second
                ? Http::response(['orderId' => $second, 'resultCode' => 0, 'amount' => 2000000, 'transId' => 4088878999])
                : Http::response(['orderId' => $r['orderId'], 'resultCode' => 1000, 'amount' => 2000000]);
        }]);
        $r = app(OnlinePaymentService::class)->queryAndSettle($id);
        $this->assertSame('paid', $r['status']);
        $this->assertSame([$third, $second], $asked);   // mới nhất trước, dừng khi tìm thấy lần đã trả
        $this->assertSame(['y', 'y'], $this->st($id));
        $this->assertSame('4088878999', DB::table('tbl_checkout')->where('bookingId', $id)->value('providerTxnNo'));
    }

    // ---------------------------------------------------------------- bảng lần thử, khoản thu trùng, sổ cái hai bút toán

    private function refundsOn(): void
    {
        config(['payments.refund' => ['enabled' => true, 'auto' => false, 'auto_delay_minutes' => 0, 'max_attempts' => 3]]);
    }

    public function test_every_attempt_is_recorded_before_calling_the_gateway(): void
    {
        $calls = 0;
        Http::fake(['test-payment.momo.vn/v2/gateway/api/create' => function ($r) use (&$calls) {
            $calls++;
            // lúc cổng được gọi, lần thử đã nằm trong CSDL
            \PHPUnit\Framework\Assert::assertSame(1, DB::table('tbl_payment_attempts')->where(['provider' => 'momo', 'txnRef' => $r['orderId'], 'status' => 'created'])->count());

            return Http::response(['resultCode' => 0, 'orderId' => $r['orderId'], 'payUrl' => 'https://test-payment.momo.vn/pay']);
        }]);
        [$id, $ref] = $this->book('momo');
        $this->assertSame(1, $calls);
        $a = DB::table('tbl_payment_attempts')->where('bookingId', $id)->first();
        $this->assertSame($ref, $a->txnRef);
        $this->assertSame(2000000, (int) $a->amount);
        $this->assertSame(DB::table('tbl_checkout')->where('bookingId', $id)->value('providerCreateDate'), $a->createDate);
    }

    public function test_settling_an_older_vnpay_attempt_keeps_its_own_create_date_for_refund(): void
    {
        $this->refundsOn();
        [$id, [, $second]] = $this->threeAttempts('vnpay');
        DB::table('tbl_payment_attempts')->where('txnRef', $second)->update(['createDate' => '20261101080000']);   // ngày tạo của lần thử 2
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp($second, 2000000)))->assertJson(['RspCode' => '00']);
        $this->assertSame('20261101080000', DB::table('tbl_checkout')->where('bookingId', $id)->value('providerCreateDate'));

        // hủy đơn → hoàn qua VNPay phải gửi đúng mã + ngày tạo của LẦN THỬ ĐÃ TRẢ (trước đây gửi ngày của lần thử mới nhất → VNPay từ chối)
        DB::transaction(fn() => app(\App\Services\RefundService::class)->requestLocked(DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first(), 'admin', 1, 100));
        $sent = null;
        Http::fake(['sandbox.vnpayment.vn/merchant_webapi/*' => function ($r) use (&$sent) {
            $sent = $r->data();

            return Http::response(['vnp_ResponseCode' => '00', 'vnp_Message' => 'OK', 'vnp_TransactionStatus' => '00', 'vnp_TransactionNo' => '15000001']);
        }]);
        $rid = (int) DB::table('tbl_refunds')->where('bookingId', $id)->value('refundId');
        $res = app(\App\Services\Payments\GatewayRefundService::class)->process($rid, 1);
        $this->assertSame('refunded', $res['status'], $res['message']);
        $this->assertSame($second, $sent['vnp_TxnRef']);
        $this->assertSame('20261101080000', $sent['vnp_TransactionDate']);
        $this->assertSame('02', $sent['vnp_TransactionType']);
    }

    public function test_admin_approves_excess_refund_via_momo_and_ledger_balances(): void
    {
        $this->refundsOn();
        [$id, [, $second, $third]] = $this->threeAttempts('momo');
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($third, 2000000))->assertNoContent();
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($second, 2000000))->assertNoContent();
        $ex = DB::table('tbl_payment_attempts')->where('txnRef', $second)->first();

        // trang Hoàn tiền hiện khoản thu trùng, có nút duyệt
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'kt', 'passWord' => 'x', 'email' => 'kt@example.com', 'fullName' => 'Kế toán', 'address' => 'HN', 'role' => 'admin']);
        $this->withSession(['adminId' => $admin])->get('/admin/refunds')->assertOk()->assertSee('Khoản thu trùng cần hoàn')->assertSee('Duyệt hoàn qua');

        Http::fake(['test-payment.momo.vn/v2/gateway/api/refund' => fn($r) => Http::response(['resultCode' => 0, 'message' => 'OK', 'transId' => 3100000001])]);
        $this->withSession(['adminId' => $admin])->post('/admin/refunds/excess/' . $ex->attemptId . '/gateway')->assertRedirect();
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/v2/gateway/api/refund') && (string) $r['transId'] === (string) $ex->providerTxnNo && (int) $r['amount'] === 2000000);
        $ex = DB::table('tbl_payment_attempts')->where('attemptId', $ex->attemptId)->first();
        $this->assertSame('refunded', $ex->refundStatus);
        $this->assertSame('3100000001', $ex->refundNo);
        $this->assertSame(['y', 'y'], $this->st($id));   // đơn vẫn đã thanh toán (khoản chính không bị hoàn)

        // sổ cái: thu 2 triệu, thu trùng 2 triệu, hoàn trùng 2 triệu → khách còn 2 triệu ở Travela, khoản trùng = 0, mọi bút toán cân
        $ledger = app(\App\Services\Payments\DoubleEntryLedger::class);
        $this->assertSame(-2000000, $ledger->balance(\App\Services\Payments\DoubleEntryLedger::DEPOSIT, $id));
        $this->assertSame(0, $ledger->balance(\App\Services\Payments\DoubleEntryLedger::EXCESS, $id));
        $this->assertSame(2000000, $ledger->balance('cash:momo', $id));
        $this->assertSame([], $ledger->unbalanced());
        $types = array_column(app(\App\Services\Payments\PaymentReconciliationService::class)->scan(), 'type');
        $this->assertNotContains('payment_excess_unrefunded', $types);
        $this->assertNotContains('ledger_deposit_mismatch', $types);
    }

    public function test_qr_transferred_twice_is_excess_and_refunded_manually(): void
    {
        [$id, $ref] = $this->book('qr');
        $this->sepay('CK ' . $ref, 2000000)->assertOk();
        $this->sepay('CK ' . $ref . ' lan 2', 2000000)->assertOk();          // khách chuyển thêm lần nữa (mã SePay khác)
        $ex = DB::table('tbl_payment_attempts')->where('bookingId', $id)->where('status', 'excess')->first();
        $this->assertNotNull($ex, 'chuyển khoản lần hai phải là khoản thu trùng, không phải "gửi lặp"');
        $this->assertSame('pending', $ex->refundStatus);
        $chk = app(\App\Services\Payments\GatewayRefundService::class)->checkExcess($ex);
        $this->assertFalse($chk['ok']);                                        // QR không có API hoàn → hoàn tay
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'kt2', 'passWord' => 'x', 'email' => 'kt2@example.com', 'fullName' => 'Kế toán', 'address' => 'HN', 'role' => 'admin']);
        $this->withSession(['adminId' => $admin])->post('/admin/refunds/excess/' . $ex->attemptId . '/complete', ['method' => 'Chuyển khoản', 'reference' => 'FT99'])->assertRedirect();
        $this->assertSame('refunded', DB::table('tbl_payment_attempts')->where('attemptId', $ex->attemptId)->value('refundStatus'));
        $this->assertSame(0, app(\App\Services\Payments\DoubleEntryLedger::class)->balance(\App\Services\Payments\DoubleEntryLedger::EXCESS, $id));
    }

    public function test_sepay_transfer_into_another_account_is_ignored(): void
    {
        [$id, $ref] = $this->book('qr');
        $this->withHeaders(['Authorization' => 'Apikey sepay-key-test'])->postJson('/thanh-toan/thong-bao/qr', ['id' => 5, 'gateway' => 'MBBank',
            'accountNumber' => '9999999999', 'content' => 'CK ' . $ref, 'transferType' => 'in', 'transferAmount' => 2000000])->assertOk();
        $this->assertSame('n', DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus'));
    }

    public function test_gateway_refund_is_capped_at_the_gateway_transaction_amount(): void
    {
        $this->refundsOn();
        [$id, $ref] = $this->book('momo');
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($ref, 2000000))->assertNoContent();
        // thu thêm 500.000 đ tiền mặt khi tăng khách: checkout.amount = 2.500.000 nhưng giao dịch MoMo chỉ có 2.000.000
        DB::table('tbl_checkout')->where('bookingId', $id)->increment('amount', 500000);
        DB::table('tbl_booking_adjustments')->insert(['bookingId' => $id, 'kind' => 'increase', 'oldAdults' => 2, 'oldChildren' => 0, 'newAdults' => 3, 'newChildren' => 0, 'oldTotal' => 2000000,
            'newTotal' => 2500000, 'settleAmount' => 500000, 'moneyStatus' => 'collected', 'method' => 'Tiền mặt', 'by' => 'admin', 'createdAt' => now()]);
        DB::transaction(fn() => app(\App\Services\RefundService::class)->requestLocked(DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first(), 'admin', 1, 100));
        $r = DB::table('tbl_refunds')->where('bookingId', $id)->first();
        $this->assertSame(2500000, (int) $r->amount);
        $chk = app(\App\Services\Payments\GatewayRefundService::class)->check($r);
        $this->assertFalse($chk['ok']);
        $this->assertStringContainsString('vượt số còn hoàn được qua', $chk['message']);
    }

    public function test_vnpay_refund_status_is_not_guessed_when_txn_has_other_refunds_and_admin_can_resolve(): void
    {
        $this->refundsOn();
        [$id, $ref] = $this->book('vnpay');
        $this->getJson('/thanh-toan/thong-bao/vnpay?' . http_build_query($this->vnp($ref, 2000000)))->assertJson(['RspCode' => '00']);
        // đã có một lệnh hoàn phần dư qua cổng trước đó
        DB::table('tbl_booking_adjustments')->insert(['bookingId' => $id, 'kind' => 'decrease', 'oldAdults' => 2, 'oldChildren' => 0, 'newAdults' => 1, 'newChildren' => 0, 'oldTotal' => 2000000,
            'newTotal' => 1000000, 'settleAmount' => 500000, 'moneyStatus' => 'refunded', 'method' => 'Hoàn qua VNPay', 'by' => 'admin', 'createdAt' => now(),
            'gateway' => 'vnpay', 'gatewayRefundRef' => 'RFADJ1', 'gatewayStatus' => 'succeeded']);
        DB::transaction(fn() => app(\App\Services\RefundService::class)->requestLocked(DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first(), 'admin', 1, 100));
        $rid = (int) DB::table('tbl_refunds')->where('bookingId', $id)->value('refundId');
        Http::fake(['sandbox.vnpayment.vn/merchant_webapi/*' => fn($r) => $r['vnp_Command'] === 'refund'
            ? Http::response(['vnp_ResponseCode' => '00', 'vnp_TransactionStatus' => '05'])                          // đang xử lý
            : Http::response(['vnp_ResponseCode' => '00', 'vnp_TransactionType' => '03', 'vnp_TransactionStatus' => '00'])]);   // querydr: hoàn MỚI NHẤT xong
        $svc = app(\App\Services\Payments\GatewayRefundService::class);
        $this->assertSame('pending', $svc->process($rid, 1)['status']);
        $sent = collect(Http::recorded())->map(fn($x) => $x[0]->data())->firstWhere('vnp_Command', 'refund');
        $this->assertSame('03', $sent['vnp_TransactionType']);                // đã hoàn một phần trước đó → không được gửi 02
        $sync = $svc->sync($rid, 1);
        $this->assertSame('unknown', $sync['status'], 'querydr không phân biệt lệnh hoàn: không được coi là đã hoàn');
        $this->assertSame('pending', DB::table('tbl_refunds')->where('refundId', $rid)->value('status'));

        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'kt3', 'passWord' => 'x', 'email' => 'kt3@example.com', 'fullName' => 'Kế toán', 'address' => 'HN', 'role' => 'admin']);
        $this->withSession(['adminId' => $admin])->post('/admin/refunds/' . $rid . '/resolve', ['outcome' => 'refunded'])->assertSessionHas('error');   // thiếu mã hoàn
        $this->withSession(['adminId' => $admin])->post('/admin/refunds/' . $rid . '/resolve', ['outcome' => 'refunded', 'reference' => 'VNP-RF-778'])->assertSessionHas('success');
        $r = DB::table('tbl_refunds')->where('refundId', $rid)->first();
        $this->assertSame('refunded', $r->status);
        $this->assertSame('succeeded', $r->gatewayStatus);
    }

    public function test_ledger_backfill_is_idempotent_and_balanced(): void
    {
        [$id, $ref] = $this->book('momo');
        $this->postJson('/thanh-toan/thong-bao/momo', $this->momoIpn($ref, 2000000))->assertNoContent();
        DB::table('tbl_ledger_entries')->delete();   // giả lập dữ liệu có trước khi bật sổ cái
        $ledger = app(\App\Services\Payments\DoubleEntryLedger::class);
        $this->assertSame(1, $ledger->backfill());
        $this->assertSame(0, $ledger->backfill());
        $this->assertSame(-2000000, $ledger->balance(\App\Services\Payments\DoubleEntryLedger::DEPOSIT, $id));
        $this->assertSame([], $ledger->unbalanced());
        $this->expectException(\LogicException::class);
        $ledger->post('x:1', $id, 'capture', [['cash:momo', 100], [\App\Services\Payments\DoubleEntryLedger::DEPOSIT, -99]]);
    }

    public function test_momo_rejects_amount_above_its_limit_without_calling_gateway(): void
    {
        $tour = $this->tour(30);
        DB::table('tbl_tours')->where('tourId', $tour)->update(['priceAdult' => 26000000]);
        $user = $this->user();
        $r = app(BookingService::class)->create($user, ['tourId' => $tour, 'fullName' => 'K', 'email' => 'k@example.com', 'tel' => '0900000000',
            'address' => 'HN', 'numAdults' => 2, 'numChildren' => 0, 'couponCode' => '', 'payment' => 'momo']);
        $start = app(OnlinePaymentService::class)->start($r['bookingId'], 'momo', '127.0.0.1');
        $this->assertFalse($start['ok']);
        $this->assertStringContainsString('50.000.000', $start['message']);
        Http::assertNotSent(fn($req) => str_contains($req->url(), '/v2/gateway/api/create'));
    }
}
