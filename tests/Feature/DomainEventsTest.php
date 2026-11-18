<?php

namespace Tests\Feature;

use App\Services\BookingService;
use App\Services\Planner\TripPlanner;
use App\Support\BookingPii;
use App\Support\DomainEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Outbox sự kiện nghiệp vụ Tour / Nội dung → Trip Planner: ghi cùng transaction, xử lý sau commit (hàng đợi), không trùng,
 * gửi lại khi sót; Planner chỉ thông báo vào nhật ký chuyến, không tự sửa lịch của người dùng.
 */
class DomainEventsTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $hn;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'Thủ đô', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'ev', 'email' => 'ev@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
    }

    private function trip(string $start = '2027-03-01', int $days = 5): int
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => $days, 'groupSize' => 2, 'startDate' => $start, 'destinationId' => $this->hn]);
    }

    /** Đơn tour gắn với chuyến tự lập (đặt từ Trip Planner). */
    private function booking(int $tripId, string $departure = '2027-03-02', string $status = 'n'): int
    {
        $tour = DB::table('tbl_tours')->insertGetId(['title' => 'Hạ Long 2 ngày', 'time' => '2 ngày', 'description' => 'x', 'quantity' => 10, 'priceAdult' => 1000000,
            'priceChild' => 500000, 'destination' => 'Hạ Long', 'domain' => 'b', 'availability' => 1, 'durationDays' => 2, 'startDate' => $departure, 'endDate' => $departure]);
        $id = DB::table('tbl_booking')->insertGetId(BookingPii::encrypt(['tourId' => $tour, 'userId' => $this->uid, 'departureDate' => $departure, 'fullName' => 'K',
            'email' => 'k@example.com', 'phoneNumber' => '0900000000', 'address' => 'HN', 'numAdults' => 2, 'numChildren' => 0, 'totalPrice' => 2000000,
            'bookingStatus' => $status, 'bookingCode' => 'EV' . uniqid(), 'tripId' => $tripId]));
        DB::table('tbl_checkout')->insert(['bookingId' => $id, 'paymentMethod' => 'office', 'amount' => 2000000, 'paymentStatus' => 'n']);

        return $id;
    }

    private function events(int $tripId, string $kind): array
    {
        return DB::table('tbl_trip_events')->where(['tripId' => $tripId, 'kind' => $kind])->orderBy('eventId')->get()->all();
    }

    public function test_booking_confirmed_and_cancelled_reach_the_trip_feed_once(): void
    {
        $trip = $this->trip();
        DB::table('tbl_trip_items')->insert(['tripId' => $trip, 'refType' => 'note', 'label' => 'Bảo tàng Dân tộc học', 'dayNo' => 2, 'orderNo' => 0]);
        $b = $this->booking($trip);

        DB::transaction(fn() => app(BookingService::class)->confirmLocked(DB::table('tbl_booking')->where('bookingId', $b)->lockForUpdate()->first()));
        $ev = $this->events($trip, 'booking');
        $this->assertCount(1, $ev);
        $this->assertSame('ok', $ev[0]->tone);
        $this->assertStringContainsString('Hạ Long 2 ngày', $ev[0]->title);
        $this->assertStringContainsString('Bảo tàng Dân tộc học (ngày 2)', $ev[0]->text);   // mục rơi vào ngày đi tour
        $this->assertNotNull(DB::table('tbl_domain_events')->where('name', DomainEvents::BOOKING_CONFIRMED)->value('processedAt'));

        // xử lý lại cùng sự kiện (relay / job lặp): không thông báo trùng
        $id = (int) DB::table('tbl_domain_events')->where('name', DomainEvents::BOOKING_CONFIRMED)->value('eventId');
        DB::table('tbl_domain_events')->where('eventId', $id)->update(['processedAt' => null]);
        \App\Jobs\ProcessDomainEvent::dispatchSync($id);
        $this->assertCount(1, $this->events($trip, 'booking'));

        DB::transaction(fn() => app(BookingService::class)->cancelLocked(DB::table('tbl_booking')->where('bookingId', $b)->lockForUpdate()->first(), 'customer'));
        $ev = $this->events($trip, 'booking');
        $this->assertCount(2, $ev);
        $this->assertSame('warn', $ev[1]->tone);
        $this->assertStringContainsString('đã hủy', $ev[1]->title);
        // Planner KHÔNG tự sửa lịch của người dùng
        $this->assertSame(1, DB::table('tbl_trip_items')->where('tripId', $trip)->count());
    }

    public function test_rolled_back_transaction_leaves_no_event(): void
    {
        $trip = $this->trip();
        $b = $this->booking($trip);
        try {
            DB::transaction(function () use ($b) {
                app(BookingService::class)->confirmLocked(DB::table('tbl_booking')->where('bookingId', $b)->lockForUpdate()->first());
                throw new \RuntimeException('lỗi giữa chừng');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('tbl_domain_events')->count(), 'không có sự kiện "ma" cho thay đổi đã rollback');
        $this->assertSame([], $this->events($trip, 'booking'));
    }

    public function test_departure_change_warns_about_overlapping_items_and_outside_range(): void
    {
        $trip = $this->trip('2027-03-01', 3);
        DB::table('tbl_trip_items')->insert(['tripId' => $trip, 'refType' => 'note', 'label' => 'Chợ Đồng Xuân', 'dayNo' => 3, 'orderNo' => 0]);
        $b = $this->booking($trip, '2027-03-03', 'y');
        DomainEvents::record(DomainEvents::DEPARTURE_CHANGED, 'booking', $b, ['bookingId' => $b, 'from' => '2027-03-02', 'to' => '2027-03-03']);
        $ev = $this->events($trip, 'booking');
        $this->assertCount(1, $ev);
        $this->assertStringContainsString('02/03/2027 → 03/03/2027', $ev[0]->title);
        $this->assertStringContainsString('Chợ Đồng Xuân (ngày 3)', $ev[0]->text);
        $this->assertStringContainsString('KHÔNG tự đổi', $ev[0]->text);
    }

    public function test_unpublishing_a_place_notifies_trips_that_contain_it(): void
    {
        $place = DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => 'p1', 'name' => 'Nhà hát Lớn',
            'category' => 'see', 'kind' => 'theatre', 'latitude' => 21.024, 'longitude' => 105.857, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        $t1 = $this->trip();
        $t2 = $this->trip();
        DB::table('tbl_trip_items')->insert(['tripId' => $t1, 'refType' => 'place', 'refId' => $place, 'dayNo' => 2, 'orderNo' => 0]);
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ct', 'passWord' => 'x', 'email' => 'ct@example.com', 'fullName' => 'Biên tập', 'address' => 'HN', 'role' => 'admin']);

        $this->withSession(['adminId' => $admin])->post("/admin/content/places/$place/status", ['status' => 'draft'])->assertRedirect();

        $ev = $this->events($t1, 'content');
        $this->assertCount(1, $ev);
        $this->assertStringContainsString('Nhà hát Lớn', $ev[0]->title);
        $this->assertSame(2, (int) $ev[0]->day);
        $this->assertSame([], $this->events($t2, 'content'));
    }

    public function test_relay_processes_events_whose_dispatch_was_lost(): void
    {
        $trip = $this->trip();
        $b = $this->booking($trip);
        // giả lập: sự kiện đã ghi nhưng job không được đẩy (tiến trình chết sau commit)
        DB::table('tbl_domain_events')->insert(['name' => DomainEvents::BOOKING_CANCELLED, 'aggregate' => 'booking', 'aggregateId' => (string) $b,
            'payload' => json_encode(['bookingId' => $b, 'reason' => 'expired']), 'createdAt' => now()->subMinutes(5)]);
        Artisan::call('events:relay');
        $this->assertCount(1, $this->events($trip, 'booking'));
        $this->assertSame(0, DB::table('tbl_domain_events')->whereNull('processedAt')->count());
    }
}
