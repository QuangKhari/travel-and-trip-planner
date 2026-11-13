<?php

namespace Tests\Feature;

use App\Services\DepartureService;
use App\Services\NoShowService;
use App\Support\BookingPii;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** B5: đánh dấu khách không đến — chỉ từ ngày khởi hành, không trả chỗ, không hoàn, chặn đánh giá, hoàn tác trong 72 giờ. */
class NoShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @return array{0:int,1:int,2:int} [bookingId, userId, tourId] */
    private function booking(int $offset, string $pay = 'y', string $status = 'y'): array
    {
        $tourId = DB::table('tbl_tours')->insertGetId([
            'title' => 'Tour vắng ' . uniqid(), 'time' => '2 ngày', 'description' => 'x', 'quantity' => 10, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Huế', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays($offset)->toDateString(),
            'endDate' => now()->addDays($offset + 1)->toDateString(), 'durationDays' => 2,
        ]);
        app(DepartureService::class)->ensureForTour($tourId);
        $av = (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('availabilityId');
        $userId = DB::table('tbl_users')->insertGetId(['username' => 'ns' . uniqid(), 'email' => 'ns' . uniqid() . '@example.com', 'password' => 'x', 'fullName' => 'Khách', 'isActive' => 'y']);
        $id = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt([
            'tourId' => $tourId, 'userId' => $userId, 'availabilityId' => $av,
            'departureDate' => DB::table('tbl_tour_availability')->where('availabilityId', $av)->value('departureDate'),
            'fullName' => 'Khách', 'email' => 'test@example.com', 'phoneNumber' => '0900000000', 'address' => 'Huế',
            'numAdults' => 2, 'numChildren' => 0, 'totalPrice' => 2000000, 'bookingStatus' => $status, 'bookingCode' => 'NS' . uniqid(),
        ]));
        DB::table('tbl_tour_availability')->where('availabilityId', $av)->increment('bookedSlots', 2);
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => 'office', 'amount' => 2000000, 'paymentStatus' => $pay]);

        return [$id, $userId, $tourId];
    }

    public function test_cannot_mark_before_departure_or_pending_booking(): void
    {
        [$future] = $this->booking(5);
        $this->assertFalse(app(NoShowService::class)->mark($future, 1, null)['ok']);
        [$pending] = $this->booking(0, 'n', 'n');
        $this->assertFalse(app(NoShowService::class)->mark($pending, 1, null)['ok']);
        $this->assertNull(DB::table('tbl_booking')->where('bookingId', $future)->value('noShowAt'));
    }

    public function test_paid_no_show_keeps_money_and_seats_and_blocks_review(): void
    {
        [$id, $userId, $tourId] = $this->booking(0);
        $booked = (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('bookedSlots');
        $r = app(NoShowService::class)->mark($id, 1, 'Gọi 3 lần không nghe máy');
        $this->assertTrue($r['ok']);
        $b = DB::table('tbl_booking')->where('bookingId', $id)->first();
        $this->assertSame('f', $b->bookingStatus);
        $this->assertNotNull($b->noShowAt);
        $this->assertSame($booked, (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('bookedSlots'));
        $this->assertSame(0, DB::table('tbl_refunds')->where('bookingId', $id)->count());
        $this->assertFalse(app(NoShowService::class)->mark($id, 1, null)['ok'], 'không đánh dấu 2 lần');

        $this->withSession(['userId' => $userId, 'username' => 'u'])
            ->postJson('/reviews', ['tourId' => $tourId, 'rating' => 5, 'message' => 'Rất tốt'])->assertStatus(403);
    }

    public function test_unpaid_no_show_cancels_with_reason_and_undo_restores(): void
    {
        [$id] = $this->booking(-1, 'n');
        $this->assertTrue(app(NoShowService::class)->mark($id, 1, null)['ok']);
        $b = DB::table('tbl_booking')->where('bookingId', $id)->first();
        $this->assertSame('c', $b->bookingStatus);
        $this->assertSame('no_show', $b->cancelReason);

        $this->assertTrue(app(NoShowService::class)->undo($id, 1)['ok']);
        $b = DB::table('tbl_booking')->where('bookingId', $id)->first();
        $this->assertSame('y', $b->bookingStatus);
        $this->assertNull($b->noShowAt);
        $this->assertNull($b->cancelReason);
    }

    public function test_undo_window_expires(): void
    {
        [$id] = $this->booking(0);
        app(NoShowService::class)->mark($id, 1, null);
        DB::table('tbl_booking')->where('bookingId', $id)->update(['noShowAt' => now()->subHours(NoShowService::UNDO_HOURS + 1)]);
        $this->assertFalse(app(NoShowService::class)->undo($id, 1)['ok']);
    }
}
