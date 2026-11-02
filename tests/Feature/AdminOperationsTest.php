<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Services\BookingException;
use App\Services\BookingService;
use App\Services\CouponService;
use App\Services\DepartureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Chức năng quản trị: lịch khởi hành nhiều ngày (A3/D-02), hoàn tiền theo bậc (A6), mã giảm giá đủ luật (D-04),
 * nhật ký thao tác (D-08), yêu cầu tư vấn (T-39), phân quyền §12.2, /health (PF-08).
 */
class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): int
    {
        static $n = 0;
        $n++;

        return DB::table('tbl_users')->insertGetId([
            'fullName' => "Khách $n", 'username' => "khach$n", 'password' => 'x', 'email' => "khach$n@example.com", 'isActive' => 'y',
        ]);
    }

    private function admin(string $role = 'admin'): int
    {
        static $n = 0;
        $n++;

        return DB::table('tbl_admin')->insertGetId([
            'userName' => "$role$n", 'passWord' => 'x', 'email' => "$role$n@example.com", 'fullName' => ucfirst($role), 'address' => 'HN', 'role' => $role,
        ]);
    }

    private function tour(int $qty = 10, int $daysAhead = 30, int $days = 3): int
    {
        return DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour thử', 'time' => "$days ngày", 'description' => '<p>x</p>', 'quantity' => $qty,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destination' => 'Đà Nẵng', 'domain' => 't', 'availability' => 1,
            'startDate' => now()->addDays($daysAhead)->toDateString(),
            'endDate' => now()->addDays($daysAhead + $days - 1)->toDateString(),
            'durationDays' => $days,
        ]);
    }

    private function input(int $tourId, int $adults = 2, ?int $availabilityId = null, string $coupon = ''): array
    {
        return ['tourId' => $tourId, 'fullName' => 'Khách', 'email' => 'k@example.com', 'tel' => '0900000000', 'address' => 'HN',
            'numAdults' => $adults, 'numChildren' => 0, 'couponCode' => $coupon, 'availabilityId' => $availabilityId];
    }

    private function avail(int $id): object
    {
        return DB::table('tbl_tour_availability')->where('availabilityId', $id)->first();
    }

    private function pay(int $bookingId): void
    {
        DB::table('tbl_checkout')->where('bookingId', $bookingId)->update(['paymentStatus' => 'y', 'paymentDate' => now()]);
    }

    /* ---------------- Lịch khởi hành ---------------- */

    public function test_booking_holds_seats_on_the_chosen_departure_and_syncs_tour_summary(): void
    {
        $tour = $this->tour(10, 20);
        $svc = app(DepartureService::class);
        $svc->ensureForTour($tour);
        $second = $svc->add($tour, now()->addDays(40)->toDateString(), 6)['id'];

        $r = app(BookingService::class)->create($this->user(), $this->input($tour, 4, $second));

        $this->assertSame($second, $r['availabilityId']);
        $this->assertSame(4, (int) $this->avail($second)->heldSlots);
        $this->assertSame($second, (int) DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->value('availabilityId'));
        // Tour vẫn hiển thị chuyến gần nhất (ngày 20) với 10 chỗ
        $this->assertSame(10, (int) DB::table('tbl_tours')->where('tourId', $tour)->value('quantity'));

        $this->expectException(BookingException::class);
        app(BookingService::class)->create($this->user(), $this->input($tour, 3, $second));   // còn 2 chỗ
    }

    public function test_departure_of_another_tour_is_rejected(): void
    {
        $a = $this->tour();
        $b = $this->tour();
        $svc = app(DepartureService::class);
        $svc->ensureForTour($b);
        $foreign = (int) DB::table('tbl_tour_availability')->where('tourId', $b)->value('availabilityId');

        $this->expectException(BookingException::class);
        app(BookingService::class)->create($this->user(), $this->input($a, 1, $foreign));
    }

    public function test_admin_confirm_moves_held_to_booked_and_writes_audit_log(): void
    {
        Mail::fake();
        $tour = $this->tour(10);
        $r = app(BookingService::class)->create($this->user(), $this->input($tour, 3));
        $aid = (int) DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->value('availabilityId');

        $this->withSession(['adminId' => $this->admin('staff')])
            ->postJson('/admin/confirm-booking', ['bookingId' => $r['bookingId']])
            ->assertOk()->assertJson(['success' => true]);

        $a = $this->avail($aid);
        $this->assertSame(0, (int) $a->heldSlots);
        $this->assertSame(3, (int) $a->bookedSlots);
        $this->assertDatabaseHas('tbl_audit_log', ['action' => 'booking.confirm', 'entity' => 'booking', 'entityId' => (string) $r['bookingId']]);
        Mail::assertQueued(TemplateMail::class);
    }

    public function test_admin_departure_rules(): void
    {
        $tour = $this->tour(10);
        $admin = $this->admin('manager');
        $r = app(BookingService::class)->create($this->user(), $this->input($tour, 2));
        $aid = (int) DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->value('availabilityId');

        // tổng chỗ không nhỏ hơn số đã giữ / bán
        $this->withSession(['adminId' => $admin])->post("/admin/departures/$aid", ['totalSlots' => 1])->assertSessionHas('error');
        // có đơn thì không xóa
        $this->withSession(['adminId' => $admin])->post("/admin/departures/$aid/delete")->assertSessionHas('error');
        $this->assertNotNull($this->avail($aid));
        // thêm 3 ngày cách nhau 7 ngày
        $this->withSession(['adminId' => $admin])->post('/admin/departures', [
            'tourId' => $tour, 'departureDate' => now()->addDays(60)->toDateString(), 'totalSlots' => 15, 'repeat' => 2, 'every' => 7,
        ])->assertSessionHas('success');
        $this->assertSame(4, DB::table('tbl_tour_availability')->where('tourId', $tour)->count());
        // ngừng bán thì không đặt được ngày đó
        $this->withSession(['adminId' => $admin])->post("/admin/departures/$aid/toggle")->assertSessionHas('success');
        $this->expectException(BookingException::class);
        app(BookingService::class)->create($this->user(), $this->input($tour, 1, $aid));
    }

    /* ---------------- Hủy & hoàn tiền ---------------- */

    public function test_admin_cancel_paid_booking_creates_refund_and_releases_sold_seats(): void
    {
        Mail::fake();
        $tour = $this->tour(10, 30);
        $r = app(BookingService::class)->create($this->user(), $this->input($tour, 2));
        $aid = (int) DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->value('availabilityId');
        $admin = $this->admin('manager');
        $this->withSession(['adminId' => $admin])->postJson('/admin/confirm-booking', ['bookingId' => $r['bookingId']]);
        $this->pay($r['bookingId']);

        $this->withSession(['adminId' => $admin])
            ->postJson('/admin/admin-cancel-booking', ['bookingId' => $r['bookingId'], 'reason' => 'Khách đổi lịch'])
            ->assertJson(['success' => true]);

        $refund = DB::table('tbl_refunds')->where('bookingId', $r['bookingId'])->first();
        $this->assertSame(100, (int) $refund->percent);                       // còn 30 ngày
        $this->assertSame(2000000, (int) $refund->amount);
        $this->assertSame('refund_pending', DB::table('tbl_checkout')->where('bookingId', $r['bookingId'])->value('paymentStatus'));
        $this->assertSame(0, (int) $this->avail($aid)->bookedSlots);

        $this->withSession(['adminId' => $admin])
            ->post("/admin/refunds/{$refund->refundId}/complete", ['method' => 'Chuyển khoản', 'reference' => 'FT123'])
            ->assertSessionHas('success');
        $this->assertSame('refunded', DB::table('tbl_refunds')->where('refundId', $refund->refundId)->value('status'));
        $this->assertSame('refunded', DB::table('tbl_checkout')->where('bookingId', $r['bookingId'])->value('paymentStatus'));
        $this->assertDatabaseHas('tbl_audit_log', ['action' => 'refund.done']);
    }

    public function test_customer_refund_tiers(): void
    {
        $user = $this->user();
        // 10 ngày: được hủy, hoàn 50%
        $t1 = $this->tour(10, 10);
        $b1 = app(BookingService::class)->create($user, $this->input($t1, 2));
        $this->pay($b1['bookingId']);
        $this->withSession(['userId' => $user, 'username' => 'u'])->post('/cancel-booking', ['bookingId' => $b1['bookingId']])->assertRedirect(route('my-tours'));
        $this->assertSame(50, (int) DB::table('tbl_refunds')->where('bookingId', $b1['bookingId'])->value('percent'));

        // 5 ngày, đã thanh toán: không hủy trên web
        $t2 = $this->tour(10, 5);
        $b2 = app(BookingService::class)->create($user, $this->input($t2, 1));
        $this->pay($b2['bookingId']);
        $this->withSession(['userId' => $user, 'username' => 'u'])->post('/cancel-booking', ['bookingId' => $b2['bookingId']]);
        $this->assertSame('n', DB::table('tbl_booking')->where('bookingId', $b2['bookingId'])->value('bookingStatus'));

        // 5 ngày, chưa thanh toán: hủy tự do, không có hoàn tiền
        $b3 = app(BookingService::class)->create($user, $this->input($t2, 1));
        $this->withSession(['userId' => $user, 'username' => 'u'])->post('/cancel-booking', ['bookingId' => $b3['bookingId']]);
        $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $b3['bookingId'])->value('bookingStatus'));
        $this->assertNull(DB::table('tbl_refunds')->where('bookingId', $b3['bookingId'])->first());
    }

    /* ---------------- Mã giảm giá ---------------- */

    public function test_coupon_min_order_max_discount_and_per_user_limit(): void
    {
        $id = DB::table('tbl_promotion')->insertGetId([
            'code' => 'HE2026', 'description' => 'x', 'discount' => 50, 'maxDiscount' => 300000, 'minOrder' => 1500000, 'perUserLimit' => 2,
            'quantity' => 10, 'startDate' => now()->toDateString(), 'endDate' => now()->toDateString(), 'status' => 'y',
        ]);
        $user = $this->user();
        $c = app(CouponService::class);

        try {
            $c->quote('he2026', 1000000, $user);
            $this->fail('Đơn dưới mức tối thiểu phải bị từ chối');
        } catch (BookingException $e) {
            $this->assertStringContainsString('từ', $e->getMessage());
        }
        $this->assertSame(300000, $c->quote('HE2026', 2000000, $user)['discount']);   // 50% = 1tr, chặn ở 300k (ngày cuối vẫn dùng được)

        $tour = $this->tour(20);
        app(BookingService::class)->create($user, $this->input($tour, 2, null, 'HE2026'));
        app(BookingService::class)->create($user, $this->input($tour, 2, null, 'HE2026'));
        $this->expectException(BookingException::class);
        app(BookingService::class)->create($user, $this->input($tour, 2, null, 'HE2026'));
    }

    /* ---------------- Phân quyền, nhật ký, ticket, health ---------------- */

    public function test_role_permissions_for_new_pages(): void
    {
        $staff = $this->admin('staff');
        $manager = $this->admin('manager');

        foreach (['/admin/refunds', '/admin/departures', '/admin/audit-log'] as $url) {
            $this->withSession(['adminId' => $staff])->get($url)->assertRedirect(route('admin.dashboard'));
            $this->withSession(['adminId' => $manager])->get($url)->assertOk();
        }
        $this->withSession(['adminId' => $staff])->get('/admin/support')->assertOk();
        $this->withSession(['adminId' => $staff])->get('/admin/admin')->assertOk();   // hồ sơ của chính mình
        $this->withSession(['adminId' => $staff])->get('/admin/dashboard')->assertOk();
        $this->withSession(['adminId' => $manager])->get('/admin/dashboard')->assertOk()->assertSee('Lấp đầy');
    }

    public function test_support_ticket_flow(): void
    {
        Mail::fake();
        $user = $this->user();
        $trip = DB::table('tbl_trips')->insertGetId(['userId' => $user, 'kind' => 'trip', 'title' => 'Đà Lạt 3 ngày', 'groupSize' => 2]);

        $this->withSession(['userId' => $user, 'username' => 'u'])->post('/ho-tro', [
            'tripId' => $trip, 'topic' => 'itinerary', 'subject' => 'Nhờ xem lịch trình', 'body' => 'Lịch trình ngày 2 có quá dày không ạ?',
        ])->assertRedirect();
        $t = DB::table('tbl_support_tickets')->where('userId', $user)->first();
        $this->assertSame($trip, (int) $t->tripId);
        $this->assertSame('open', $t->status);

        $staff = $this->admin('staff');
        $this->withSession(['adminId' => $staff])->get("/admin/support/{$t->ticketId}")->assertOk()->assertSee('Nhờ xem lịch trình');
        $this->withSession(['adminId' => $staff])->post("/admin/support/{$t->ticketId}/reply", ['body' => 'Nên bỏ bớt một điểm buổi chiều.'])->assertSessionHas('success');
        $this->assertSame('answered', DB::table('tbl_support_tickets')->where('ticketId', $t->ticketId)->value('status'));
        Mail::assertQueued(TemplateMail::class, 2);   // xác nhận đã nhận + trả lời

        // người khác không xem được ticket
        $this->withSession(['userId' => $this->user(), 'username' => 'o'])->get('/ho-tro/' . $t->code)->assertNotFound();
        $this->withSession(['userId' => $user, 'username' => 'u'])->get('/ho-tro/' . $t->code)->assertOk()->assertSee('Nên bỏ bớt');
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database.ok', true);
    }

    public function test_audit_log_page_lists_actions(): void
    {
        $manager = $this->admin('manager');
        $tour = $this->tour();
        $this->withSession(['adminId' => $manager])->postJson('/admin/toggle-tour', ['tourId' => $tour])->assertJson(['success' => true]);
        $this->withSession(['adminId' => $manager])->get('/admin/audit-log?entity=tour')->assertOk()->assertSee('Ẩn / hiện tour');
    }

    public function test_admin_counterparts_of_customer_features(): void
    {
        Mail::fake();
        $user = $this->user();
        $tour = $this->tour(10);
        $trip = DB::table('tbl_trips')->insertGetId(['userId' => $user, 'kind' => 'trip', 'title' => 'Hội An cuối tuần', 'groupSize' => 2]);
        DB::table('tbl_trip_items')->insert(['tripId' => $trip, 'refType' => 'note', 'dayNo' => 1, 'orderNo' => 1, 'label' => 'Ăn cao lầu']);
        $manager = $this->admin('manager');
        $staff = $this->admin('staff');

        // Đặt tour hộ khách, thu tiền tại quầy -> đơn xác nhận ngay, chỗ đã bán
        app(DepartureService::class)->ensureForTour($tour);
        $aid = (int) DB::table('tbl_tour_availability')->where('tourId', $tour)->value('availabilityId');
        $this->withSession(['adminId' => $manager])->post('/admin/booking/create', [
            'userId' => $user, 'tourId' => $tour, 'availabilityId' => $aid, 'numAdults' => 2, 'numChildren' => 1,
            'fullName' => 'Khách quầy', 'email' => 'quay@example.com', 'tel' => '0911111111', 'address' => 'HN',
            'paidNow' => 1, 'requestToken' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertRedirect();
        $b = DB::table('tbl_booking')->where('userId', $user)->first();
        $this->assertSame('y', $b->bookingStatus);
        $this->assertSame('y', DB::table('tbl_checkout')->where('bookingId', $b->bookingId)->value('paymentStatus'));
        $this->assertSame(3, (int) DB::table('tbl_tour_availability')->where('availabilityId', $aid)->value('bookedSlots'));
        $this->assertDatabaseHas('tbl_audit_log', ['action' => 'booking.create']);

        // Hồ sơ khách gom đơn, chuyến đi; xem chuyến đi chỉ đọc (nhân viên cũng xem được để tư vấn)
        $this->withSession(['adminId' => $manager])->get("/admin/users/$user")->assertOk()->assertSee($b->bookingCode)->assertSee('Hội An cuối tuần');
        $this->withSession(['adminId' => $staff])->get("/admin/trips/$trip")->assertOk()->assertSee('Ăn cao lầu');
        $this->withSession(['adminId' => $staff])->get("/admin/users/$user")->assertRedirect(route('admin.dashboard'));
        $this->withSession(['adminId' => $manager])->get('/admin/payments')->assertOk()->assertSee('Thanh toán trực tuyến');
        $this->withSession(['adminId' => $staff])->get('/admin/booking/create')->assertOk();
        // nhân viên không tự đánh dấu "đã thu tiền" khi đặt hộ
        $this->withSession(['adminId' => $staff])->post('/admin/booking/create', [
            'userId' => $user, 'tourId' => $tour, 'availabilityId' => $aid, 'numAdults' => 1, 'numChildren' => 0,
            'fullName' => 'K', 'email' => 'q2@example.com', 'tel' => '0911111111', 'address' => 'HN', 'paidNow' => 1, 'requestToken' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertRedirect();
        $this->assertSame('n', DB::table('tbl_booking')->where('userId', $user)->orderByDesc('bookingId')->value('bookingStatus'));
    }
}
