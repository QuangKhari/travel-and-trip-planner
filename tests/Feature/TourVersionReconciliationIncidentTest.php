<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Services\DepartureRescueService;
use App\Services\DepartureService;
use App\Services\IncidentService;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\RefundService;
use App\Services\TourVersionService;
use App\Support\AdminBadges;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Phiên bản tour gắn vào booking, đối soát thanh toán/hoàn tiền, sự cố tour nối với Departure Rescue. */
class TourVersionReconciliationIncidentTest extends TestCase
{
    use RefreshDatabase;

    private function tour(int $offset = 30, string $suffix = 'a'): array
    {
        $tourId = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour phiên bản ' . $suffix, 'time' => '3 ngày', 'description' => '<p>Mô tả</p>', 'quantity' => 10, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Sa Pa', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays($offset)->toDateString(),
            'endDate' => now()->addDays($offset + 2)->toDateString(), 'durationDays' => 3,
        ]);
        foreach (range(1, 3) as $d) {
            DB::table('tbl_timeline')->insert(['tourId' => $tourId, 'title' => 'Ngày ' . $d, 'description' => 'Hoạt động ngày ' . $d]);
        }
        app(DepartureService::class)->ensureForTour($tourId);

        return [$tourId, (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('availabilityId')];
    }

    private function user(): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => 'v' . uniqid(), 'email' => 'v' . uniqid() . '@example.com', 'password' => 'x', 'fullName' => 'Khách', 'isActive' => 'y']);
    }

    /** Đơn thẳng vào bảng (không qua BookingService) để dựng tình huống đối soát. */
    private function rawBooking(int $tourId, int $av, string $status = 'y', string $pay = 'y', ?string $provider = null, ?string $txn = null, int $total = 2000000, ?int $amount = null): int
    {
        $id = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
            'tourId' => $tourId, 'userId' => $this->user(), 'availabilityId' => $av,
            'departureDate' => DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('departureDate'),
            'fullName' => 'Khách', 'email' => 'test@example.com', 'phoneNumber' => '0900000000', 'address' => 'HN',
            'numAdults' => 2, 'numChildren' => 0, 'totalPrice' => $total, 'bookingStatus' => $status, 'bookingCode' => 'RC' . uniqid(),
        ]));
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => $provider ?? 'office-payment', 'provider' => $provider, 'providerTxnNo' => $txn,
            'amount' => $amount ?? $total, 'paymentStatus' => $pay]);

        return $id;
    }

    // ------------------------------------------------------------------ phiên bản tour

    public function test_booking_gets_current_version_and_keeps_it_after_tour_is_edited(): void
    {
        [$tour] = $this->tour();
        $res = app(BookingService::class)->create($this->user(), ['tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com', 'tel' => '0900000000',
            'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'couponCode' => '']);
        $b = DB::table('tbl_booking')->where('bookingId', $res['bookingId'])->first();
        $this->assertNotNull($b->tourVersionId);
        $v1 = (int) $b->tourVersionId;

        $svc = app(TourVersionService::class);
        $before = $svc->beforeEdit($tour, 1);
        $this->assertSame($v1, $before);                                   // nội dung chưa đổi: cùng phiên bản
        DB::table('tbl_tours')->where('tourId', $tour)->update(['priceAdult' => 1500000]);
        DB::table('tbl_timeline')->where('tourId', $tour)->where('title', 'Ngày 2')->update(['description' => 'Đổi hoạt động']);
        $after = $svc->afterEdit($tour, $before, 1);

        $this->assertTrue($after['changed']);
        $this->assertSame(2, $after['version']);
        $this->assertSame(1, $after['previous']);
        $this->assertSame(['Giá người lớn', 'Lịch trình ngày 2'], $after['changes']);
        $this->assertSame(1, $after['affected']);                          // 1 đơn vẫn giữ bản cũ
        $this->assertSame($v1, (int) DB::table('tbl_booking')->where('bookingId', $res['bookingId'])->value('tourVersionId'));

        $view = $svc->forBooking(DB::table('tbl_booking')->where('bookingId', $res['bookingId'])->first());
        $this->assertTrue($view['outdated']);
        $this->assertSame(1000000, $view['snapshot']['priceAdult']);       // khách vẫn thấy giá lúc đặt
        $this->assertSame('Hoạt động ngày 2', $view['snapshot']['timeline'][1]['description']);

        $res2 = app(BookingService::class)->create($this->user(), ['tourId' => $tour, 'fullName' => 'Khách 2', 'email' => 'k2@example.com', 'tel' => '0900000001',
            'address' => 'HN', 'numAdults' => 1, 'numChildren' => 0, 'couponCode' => '']);
        $this->assertNotSame($v1, (int) DB::table('tbl_booking')->where('bookingId', $res2['bookingId'])->value('tourVersionId'));   // khách mới nhận bản mới
    }

    public function test_reverting_content_reuses_the_old_version_and_legacy_bookings_are_backfilled(): void
    {
        [$tour, $av] = $this->tour(30, 'rv');
        $legacy = $this->rawBooking($tour, $av);                           // đơn cũ, chưa có tourVersionId
        $svc = app(TourVersionService::class);
        $v1 = $svc->beforeEdit($tour, 1);
        $this->assertSame($v1, (int) DB::table('tbl_booking')->where('bookingId', $legacy)->value('tourVersionId'));
        DB::table('tbl_tours')->where('tourId', $tour)->update(['priceChild' => 700000]);
        $v2 = $svc->ensureCurrent($tour);
        $this->assertNotSame($v1, $v2);
        DB::table('tbl_tours')->where('tourId', $tour)->update(['priceChild' => 500000]);
        $this->assertSame($v1, $svc->ensureCurrent($tour));                // trở lại y như cũ -> dùng lại V1
        $this->assertSame(2, DB::table('tbl_tour_versions')->where('tourId', $tour)->count());
    }

    // ------------------------------------------------------------------ đối soát

    public function test_reconciliation_detects_paid_cancelled_without_refund_and_auto_resolves(): void
    {
        [$tour, $av] = $this->tour(30, 'rc');
        $b = $this->rawBooking($tour, $av, 'c', 'y', 'momo', 'TX1');
        $svc = app(PaymentReconciliationService::class);

        $r = $svc->sync();
        $this->assertSame(1, $r['open']);
        $f = DB::table('tbl_reconciliation_findings')->first();
        $this->assertSame('paid_cancelled_no_refund', $f->type);
        $this->assertSame('high', $f->severity);
        $this->assertSame($b, (int) $f->bookingId);
        AdminBadges::forget();
        $this->assertSame(1, AdminBadges::counts()['reconcile']);

        DB::transaction(fn () => app(RefundService::class)->requestLocked(DB::table('tbl_booking')->where('bookingId', $b)->lockForUpdate()->first(), 'admin', 1, 100, 'x'));
        $r2 = $svc->sync();
        $this->assertSame(0, $r2['open']);
        $this->assertSame('resolved', DB::table('tbl_reconciliation_findings')->where('findingId', $f->findingId)->value('status'));
    }

    public function test_reconciliation_flags_duplicate_txn_amount_mismatch_and_ignore_keeps_status(): void
    {
        [$tour, $av] = $this->tour(30, 'dup');
        $this->rawBooking($tour, $av, 'y', 'y', 'momo', 'DUP-1');
        $this->rawBooking($tour, $av, 'y', 'y', 'momo', 'DUP-1');
        $this->rawBooking($tour, $av, 'y', 'y', 'zalopay', 'OK-9', 2000000, 1500000);   // thu 1,5tr, đơn 2tr
        $svc = app(PaymentReconciliationService::class);
        $svc->sync();
        $types = DB::table('tbl_reconciliation_findings')->pluck('type')->all();
        $this->assertSame(2, count(array_keys($types, 'duplicate_txn', true)));
        $this->assertContains('paid_amount_mismatch', $types);

        $id = (int) DB::table('tbl_reconciliation_findings')->where('type', 'paid_amount_mismatch')->value('findingId');
        $this->assertTrue($svc->ignore($id, 1, 'Khách được giảm trực tiếp tại quầy'));
        $svc->sync();   // lỗi vẫn còn nhưng đã bỏ qua: không mở lại
        $this->assertSame('ignored', DB::table('tbl_reconciliation_findings')->where('findingId', $id)->value('status'));
    }

    public function test_duplicate_manual_refund_reference_is_refused(): void
    {
        [$tour, $av] = $this->tour(30, 'rf');
        $b1 = $this->rawBooking($tour, $av, 'c', 'y', 'qr', 'Q1');
        $b2 = $this->rawBooking($tour, $av, 'c', 'y', 'qr', 'Q2');
        $refunds = app(RefundService::class);
        foreach ([$b1, $b2] as $b) {
            DB::transaction(fn () => $refunds->requestLocked(DB::table('tbl_booking')->where('bookingId', $b)->lockForUpdate()->first(), 'admin', 1, 100, 'x'));
        }
        $r1 = (int) DB::table('tbl_refunds')->where('bookingId', $b1)->value('refundId');
        $r2 = (int) DB::table('tbl_refunds')->where('bookingId', $b2)->value('refundId');
        $this->assertTrue($refunds->markRefunded($r1, 1, 'Chuyển khoản', 'FT-777', null)['ok']);
        $res = $refunds->markRefunded($r2, 1, 'Chuyển khoản', 'FT-777', null);
        $this->assertFalse($res['ok']);
        $this->assertSame('pending', DB::table('tbl_refunds')->where('refundId', $r2)->value('status'));
    }

    // ------------------------------------------------------------------ sự cố + Departure Rescue

    public function test_incident_linked_plan_cancels_with_incident_reason_and_logs_events(): void
    {
        [$tour, $av] = $this->tour(25, 'ic');
        $b = $this->rawBooking($tour, $av, 'y', 'y', 'momo', 'TX-IC');
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->update(['bookedSlots' => 2]);
        $incident = DB::table('tbl_tour_incidents')->insertGetId(['availabilityId' => $av, 'tourId' => $tour, 'type' => 'weather', 'severity' => 'high', 'status' => 'open',
            'title' => 'Bão số 5 cấm tàu ra đảo', 'confidential' => false, 'createdAt' => now(), 'updatedAt' => now()]);

        $svc = app(DepartureRescueService::class);
        $plan = $svc->create(['availabilityId' => $av, 'targetAvailabilityId' => null, 'type' => 'cancel_review', 'declinePolicy' => 'reject_all',
            'description' => 'Bão cấm tàu: không thể tổ chức khởi hành an toàn theo lịch trình.', 'serviceGuarantee' => 'Hoàn 100% số tiền đã thanh toán cho mọi khách.',
            'changeSummary' => 'Hủy khởi hành do thiên tai, hoàn tiền đầy đủ.', 'responseDeadline' => now()->addDays(3)->toDateTimeString()]);
        IncidentService::linkPlan($incident, $plan, 1);
        $this->assertSame('handling', DB::table('tbl_tour_incidents')->where('incidentId', $incident)->value('status'));
        $this->assertSame($plan, (int) DB::table('tbl_tour_incidents')->where('incidentId', $incident)->value('rescuePlanId'));

        $res = $svc->cancelDeparture($plan, 1);

        $this->assertSame(1, $res['cancelled']);
        $this->assertSame('incident', DB::table('tbl_booking')->where('bookingId', $b)->value('cancelReason'));
        $this->assertSame(100, (int) DB::table('tbl_refunds')->where('bookingId', $b)->value('percent'));
        $this->assertStringContainsString('sự cố', (string) DB::table('tbl_refunds')->where('bookingId', $b)->value('reason'));
        $kinds = DB::table('tbl_incident_events')->where('incidentId', $incident)->pluck('kind')->all();
        $this->assertSame(2, count(array_keys($kinds, 'plan', true)));      // gắn phương án + hủy khởi hành
    }

    public function test_incident_pages_confidential_health_and_notify_flow(): void
    {
        Mail::fake();
        [$tour, $av] = $this->tour(25, 'nt');
        $this->rawBooking($tour, $av);
        $mk = fn (string $u, string $role) => DB::table('tbl_admin')->insertGetId(['userName' => $u, 'passWord' => 'x', 'email' => $u . '@example.com', 'fullName' => $u, 'address' => 'HN', 'role' => $role]);
        $reporter = $mk('quanly1', 'manager');
        $other = $mk('quanly2', 'manager');
        $boss = $mk('sep', 'admin');
        $s = ['adminId' => $reporter];
        $this->withSession($s)->post(route('admin.incidents.store'), ['type' => 'health', 'severity' => 'critical', 'title' => 'Khách té ngã khi leo núi',
            'description' => 'Chi tiết y tế nhạy cảm', 'availabilityId' => $av])->assertRedirect();
        $id = (int) DB::table('tbl_tour_incidents')->value('incidentId');
        $this->assertTrue((bool) DB::table('tbl_tour_incidents')->where('incidentId', $id)->value('confidential'));   // sức khỏe tự bảo mật

        // người khác (không phải admin cấp cao, không phải người báo) không thấy nội dung
        $this->withSession(['adminId' => $other])->get(route('admin.incidents.show', $id))->assertOk()->assertDontSee('Chi tiết y tế nhạy cảm');
        $this->withSession($s)->get(route('admin.incidents.show', $id))->assertOk()->assertSee('Chi tiết y tế nhạy cảm');
        $this->withSession(['adminId' => $boss])->get(route('admin.incidents.show', $id))->assertOk()->assertSee('Chi tiết y tế nhạy cảm');

        // không cho đánh dấu đã xử lý khi thiếu kết quả; có kết quả thì được
        $this->withSession($s)->post(route('admin.incidents.status', $id), ['status' => 'resolved', 'note' => ''])->assertSessionHas('error');
        $this->withSession($s)->post(route('admin.incidents.status', $id), ['status' => 'resolved', 'note' => 'Khách đã được đưa đi viện, ổn định.'])->assertSessionHas('success');
        $this->assertSame('resolved', DB::table('tbl_tour_incidents')->where('incidentId', $id)->value('status'));

        $this->withSession($s)->post(route('admin.incidents.notify', $id), ['message' => 'Chuyến đi có điều chỉnh nhỏ, Travela sẽ liên hệ.'])->assertSessionHas('success');
        $this->assertSame(1, DB::table('tbl_incident_events')->where('incidentId', $id)->where('kind', 'notify')->count());
    }
}
