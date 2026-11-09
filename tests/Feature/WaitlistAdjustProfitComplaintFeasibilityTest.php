<?php

namespace Tests\Feature;

use App\Services\BookingAdjustmentService;
use App\Services\BookingService;
use App\Services\ComplaintService;
use App\Services\DepartureService;
use App\Services\FeasibilityService;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\ProfitabilityService;
use App\Services\SupplierService;
use App\Services\WaitlistService;
use App\Support\AdminBadges;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Danh sách chờ, điều chỉnh số khách, lợi nhuận đoàn, nhà cung cấp, khiếu nại, kiểm tra khả thi. */
class WaitlistAdjustProfitComplaintFeasibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function tour(int $offset = 30, string $suffix = 'a', int $slots = 10): array
    {
        $tourId = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour tính năng ' . $suffix, 'time' => '3 ngày', 'description' => 'Mô tả', 'quantity' => $slots, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Sa Pa', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays($offset)->toDateString(),
            'endDate' => now()->addDays($offset + 2)->toDateString(), 'durationDays' => 3,
        ]);
        foreach (range(1, 3) as $d) {
            DB::table('tbl_timeline')->insert(['tourId' => $tourId, 'title' => 'Ngày ' . $d, 'description' => 'Hoạt động trong ngày ' . $d . ' của chuyến đi']);
        }
        app(DepartureService::class)->ensureForTour($tourId);
        $av = (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('availabilityId');
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->update(['totalSlots' => $slots, 'bookedSlots' => 0, 'heldSlots' => 0, 'minParticipants' => 1]);

        return [$tourId, $av];
    }

    private function user(string $n = 'u'): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $n . uniqid(), 'email' => $n . uniqid() . '@example.com', 'password' => 'x', 'fullName' => 'Khách ' . $n, 'isActive' => 'y']);
    }

    /** Đơn y thẳng vào bảng; tăng bookedSlots cho khớp. */
    private function booking(int $tourId, int $av, ?int $userId = null, int $adults = 2, bool $paid = true, ?string $provider = 'momo'): int
    {
        $userId = $userId ?? $this->user();
        $total = 1000000 * $adults;
        $id = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
            'tourId' => $tourId, 'userId' => $userId, 'availabilityId' => $av,
            'departureDate' => DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('departureDate'),
            'fullName' => 'Khách', 'email' => 'test@example.com', 'phoneNumber' => '0900000000', 'address' => 'HN',
            'numAdults' => $adults, 'numChildren' => 0, 'totalPrice' => $total, 'bookingStatus' => 'y', 'bookingCode' => 'WF' . uniqid(),
        ]));
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->increment('bookedSlots', $adults);
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => $paid ? ($provider ?? 'office-payment') : 'office-payment', 'provider' => $paid ? $provider : null,
            'providerTxnNo' => $paid && $provider ? 'TX' . $id : null, 'amount' => $total, 'paymentStatus' => $paid ? 'y' : 'n']);

        return $id;
    }

    // ------------------------------------------------------------------ danh sách chờ

    public function test_waitlist_offers_held_seats_and_accept_creates_booking_atomically(): void
    {
        [$tour, $av] = $this->tour(30, 'wl', 2);
        $this->booking($tour, $av, null, 2);                                    // đầy chỗ
        $u = $this->user('wl');
        $svc = app(WaitlistService::class);

        $this->assertTrue($svc->join($u, $av, 2, 0)['ok']);
        $this->assertFalse($svc->join($u, $av, 2, 0)['ok']);                    // không đăng ký trùng
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->update(['bookedSlots' => 0]);   // có người hủy
        $this->assertSame(1, $svc->offerNext($av));
        $w = DB::table('tbl_waitlist')->first();
        $this->assertSame('offered', $w->status);
        $this->assertSame(2, (int) DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('heldSlots'));   // chỗ được giữ cho người được mời

        $res = $svc->accept($w->offerToken, $u, ['fullName' => 'Khách wl', 'email' => 'wl@example.com', 'tel' => '0900000001', 'address' => 'HN', 'payment' => 'office-payment', 'couponCode' => ''], app(BookingService::class));
        $this->assertSame('converted', DB::table('tbl_waitlist')->where('waitId', $w->waitId)->value('status'));
        $this->assertSame((int) $res['bookingId'], (int) DB::table('tbl_waitlist')->where('waitId', $w->waitId)->value('bookingId'));
        $this->assertSame(2, (int) DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('heldSlots'));    // 1 lần giữ (đơn), không giữ đôi
    }

    public function test_waitlist_skips_entries_that_do_not_fit_and_expiry_hands_seats_to_next(): void
    {
        [$tour, $av] = $this->tour(30, 'wf', 4);
        $this->booking($tour, $av, null, 4);
        $big = $this->user('big');
        $small = $this->user('small');
        $svc = app(WaitlistService::class);
        $this->assertTrue($svc->join($big, $av, 3, 0)['ok']);
        $this->assertTrue($svc->join($small, $av, 1, 0)['ok']);
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->update(['bookedSlots' => 3]);     // trống 1 chỗ
        $svc->offerNext($av);
        $this->assertSame('waiting', DB::table('tbl_waitlist')->where('userId', $big)->value('status'));    // không vừa -> bỏ qua
        $this->assertSame('offered', DB::table('tbl_waitlist')->where('userId', $small)->value('status'));

        DB::table('tbl_waitlist')->where('userId', $small)->update(['offerExpiresAt' => now()->subMinute()]);
        $this->assertSame(1, $svc->expireOffers());
        $this->assertSame('expired', DB::table('tbl_waitlist')->where('userId', $small)->value('status'));
        $this->assertSame(0, (int) DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('heldSlots'));
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->update(['bookedSlots' => 0]);
        $svc->processAll();
        $this->assertSame('offered', DB::table('tbl_waitlist')->where('userId', $big)->value('status'));   // giờ vừa 3 người
        AdminBadges::forget();
        $this->assertSame(0, AdminBadges::counts()['waitlist']);
    }

    public function test_cannot_join_waitlist_when_seats_are_available(): void
    {
        [$tour, $av] = $this->tour(30, 'wa', 5);
        $this->assertFalse(app(WaitlistService::class)->join($this->user(), $av, 2, 0)['ok']);
    }

    // ------------------------------------------------------------------ điều chỉnh số khách

    public function test_adjust_unpaid_booking_recomputes_price_and_seats(): void
    {
        [$tour, $av] = $this->tour(30, 'ad', 10);
        $u = $this->user();
        $b = $this->booking($tour, $av, $u, 2, false, null);
        $svc = app(BookingAdjustmentService::class);

        $dry = $svc->adjust($b, 3, 1, 'customer', $u, $u, null, true);
        $this->assertTrue($dry['ok']);
        $this->assertSame(2, (int) DB::table('tbl_booking')->where('bookingId', $b)->value('numAdults'));      // xem trước: chưa ghi

        $r = $svc->adjust($b, 3, 1, 'customer', $u, $u);
        $this->assertTrue($r['ok']);
        $this->assertSame(3500000, $r['newTotal']);
        $this->assertSame(3500000, (int) DB::table('tbl_booking')->where('bookingId', $b)->value('totalPrice'));
        $this->assertSame(3500000, (int) DB::table('tbl_checkout')->where('bookingId', $b)->value('amount'));
        $this->assertSame(4, (int) DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('bookedSlots'));
        $this->assertSame('none', $r['moneyStatus']);
        $this->assertFalse($svc->adjust($b, 50, 0, 'customer', $u, $u)['ok']);                                 // vượt chỗ
        $this->assertFalse($svc->adjust($b, 3, 1, 'customer', $u, $u)['ok']);                                 // không đổi
        $this->assertFalse($svc->adjust($b, 3, 1, 'customer', $u, $this->user())['ok']);                      // đơn của người khác
    }

    public function test_adjust_uses_price_the_customer_agreed_to_not_current_price(): void
    {
        [$tour, $av] = $this->tour(30, 'pv', 10);
        $u = $this->user();
        $b = $this->booking($tour, $av, $u, 2, false, null);
        $vid = app(\App\Services\TourVersionService::class)->ensureCurrent($tour);
        DB::table('tbl_booking')->where('bookingId', $b)->update(['tourVersionId' => $vid]);
        DB::table('tbl_tours')->where('tourId', $tour)->update(['priceAdult' => 2000000]);                      // giá tour tăng sau khi khách đặt
        $r = app(BookingAdjustmentService::class)->adjust($b, 3, 0, 'customer', $u, $u);
        $this->assertSame(3000000, $r['newTotal']);                                                            // vẫn 1tr/người lớn
    }

    public function test_adjust_paid_booking_creates_collect_or_refund_with_own_ledger_and_duplicate_guard(): void
    {
        [$tour, $av] = $this->tour(30, 'pd', 10);
        $u = $this->user();
        $b = $this->booking($tour, $av, $u, 2, true, 'momo');
        $svc = app(BookingAdjustmentService::class);

        $down = $svc->adjust($b, 1, 0, 'customer', $u, $u);                                                    // giảm: hoàn phần dư 100% (còn 30 ngày)
        $this->assertSame('pending_refund', $down['moneyStatus']);
        $this->assertSame(1000000, $down['settleAmount']);
        $this->assertSame(2000000, (int) DB::table('tbl_checkout')->where('bookingId', $b)->value('amount'));  // tiền đã nhận giữ nguyên
        $this->assertFalse($svc->adjust($b, 2, 0, 'customer', $u, $u)['ok']);                                 // còn khoản chờ -> chưa điều chỉnh tiếp
        $this->assertTrue($svc->markRefunded($down['adjustId'], 1, 'Chuyển khoản', 'FT-ADJ-1')['ok']);

        $up = $svc->adjust($b, 3, 0, 'customer', $u, $u);                                                      // tăng: thu thêm
        $this->assertSame('pending_collect', $up['moneyStatus']);
        $this->assertSame(2000000, $up['settleAmount']);                                                       // tổng mới 3tr − tiền còn ở Travela (đã nhận 2tr − đã hoàn 1tr)
        $this->assertFalse($svc->markCollected($up['adjustId'], 1, 'Chuyển khoản', 'FT-ADJ-1')['ok']);        // mã giao dịch trùng
        $this->assertTrue($svc->markCollected($up['adjustId'], 1, 'Chuyển khoản', 'FT-ADJ-2')['ok']);
        $this->assertSame(4000000, (int) DB::table('tbl_checkout')->where('bookingId', $b)->value('amount'));

        // đã điều chỉnh có chủ ý: đối soát không báo "thu ≠ tổng đơn"
        app(PaymentReconciliationService::class)->sync();
        $this->assertSame(0, DB::table('tbl_reconciliation_findings')->where('type', 'paid_amount_mismatch')->count());
    }

    public function test_customer_cannot_adjust_close_to_departure_but_admin_can(): void
    {
        [$tour, $av] = $this->tour(5, 'dc', 10);
        $u = $this->user();
        $b = $this->booking($tour, $av, $u, 2, false, null);
        $svc = app(BookingAdjustmentService::class);
        $this->assertFalse($svc->adjust($b, 3, 0, 'customer', $u, $u)['ok']);
        $this->assertTrue($svc->adjust($b, 3, 0, 'admin', 1, null, 'khách gọi điện')['ok']);
    }

    // ------------------------------------------------------------------ lợi nhuận + nhà cung cấp

    public function test_profit_counts_cash_costs_refunds_and_compensation(): void
    {
        [$tour, $av] = $this->tour(-10, 'pf', 10);
        $b = $this->booking($tour, $av, null, 2, true, 'momo');                                               // thu 2.000.000
        DB::table('tbl_departure_costs')->insert([
            ['availabilityId' => $av, 'category' => 'transport', 'description' => 'Xe 2 ngày', 'amount' => 1500000, 'status' => 'estimated', 'createdAt' => now()],
            ['availabilityId' => $av, 'category' => 'guide', 'description' => 'HDV', 'amount' => 700000, 'status' => 'actual', 'createdAt' => now()],
        ]);
        $p = app(ProfitabilityService::class)->summary($av);
        $this->assertSame(2000000, $p['cashIn']);
        $this->assertSame(2200000, $p['costTotal']);
        $this->assertSame(700000, $p['costActual']);
        $this->assertSame(-200000, $p['profit']);
        $this->assertTrue($p['loss']);                                                                         // đủ khách vẫn lỗ
        $this->assertTrue($p['estimated']);

        DB::table('tbl_complaints')->insert(['code' => 'KN' . strtoupper(bin2hex(random_bytes(5))), 'bookingId' => $b, 'userId' => 1, 'category' => 'service', 'subject' => 'x', 'status' => 'resolved',
            'compensationAmount' => 100000, 'compensationStatus' => 'paid', 'createdAt' => now()]);
        $p2 = app(ProfitabilityService::class)->summary($av);
        $this->assertSame(1900000, $p2['netRevenue']);
        $this->assertSame(-300000, $p2['profit']);
    }

    public function test_supplier_rating_rules_and_attention_flag(): void
    {
        $sid = DB::table('tbl_suppliers')->insertGetId(['name' => 'Xe Sao Việt', 'kind' => 'transport', 'active' => true, 'createdAt' => now()]);
        $svc = app(SupplierService::class);
        $avs = [];
        foreach (['s1', 's2', 's3'] as $i => $k) {
            [, $av] = $this->tour(-5 - $i, $k, 10);
            DB::table('tbl_departure_costs')->insert(['availabilityId' => $av, 'supplierId' => $sid, 'category' => 'transport', 'description' => 'Xe', 'amount' => 1000000, 'status' => 'actual', 'createdAt' => now()]);
            $avs[] = $av;
        }
        [, $future] = $this->tour(20, 'sf', 10);
        DB::table('tbl_departure_costs')->insert(['availabilityId' => $future, 'supplierId' => $sid, 'category' => 'transport', 'description' => 'Xe', 'amount' => 1, 'status' => 'estimated', 'createdAt' => now()]);
        $this->assertFalse($svc->rate($sid, $future, 5, true, null, 1)['ok']);                                 // chưa khởi hành
        foreach ($avs as $av) {
            $this->assertTrue($svc->rate($sid, $av, 2, false, 'Xe trễ', 1)['ok']);
        }
        $this->assertFalse($svc->rate($sid, $avs[0], 5, true, null, 1)['ok']);                                 // đánh giá trùng
        $st = $svc->stats($sid)[$sid];
        $this->assertSame(2.0, (float) $st['avg']);
        $this->assertSame(0, (int) $st['onTimePct']);
        $this->assertTrue($st['attention']);
        $this->assertSame(4, $st['trips']);
    }

    // ------------------------------------------------------------------ khiếu nại

    public function test_complaint_eligibility_flow_and_compensation_rules(): void
    {
        [$tour, $av] = $this->tour(-10, 'kn', 10);                                                             // đã đi xong (3 ngày, khởi hành 10 ngày trước)
        $u = $this->user();
        $bid = $this->booking($tour, $av, $u, 2, true, 'momo');
        $booking = DB::table('tbl_booking')->where('bookingId', $bid)->first();
        $svc = app(ComplaintService::class);
        $this->assertTrue($svc->eligibility($booking)['can']);

        [$tour2, $av2] = $this->tour(10, 'kn2', 10);                                                           // chưa đi
        $future = DB::table('tbl_booking')->where('bookingId', $this->booking($tour2, $av2))->first();
        $this->assertFalse($svc->eligibility($future)['can']);
        DB::table('tbl_booking')->where('bookingId', $bid)->update(['departureDate' => now()->subDays(60)->toDateString()]);
        $this->assertFalse($svc->eligibility(DB::table('tbl_booking')->where('bookingId', $bid)->first())['can']);   // quá hạn 30 ngày
        DB::table('tbl_booking')->where('bookingId', $bid)->update(['departureDate' => now()->subDays(10)->toDateString()]);

        $c = $svc->create($booking, $u, 'hotel', 'Phòng không như mô tả', 'Khách sạn thực tế thấp hơn hạng đã cam kết, điều hòa hỏng suốt đêm.', 500000);
        $this->assertSame('open', $c->status);
        $this->assertFalse($svc->setStatus($c->complaintId, 'resolved', 1, 'ngắn')['ok']);                    // phải có kết quả
        $this->assertFalse($svc->setCompensation($c->complaintId, 3000000, null)['ok']);                       // vượt số đã thanh toán
        $this->assertTrue($svc->setCompensation($c->complaintId, 500000, null)['ok']);
        $this->assertFalse($svc->setStatus($c->complaintId, 'closed', 1, null)['ok']);                         // open -> closed không hợp lệ
        $this->assertTrue($svc->setStatus($c->complaintId, 'resolved', 1, 'Đã xác minh với khách sạn, bồi hoàn 500.000 đ.')['ok']);
        $this->assertFalse($svc->setStatus($c->complaintId, 'closed', 1, null)['ok']);                         // còn bồi hoàn chờ chi
        $this->assertTrue($svc->markCompensationPaid($c->complaintId, 1, 'Chuyển khoản', 'FT-KN-1')['ok']);
        $this->assertTrue($svc->setStatus($c->complaintId, 'closed', 1, null)['ok']);

        $c2 = $svc->create($booking, $u, 'food', 'Bữa tối thiếu món', 'Bữa tối ngày hai thiếu món so với thực đơn công bố, đã nhắc nhưng không được xử lý.', 100000);
        $svc->setCompensation($c2->complaintId, 100000, null);
        $this->assertFalse($svc->markCompensationPaid($c2->complaintId, 1, 'Chuyển khoản', 'FT-KN-1')['ok']);  // mã giao dịch đã dùng
        AdminBadges::forget();
        $this->assertSame(1, AdminBadges::counts()['complaint']);
    }

    // ------------------------------------------------------------------ khả thi

    public function test_feasibility_flags_structure_time_order_and_ignores_duration_phrases(): void
    {
        $tourId = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour kiểm tra khả thi', 'time' => '3 ngày', 'description' => 'Mô tả', 'quantity' => 10, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Chưa có dữ liệu địa điểm', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays(30)->toDateString(),
            'endDate' => now()->addDays(32)->toDateString(), 'durationDays' => 3,
        ]);
        DB::table('tbl_timeline')->insert([
            ['tourId' => $tourId, 'title' => 'Ngày 1', 'description' => '<p>09:00 Tham quan bản làng</p><p>08:00 Ăn sáng tại khách sạn rồi nghỉ ngơi</p>'],
            ['tourId' => $tourId, 'title' => 'Ngày 2', 'description' => 'Tự do'],
        ]);
        app(DepartureService::class)->ensureForTour($tourId);
        $codes = collect(app(FeasibilityService::class)->check($tourId)['findings'])->pluck('code')->all();
        $this->assertContains('duration_mismatch', $codes);   // ghi 3 ngày, lịch trình 2 ngày
        $this->assertContains('time_order', $codes);          // 09:00 rồi 08:00
        $this->assertContains('day_empty', $codes);           // ngày 2 quá ngắn
        $this->assertContains('no_places', $codes);           // điểm đến chưa có dữ liệu -> kiểm tra thủ công

        DB::table('tbl_timeline')->where('tourId', $tourId)->delete();
        DB::table('tbl_timeline')->insert([
            ['tourId' => $tourId, 'title' => 'Ngày 1', 'description' => '07:00 Khởi hành, khoảng 2h di chuyển tới điểm đầu tiên rồi nghỉ ngơi. 12:00 Ăn trưa tại nhà hàng địa phương.'],
            ['tourId' => $tourId, 'title' => 'Ngày 2', 'description' => 'Tham quan tự do trong ngày, không cố định giờ, hướng dẫn viên hỗ trợ.'],
            ['tourId' => $tourId, 'title' => 'Ngày 3', 'description' => 'Ngày 3: ăn sáng, trả phòng và trở về điểm xuất phát.'],
        ]);
        $r = app(FeasibilityService::class)->check($tourId);
        $codes = collect($r['findings'])->pluck('code')->all();
        $this->assertNotContains('duration_mismatch', $codes);
        $this->assertNotContains('time_order', $codes);
        $this->assertNotContains('too_early', $codes);        // "khoảng 2h" là thời lượng, không phải mốc 02:00
        $this->assertContains('no_times', $codes);            // ngày 2, 3 không có mốc giờ -> thủ công
        $this->assertSame(0, $r['counts']['error']);
    }
}
