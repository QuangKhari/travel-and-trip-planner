<?php

namespace Tests\Feature;

use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quản trị đơn đặt tour từ đầu tới cuối: danh sách, xuất CSV (chống CSV injection), đặt hộ khách tại quầy, ghi nhận thanh toán,
 * xem trước hoàn tiền, gửi hóa đơn (chỉ nhận PDF thật), hoàn tất (chỉ khi chuyến đã về), hủy và các lần bấm lặp.
 */
class AdminBookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $admin;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'admin']);
        $this->user = DB::table('tbl_users')->insertGetId(['fullName' => '=HYPERLINK("x")', 'username' => 'k', 'password' => 'x', 'email' => 'k@example.com', 'isActive' => 'y']);
    }

    private function ad(): static
    {
        return $this->withSession(['adminId' => $this->admin, 'adminRole' => 'admin']);
    }

    private function tour(int $daysAhead = 30, int $days = 3): int
    {
        return DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour Đà Nẵng', 'time' => "$days ngày", 'description' => '<p>x</p>', 'quantity' => 10,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destination' => 'Đà Nẵng', 'domain' => 't', 'availability' => 1,
            'startDate' => now()->addDays($daysAhead)->toDateString(), 'endDate' => now()->addDays($daysAhead + $days - 1)->toDateString(), 'durationDays' => $days,
        ]);
    }

    private function book(int $tour, int $adults = 2): int
    {
        return app(BookingService::class)->create($this->user, ['tourId' => $tour, 'fullName' => '=HYPERLINK("x")', 'email' => 'k@example.com', 'tel' => '0900000000',
            'address' => 'HN', 'numAdults' => $adults, 'numChildren' => 0, 'couponCode' => '', 'availabilityId' => null])['bookingId'];
    }

    public function test_list_detail_and_csv_export_escape_formulas(): void
    {
        $id = $this->book($this->tour());
        $this->ad()->get('/admin/booking')->assertOk();
        $this->ad()->get("/admin/booking-detail/$id")->assertOk();
        $this->ad()->get('/admin/booking-detail/999999')->assertNotFound();

        $csv = $this->ad()->get('/admin/booking-export?status=n')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'ô bắt đầu bằng = phải được vô hiệu hóa');
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $other = $this->ad()->get('/admin/booking-export?status=c')->streamedContent();
        $this->assertStringNotContainsString('HYPERLINK', $other);
        $this->assertTrue(DB::table('tbl_audit_log')->where('action', 'booking.export')->exists());
    }

    public function test_counter_booking_paid_now_is_confirmed_and_idempotent(): void
    {
        $tour = $this->tour();
        $this->ad()->get('/admin/booking/create?user=' . $this->user)->assertOk();
        $deps = $this->ad()->getJson('/admin/booking/departures?tour=' . $tour)->assertOk()->json();
        $this->assertNotEmpty($deps);

        $token = (string) Str::uuid();
        $payload = ['userId' => $this->user, 'tourId' => $tour, 'availabilityId' => $deps[0]['id'], 'numAdults' => 2, 'numChildren' => 1, 'fullName' => 'Khách quầy',
            'email' => 'k@example.com', 'tel' => '0911222333', 'address' => 'HN', 'paidNow' => 1, 'requestToken' => $token];
        $this->ad()->post('/admin/booking/create', $payload)->assertRedirect()->assertSessionHas('success');
        $b = DB::table('tbl_booking')->where('userId', $this->user)->first();
        $this->assertSame('y', $b->bookingStatus);
        $this->assertSame('y', DB::table('tbl_checkout')->where('bookingId', $b->bookingId)->value('paymentStatus'));

        // bấm gửi lại cùng requestToken: không tạo đơn thứ hai
        $this->ad()->post('/admin/booking/create', $payload)->assertRedirect();
        $this->assertSame(1, DB::table('tbl_booking')->where('userId', $this->user)->count());

        $this->ad()->post('/admin/booking/create', ['tel' => 'abc'] + $payload)->assertSessionHasErrors('tel');
    }

    public function test_payment_preview_invoice_finish_and_cancel_rules(): void
    {
        $id = $this->book($this->tour(10));
        $ok = fn($r) => $r->assertOk()->json('success');

        $this->assertTrue($ok($this->ad()->postJson('/admin/confirm-payment', ['bookingId' => $id])));
        $this->assertSame('y', DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus'));
        $this->assertStringContainsString('trước đó', $this->ad()->postJson('/admin/received-money', ['bookingId' => $id])->json('message'));

        $p = $this->ad()->getJson('/admin/cancel-preview?bookingId=' . $id)->assertOk()->json();
        $this->assertTrue($p['paid']);
        $this->assertGreaterThanOrEqual(0, $p['percent']);
        $this->ad()->getJson('/admin/cancel-preview?bookingId=999999')->assertNotFound();

        // hóa đơn: PDF giả bị từ chối, PDF thật được đính kèm
        $this->assertFalse($ok($this->ad()->postJson('/admin/send-pdf', ['bookingId' => $id, 'pdf' => base64_encode('<html>')])));
        $this->assertTrue($ok($this->ad()->postJson('/admin/send-pdf', ['bookingId' => $id, 'pdf' => 'data:application/pdf;base64,' . base64_encode("%PDF-1.4\n%%EOF")])));
        $this->assertTrue(DB::table('tbl_invoice')->where('bookingId', $id)->exists());

        // xác nhận đơn rồi thử hoàn tất khi chuyến chưa về: bị từ chối
        $this->ad()->postJson('/admin/confirm-booking', ['bookingId' => $id])->assertOk();
        $this->assertSame('y', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));
        $this->assertFalse($ok($this->ad()->postJson('/admin/finish-booking', ['bookingId' => $id])));

        // hủy đơn đã thanh toán: tạo yêu cầu hoàn tiền; hủy lần nữa là no-op
        $this->assertTrue($ok($this->ad()->postJson('/admin/admin-cancel-booking', ['bookingId' => $id, 'reason' => 'Khách đổi ý', 'refundPercent' => 50])));
        $this->assertSame('c', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));
        $this->assertTrue(DB::table('tbl_refunds')->where('bookingId', $id)->exists());
        $this->assertStringContainsString('trước đó', $this->ad()->postJson('/admin/admin-cancel-booking', ['bookingId' => $id])->json('message'));
        $this->assertFalse($ok($this->ad()->postJson('/admin/confirm-payment', ['bookingId' => $this->book($this->tour(20)) + 1000])));
        $this->assertFalse($ok($this->ad()->postJson('/admin/send-pdf', ['bookingId' => $id])));
        $this->assertFalse($ok($this->ad()->postJson('/admin/admin-cancel-booking', ['bookingId' => $id, 'refundPercent' => 150])));
    }

    public function test_finish_after_trip_returns(): void
    {
        $id = $this->book($this->tour(10));
        DB::table('tbl_checkout')->where('bookingId', $id)->update(['paymentStatus' => 'y', 'paymentDate' => now()]);
        DB::table('tbl_booking')->where('bookingId', $id)->update(['bookingStatus' => 'y', 'departureDate' => now()->subDays(10)->toDateString()]);
        DB::table('tbl_tours')->update(['startDate' => now()->subDays(10)->toDateString(), 'endDate' => now()->subDays(8)->toDateString()]);

        $this->ad()->postJson('/admin/finish-booking', ['bookingId' => $id])->assertOk()->assertJson(['success' => true]);
        $this->assertSame('f', DB::table('tbl_booking')->where('bookingId', $id)->value('bookingStatus'));
        $this->ad()->postJson('/admin/finish-booking', ['bookingId' => $id])->assertJson(['success' => true]);
        $this->ad()->postJson('/admin/finish-booking', ['bookingId' => 'x'])->assertJson(['success' => false]);
    }

    public function test_pages_require_admin_session(): void
    {
        $this->get('/admin/booking')->assertRedirect();
        $id = $this->book($this->tour());
        $this->assertNotSame(200, $this->postJson('/admin/confirm-payment', ['bookingId' => $id])->status());
        $this->assertSame('n', DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus'));
        $this->assertFalse(DB::table('tbl_audit_log')->where('action', 'booking.paid')->exists());
    }
}
