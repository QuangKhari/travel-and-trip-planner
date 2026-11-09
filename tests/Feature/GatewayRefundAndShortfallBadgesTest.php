<?php

namespace Tests\Feature;

use App\Services\DepartureGroupingService;
use App\Services\DepartureService;
use App\Services\Payments\GatewayRefundService;
use App\Services\Payments\MomoGateway;
use App\Services\RefundService;
use App\Support\AdminBadges;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Hoàn tiền qua API MoMo / ZaloPay (Http::fake, không gọi cổng thật) và cờ thiếu khách cho đoàn ghép + chấm vàng. */
class GatewayRefundAndShortfallBadgesTest extends TestCase
{
    use RefreshDatabase;

    private const MOMO_SECRET = 'momo-secret-test';
    private const ZP_KEY1 = 'zalopay-key1-test';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'payments.momo' => ['enabled' => true, 'partner_code' => 'MOMOTEST', 'access_key' => 'ACCESSTEST', 'secret_key' => self::MOMO_SECRET,
                'endpoint' => 'https://test-payment.momo.vn', 'request_type' => 'captureWallet', 'hold_minutes' => 15],
            'payments.zalopay' => ['enabled' => true, 'app_id' => '2553', 'key1' => self::ZP_KEY1, 'key2' => 'k2', 'endpoint' => 'https://sb-openapi.zalopay.vn', 'hold_minutes' => 15],
            'payments.refund' => ['enabled' => true, 'auto' => false, 'auto_delay_minutes' => 0, 'max_attempts' => 3],
        ]);
    }

    private function departure(int $offset, string $suffix, int $min = 1): array
    {
        $tourId = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour hoàn ' . $suffix, 'time' => '3 ngày', 'description' => 'Thử nghiệm', 'quantity' => 10, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Sa Pa', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays($offset)->toDateString(),
            'endDate' => now()->addDays($offset + 2)->toDateString(), 'durationDays' => 3,
        ]);
        foreach (range(1, 3) as $d) {
            DB::table('tbl_timeline')->insert(['tourId' => $tourId, 'title' => 'Ngày ' . $d, 'description' => 'Giống nhau ' . $d]);
        }
        app(DepartureService::class)->ensureForTour($tourId);
        $av = (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('availabilityId');
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->update(['minParticipants' => $min]);

        return [$tourId, $av];
    }

    private function booking(int $tourId, int $av, string $provider = 'momo', ?string $txnNo = '2820000001', string $status = 'y'): int
    {
        $userId = DB::table('tbl_users')->insertGetId(['username' => 'gr' . uniqid(), 'email' => 'gr' . uniqid() . '@example.com', 'password' => 'x', 'fullName' => 'Khách', 'isActive' => 'y']);
        $id = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
            'tourId' => $tourId, 'userId' => $userId, 'availabilityId' => $av,
            'departureDate' => DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('departureDate'),
            'fullName' => 'Khách', 'email' => 'test@example.com', 'phoneNumber' => '0900000000', 'address' => 'Hà Nội',
            'numAdults' => 2, 'numChildren' => 0, 'totalPrice' => 2000000, 'bookingStatus' => $status, 'bookingCode' => 'GR' . uniqid(),
        ]));
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->increment('bookedSlots', 2);
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => $provider, 'provider' => $provider, 'providerTxnRef' => 'TXN' . $id,
            'providerTxnNo' => $txnNo, 'amount' => 2000000, 'paymentStatus' => 'y']);

        return $id;
    }

    /** Đơn đã thanh toán bị hủy → hồ sơ hoàn 100% chờ xử lý. @return array{0:int,1:int} [bookingId, refundId] */
    private function pendingRefund(string $provider = 'momo', ?string $txnNo = '2820000001'): array
    {
        [$tour, $av] = $this->departure(30, uniqid());
        $b = $this->booking($tour, $av, $provider, $txnNo);
        DB::transaction(function () use ($b) {
            $row = DB::table('tbl_booking')->where('bookingId', $b)->lockForUpdate()->first();
            app(RefundService::class)->requestLocked($row, 'admin', 1, 100, 'thử nghiệm');
        });

        return [$b, (int) DB::table('tbl_refunds')->where('bookingId', $b)->value('refundId')];
    }

    public function test_momo_refund_is_signed_marks_refunded_and_logs(): void
    {
        Http::fake(['test-payment.momo.vn/v2/gateway/api/refund' => Http::response(['resultCode' => 0, 'message' => 'Thành công', 'transId' => 3000000001])]);
        [$b, $rid] = $this->pendingRefund();

        $res = app(GatewayRefundService::class)->process($rid, 7, '127.0.0.1');

        $this->assertTrue($res['ok']);
        $this->assertSame('refunded', $res['status']);
        $row = DB::table('tbl_refunds')->where('refundId', $rid)->first();
        $this->assertSame('refunded', $row->status);
        $this->assertSame('succeeded', $row->gatewayStatus);
        $this->assertSame('3000000001', $row->gatewayRefundNo);
        $this->assertSame('Hoàn qua Ví MoMo', $row->method);
        $this->assertSame(7, (int) $row->processedBy);
        $this->assertSame('refunded', DB::table('tbl_checkout')->where('bookingId', $b)->value('paymentStatus'));
        $this->assertSame(1, DB::table('tbl_payment_logs')->where('bookingId', $b)->where('kind', 'refund')->count());
        Http::assertSent(function ($req) {
            $d = $req->data();
            $raw = MomoGateway::raw(['accessKey' => 'ACCESSTEST'] + $d, ['accessKey', 'amount', 'description', 'orderId', 'partnerCode', 'requestId', 'transId']);

            return str_ends_with($req->url(), '/v2/gateway/api/refund') && (int) $d['transId'] === 2820000001 && (int) $d['amount'] === 2000000
                && $d['requestId'] === $d['orderId']   // idempotency: requestId cố định theo mã hoàn, không ngẫu nhiên
                && hash_equals(hash_hmac('sha256', $raw, self::MOMO_SECRET), $d['signature']);
        });
    }

    public function test_momo_rejection_keeps_refund_pending_and_allows_retry_or_manual(): void
    {
        Http::fake(['test-payment.momo.vn/v2/gateway/api/refund' => Http::response(['resultCode' => 1080, 'message' => 'Quá hạn hoàn'])]);
        [, $rid] = $this->pendingRefund();
        $svc = app(GatewayRefundService::class);

        $res = $svc->process($rid, 7);

        $this->assertFalse($res['ok']);
        $this->assertSame('failed', $res['status']);
        $row = DB::table('tbl_refunds')->where('refundId', $rid)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('failed', $row->gatewayStatus);
        $this->assertSame(1, (int) $row->gatewayAttempts);
        $this->assertSame(1, AdminBadges::counts()['refundFail']);
        $this->assertTrue(app(RefundService::class)->markRefunded($rid, 7, 'Chuyển khoản', 'FT1', null)['ok']); // vẫn hoàn tay được
    }

    public function test_timeout_blocks_second_send_and_manual_mark_until_sync_resolves(): void
    {
        Http::fake([
            'test-payment.momo.vn/v2/gateway/api/refund' => fn () => throw new ConnectionException('timeout'),
            'test-payment.momo.vn/v2/gateway/api/refund/query' => Http::response(['resultCode' => 0, 'message' => 'ok', 'refundTrans' => [['resultCode' => 0, 'transId' => 555001]]]),
        ]);
        [, $rid] = $this->pendingRefund();
        $svc = app(GatewayRefundService::class);

        $first = $svc->process($rid, 7);
        $this->assertSame('unknown', $first['status']);
        $this->assertSame('processing', DB::table('tbl_refunds')->where('refundId', $rid)->value('gatewayStatus'));

        $this->assertStringContainsString('đang xử lý', $svc->process($rid, 7)['message']);          // không gửi lệnh lần hai
        $this->assertFalse(app(RefundService::class)->markRefunded($rid, 7, 'Chuyển khoản', 'FT1', null)['ok']); // không ghi tay chồng lên

        $this->assertSame('refunded', $svc->sync($rid, 7)['status']);
        $this->assertSame('refunded', DB::table('tbl_refunds')->where('refundId', $rid)->value('status'));
        $this->assertSame('555001', DB::table('tbl_refunds')->where('refundId', $rid)->value('gatewayRefundNo'));
    }

    public function test_zalopay_refund_is_signed_with_key1(): void
    {
        Http::fake(['sb-openapi.zalopay.vn/v2/refund' => Http::response(['return_code' => 1, 'return_message' => 'ok', 'refund_id' => 998877])]);
        [, $rid] = $this->pendingRefund('zalopay', '221100334455');

        $this->assertSame('refunded', app(GatewayRefundService::class)->process($rid, 7)['status']);
        $this->assertSame('Hoàn qua ZaloPay', DB::table('tbl_refunds')->where('refundId', $rid)->value('method'));
        Http::assertSent(function ($req) {
            $d = $req->data();
            $mac = hash_hmac('sha256', implode('|', [$d['app_id'], $d['zp_trans_id'], $d['amount'], $d['description'], $d['timestamp']]), self::ZP_KEY1);

            return str_ends_with($req->url(), '/v2/refund') && $d['zp_trans_id'] === '221100334455' && hash_equals($mac, $d['mac'])
                && preg_match('/^\d{6}_2553_\d+$/', $d['m_refund_id']) === 1;
        });
    }

    public function test_qr_manual_confirmed_and_disabled_flag_are_not_refunded_via_gateway(): void
    {
        Http::fake();
        [, $qr] = $this->pendingRefund('qr', 'SEPAY-1');
        [, $noTxn] = $this->pendingRefund('momo', null);
        $svc = app(GatewayRefundService::class);
        $this->assertSame('blocked', $svc->process($qr, 7)['status']);
        $this->assertSame('blocked', $svc->process($noTxn, 7)['status']);
        config(['payments.refund.enabled' => false]);
        [, $other] = $this->pendingRefund();
        $this->assertSame('blocked', $svc->process($other, 7)['status']);
        Http::assertNothingSent();
    }

    public function test_auto_mode_refunds_once_and_never_retries_rejected(): void
    {
        config(['payments.refund.auto' => true]);
        Http::fake(['test-payment.momo.vn/v2/gateway/api/refund' => Http::sequence()
            ->push(['resultCode' => 0, 'message' => 'ok', 'transId' => 111])
            ->push(['resultCode' => 1080, 'message' => 'Từ chối'])]);
        [, $ok] = $this->pendingRefund();
        [, $bad] = $this->pendingRefund('momo', '2820000002');
        $svc = app(GatewayRefundService::class);

        $this->assertSame(1, $svc->autoProcess());
        $this->assertSame(0, $svc->autoProcess()); // lần hai: hồ sơ lỗi có gatewayStatus nên không tự thử lại
        $this->assertSame('refunded', DB::table('tbl_refunds')->where('refundId', $ok)->value('status'));
        $this->assertSame('failed', DB::table('tbl_refunds')->where('refundId', $bad)->value('gatewayStatus'));
        Http::assertSentCount(2);
    }

    public function test_grouped_departures_are_flagged_by_group_total_not_individually(): void
    {
        [$t1, $a1] = $this->departure(8, 'gA', 5);
        [$t2, $a2] = $this->departure(8, 'gB', 5);
        $this->booking($t1, $a1);   // 2 khách
        $this->booking($t2, $a2);   // 2 khách -> tổng 4 < 5
        $groups = app(DepartureGroupingService::class);
        $this->assertTrue($groups->merge($a1, $a2)['ok']);
        $gid = (int) DB::table('tbl_departure_groups')->value('groupId');

        $this->artisan('departures:check-shortfall')->assertSuccessful();

        $this->assertNotNull(DB::table('tbl_departure_groups')->where('groupId', $gid)->value('shortfallFlaggedAt'));
        $this->assertSame(0, DB::table('tbl_tour_availability')->whereNotNull('shortfallFlaggedAt')->count());
        $this->assertSame(['booked' => 4, 'minimum' => 5, 'short' => 1, 'members' => 2, 'open' => 2], $groups->groupStats($gid));
        AdminBadges::forget();
        $this->assertSame(1, AdminBadges::counts()['shortfall']);

        // thêm khách: tổng 6 >= 5 -> gỡ cờ, chấm vàng biến mất
        $this->booking($t1, $a1);
        $this->artisan('departures:check-shortfall')->assertSuccessful();
        $this->assertNull(DB::table('tbl_departure_groups')->where('groupId', $gid)->value('shortfallFlaggedAt'));
        AdminBadges::forget();
        $this->assertSame(0, AdminBadges::counts()['shortfall']);
    }

    public function test_group_that_meets_minimum_in_total_is_not_flagged_even_if_each_date_is_short(): void
    {
        [$t1, $a1] = $this->departure(8, 'hA', 3);
        [$t2, $a2] = $this->departure(8, 'hB', 3);
        $this->booking($t1, $a1);   // 2 < 3
        $this->booking($t2, $a2);   // 2 < 3, tổng 4 >= 3
        app(DepartureGroupingService::class)->merge($a1, $a2);
        $this->artisan('departures:check-shortfall')->assertSuccessful();
        $this->assertSame(0, DB::table('tbl_departure_groups')->whereNotNull('shortfallFlaggedAt')->count());
        $this->assertSame(0, DB::table('tbl_tour_availability')->whereNotNull('shortfallFlaggedAt')->count());
    }

    public function test_merging_a_flagged_date_clears_its_own_flag_and_single_flag_counts_in_badge(): void
    {
        [$t1, $a1] = $this->departure(8, 'mA', 5);
        [$t2, $a2] = $this->departure(8, 'mB', 5);
        $this->booking($t1, $a1);
        $this->artisan('departures:check-shortfall')->assertSuccessful();
        $this->assertNotNull(DB::table('tbl_tour_availability')->where('availabilityId', $a1)->value('shortfallFlaggedAt'));
        AdminBadges::forget();
        $this->assertSame(1, AdminBadges::counts()['shortfall']);
        app(DepartureGroupingService::class)->merge($a1, $a2);
        $this->assertNull(DB::table('tbl_tour_availability')->where('availabilityId', $a1)->value('shortfallFlaggedAt'));
    }
}
