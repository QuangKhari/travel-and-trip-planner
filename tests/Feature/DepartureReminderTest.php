<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** C-10: email nhắc lịch 3 ngày và 1 ngày trước khởi hành, không gửi trùng khi chạy lại. */
class DepartureReminderTest extends TestCase
{
    use RefreshDatabase;

    private function booking(string $status, int $daysAhead): int
    {
        static $n = 0;
        $n++;
        $tour = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour nhắc ' . $n, 'time' => '3 ngày', 'description' => '<p>x</p>', 'quantity' => 10,
            'priceAdult' => 1000000, 'priceChild' => 500000, 'destination' => 'Huế', 'domain' => 'b', 'availability' => 1,
            'startDate' => now()->addDays($daysAhead)->toDateString(), 'endDate' => now()->addDays($daysAhead + 2)->toDateString(),
        ]);
        $user = DB::table('tbl_users')->insertGetId(['username' => "u$n", 'email' => "u$n@example.com", 'password' => 'x', 'fullName' => 'Khách', 'isActive' => 'y']);
        $id = app(\App\Services\BookingService::class)->create($user, [
            'tourId' => $tour, 'fullName' => 'Khách ' . $n, 'email' => "k$n@example.com", 'tel' => '0900000000', 'address' => 'HN',
            'numAdults' => 1, 'numChildren' => 0, 'couponCode' => '',
        ])['bookingId'];
        DB::table('tbl_booking')->where('bookingId', $id)->update(['bookingStatus' => $status]);

        return $id;
    }

    public function test_sends_reminders_at_3_and_1_days_once(): void
    {
        Mail::fake();
        $three = $this->booking('y', 3);
        $one = $this->booking('y', 1);
        $this->booking('y', 2);     // không đúng mốc
        $this->booking('c', 3);     // đã hủy
        $this->booking('n', 1);     // chưa xác nhận

        $this->artisan('bookings:remind-departure')->assertSuccessful();
        Mail::assertQueued(TemplateMail::class, 2);
        Mail::assertQueued(TemplateMail::class, fn($m) => str_contains($m->subjectLine, 'ngày mai') && $m->payload['b']->bookingId == $one);
        Mail::assertQueued(TemplateMail::class, fn($m) => str_contains($m->subjectLine, 'sau 3 ngày') && $m->payload['b']->bookingId == $three);
        $this->assertSame(2, DB::table('tbl_booking_reminders')->count());

        // chạy lại cùng ngày: không gửi thêm
        $this->artisan('bookings:remind-departure')->assertSuccessful();
        Mail::assertQueued(TemplateMail::class, 2);

        // hai ngày sau, đơn "3 ngày" tới mốc 1 ngày
        $this->artisan('bookings:remind-departure', ['--date' => now()->addDays(2)->toDateString()])->assertSuccessful();
        Mail::assertQueued(TemplateMail::class, 3);
        $this->assertTrue(DB::table('tbl_booking_reminders')->where(['bookingId' => $three, 'daysBefore' => 1])->exists());
    }

    public function test_reminder_email_renders(): void
    {
        $id = $this->booking('y', 1);
        $b = (new \App\Models\admin\BookingModel())->getInvoiceBooking($id);
        $html = view('admin.emails.booking-status', ['b' => $b, 'event' => 'reminder', 'refund' => null, 'days' => 1])->render();
        $this->assertStringContainsString('ngày mai', $html);
        $this->assertStringContainsString($b->bookingCode, $html);
    }
}
