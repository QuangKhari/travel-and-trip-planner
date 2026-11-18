<?php

namespace Tests\Feature;

use App\Services\BookingAdjustmentService;
use App\Services\BookingService;
use App\Services\Departures\DepartureOps;
use App\Services\PriceRules;
use App\Services\PricingService;
use App\Services\Planner\TripLifecycle;
use App\Services\Planner\TripPlanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tầng 3: vòng đời chuyến (mức sẵn sàng + việc có hạn chót, tổng kết sau chuyến), bảng điều hành khởi hành, giá theo mùa.
 */
class ProductLifecycleOpsPricingTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private array $s;
    private int $hn;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'x', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'lp', 'email' => 'lp@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'lp'];
    }

    private function place(string $name, float $lat, float $lng): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => 'museum', 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    private function trip(string $start, int $days, array $items, ?int $budget = null): int
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => $days, 'groupSize' => 2, 'startDate' => $start, 'destinationId' => $this->hn, 'budget' => $budget]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => $days, 'groupSize' => 2, 'startDate' => $start, 'budget' => $budget, 'items' => $items])->assertOk();

        return $id;
    }

    // ================================================================== vòng đời chuyến

    public function test_readiness_scores_tasks_with_deadlines_and_endpoint_works(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355);
        $start = now()->addDays(10)->toDateString();
        $id = $this->trip($start, 3, [['type' => 'place', 'refId' => $a, 'day' => 1, 'cost' => 3000000]], 1000000);
        $trip = DB::table('tbl_trips')->where('tripId', $id)->first();

        $r = app(TripLifecycle::class)->forTrip($trip);
        $this->assertSame('before', $r['phase']);
        $x = $r['readiness'];
        $tasks = collect($x['tasks'])->keyBy('key');
        $this->assertTrue($tasks['dates']['ok']);
        $this->assertFalse($tasks['stay']['ok'], 'chưa đặt chỗ ở cho 2 đêm');
        $this->assertSame(Carbon::parse($start)->subDays(14)->toDateString(), $tasks['stay']['due']);
        $this->assertTrue($tasks['stay']['overdue'], 'hạn đặt chỗ ở (trước 14 ngày) đã qua');
        $this->assertFalse($tasks['budget']['ok'], 'dự toán 3 triệu vượt ngân sách 1 triệu');
        $this->assertFalse($tasks['days']['ok'], 'ngày 2, 3 còn trống');
        $this->assertStringContainsString('ngày 2, 3', $tasks['days']['title']);
        $this->assertFalse($tasks['hours']['ok'], 'chưa biết giờ mở cửa của nơi duy nhất');
        $this->assertFalse($x['tasks'][0]['ok'], 'việc chưa xong đứng trước');
        $this->assertGreaterThan(0, $x['score']);
        $this->assertLessThan(60, $x['score']);
        $this->assertSame('todo', $x['level']);

        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/san-sang")->assertOk()->assertJsonPath('phase', 'before')->assertJsonPath('readiness.score', $x['score']);
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('id="tpReady"', false)->assertSee('planner-ready.js', false);
    }

    public function test_recap_after_trip_compares_planned_and_actual_spending_and_lists_places_to_review(): void
    {
        $a = $this->place('Văn Miếu', 21.0277, 105.8355);
        $b = $this->place('Hồ Tây', 21.06, 105.82);
        $c = $this->place('Nhà hát', 21.024, 105.857);
        $id = $this->trip(now()->subDays(5)->toDateString(), 2, [
            ['type' => 'place', 'refId' => $a, 'day' => 1, 'cost' => 400000, 'visitedAt' => now()->subDays(5)->toIso8601String()],
            ['type' => 'place', 'refId' => $b, 'day' => 1, 'cost' => 600000, 'skippedAt' => now()->subDays(5)->toIso8601String()],
            ['type' => 'place', 'refId' => $c, 'day' => 2, 'visitedAt' => now()->subDays(4)->toIso8601String()],
        ]);
        DB::table('tbl_trip_expenses')->insert([
            ['tripId' => $id, 'title' => 'Ăn trưa', 'category' => 'food', 'amount' => 700000, 'paidBy' => 'u:' . $this->uid, 'splitMode' => 'equal', 'spentOn' => now()->subDays(5)->toDateString(), 'createdBy' => $this->uid],
            ['tripId' => $id, 'title' => 'Vé', 'category' => 'ticket', 'amount' => 500000, 'paidBy' => 'u:' . $this->uid, 'splitMode' => 'equal', 'spentOn' => now()->subDays(5)->toDateString(), 'createdBy' => $this->uid],
        ]);
        DB::table('tbl_place_reviews')->insert(['userId' => $this->uid, 'tripId' => $id, 'refType' => 'place', 'refId' => $a, 'stars' => 5, 'createdAt' => now()]);

        $r = app(TripLifecycle::class)->forTrip(DB::table('tbl_trips')->where('tripId', $id)->first());
        $this->assertSame('after', $r['phase']);
        $this->assertNull($r['readiness']);
        $x = $r['recap'];
        $this->assertSame([1000000, 1200000, 200000, 20.0], [$x['planned'], $x['actual'], $x['diff'], $x['diffPct']]);
        $this->assertEquals(['food' => 700000, 'ticket' => 500000], $x['byCategory']);
        $this->assertSame([2, 1, 0], [$x['visited'], $x['skipped'], $x['unmarked']]);
        $this->assertSame(['Nhà hát'], array_column($x['toReview'], 'name'), 'nơi đã đánh giá và nơi bỏ qua không cần đánh giá');
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/san-sang")->assertOk()->assertJsonPath('recap.toReview.0.url', route('planner.reviews', ['id' => $id]));
    }

    // ================================================================== bảng điều hành khởi hành

    public function test_ops_board_flags_shortfall_unpaid_holds_missing_guide_and_incidents(): void
    {
        $tour = DB::table('tbl_tours')->insertGetId(['title' => 'Sapa 3 ngày', 'time' => '3 ngày', 'description' => 'x', 'quantity' => 20, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Sapa', 'domain' => 'b', 'availability' => 1, 'durationDays' => 3, 'startDate' => now()->addDays(5)->toDateString(), 'endDate' => now()->addDays(7)->toDateString()]);
        $soon = DB::table('tbl_tour_availability')->insertGetId(['tourId' => $tour, 'departureDate' => now()->addDays(2)->toDateString(), 'totalSlots' => 20, 'bookedSlots' => 2, 'heldSlots' => 1,
            'status' => 'open', 'minParticipants' => 8]);
        $fine = DB::table('tbl_tour_availability')->insertGetId(['tourId' => $tour, 'departureDate' => now()->addDays(12)->toDateString(), 'totalSlots' => 20, 'bookedSlots' => 0, 'heldSlots' => 0,
            'status' => 'open', 'minParticipants' => 1]);
        $mk = fn($status, $pay, $people) => tap(DB::table('tbl_booking')->insertGetId(\App\Support\BookingPii::encrypt(['tourId' => $tour, 'userId' => $this->uid, 'availabilityId' => $soon,
            'departureDate' => now()->addDays(2)->toDateString(), 'fullName' => 'K', 'email' => 'k@example.com', 'phoneNumber' => '0900000000', 'address' => 'HN', 'numAdults' => $people,
            'numChildren' => 0, 'totalPrice' => $people * 1000000, 'bookingStatus' => $status, 'bookingCode' => 'OP' . uniqid()])), fn($id) => DB::table('tbl_checkout')->insert(['bookingId' => $id,
                'paymentMethod' => 'office', 'amount' => $people * 1000000, 'paymentStatus' => $pay]));
        $mk('y', 'y', 2);
        $mk('n', 'n', 1);
        DB::table('tbl_tour_incidents')->insert(['availabilityId' => $soon, 'tourId' => $tour, 'type' => 'other', 'severity' => 'high', 'status' => 'open', 'title' => 'Sạt lở đường lên Sapa', 'createdAt' => now()]);

        $b = app(DepartureOps::class)->board(14);
        $row = collect($b['rows'])->firstWhere('availabilityId', $soon);
        $this->assertSame('red', $row['tone']);
        $texts = implode(' | ', array_column($row['flags'], 'text'));
        foreach (['Thiếu khách: 3/8', '1 đơn giữ chỗ chưa thanh toán', 'Chưa giao hướng dẫn viên', 'Chưa xếp nguồn lực', 'Sự cố: Sạt lở đường lên Sapa', 'Còn phải thu 1.000.000đ'] as $t) {
            $this->assertStringContainsString($t, $texts);
        }
        $this->assertSame([2000000, 1000000, 3], [$row['paid'], $row['due'], $row['people']]);
        $this->assertSame('ok', collect($b['rows'])->firstWhere('availabilityId', $fine)['tone']);
        $this->assertSame($soon, $b['rows'][0]['availabilityId'], 'dòng đỏ lên đầu');

        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'op', 'passWord' => 'x', 'email' => 'op@example.com', 'fullName' => 'Điều hành', 'address' => 'HN', 'role' => 'admin']);
        $this->withSession(['adminId' => $admin])->get('/admin/departures/dieu-hanh')->assertOk()->assertSee('Điều hành khởi hành')->assertSee('Sạt lở đường lên Sapa');
    }

    // ================================================================== giá theo mùa

    public function test_seasonal_price_rule_applies_to_quote_booking_and_later_adjustment(): void
    {
        $dep = now()->addDays(20)->toDateString();
        $tour = DB::table('tbl_tours')->insertGetId(['title' => 'Huế 3 ngày', 'time' => '3 ngày', 'description' => 'x', 'quantity' => 20, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Huế', 'domain' => 'b', 'availability' => 1, 'startDate' => $dep, 'endDate' => now()->addDays(22)->toDateString()]);
        $other = DB::table('tbl_tours')->insertGetId(['title' => 'Khác', 'time' => '1 ngày', 'description' => 'x', 'quantity' => 20, 'priceAdult' => 1000000, 'priceChild' => 500000,
            'destination' => 'Huế', 'domain' => 'b', 'availability' => 1, 'startDate' => $dep, 'endDate' => $dep]);
        DB::table('tbl_price_rules')->insert([
            ['tourId' => null, 'dateFrom' => now()->addDays(15)->toDateString(), 'dateTo' => now()->addDays(25)->toDateString(), 'pct' => 10, 'label' => 'Lễ', 'isActive' => 1, 'createdAt' => now()],
            ['tourId' => $tour, 'dateFrom' => now()->addDays(18)->toDateString(), 'dateTo' => now()->addDays(21)->toDateString(), 'pct' => 15, 'label' => 'Cao điểm Tết', 'isActive' => 1, 'createdAt' => now()],
            ['tourId' => $tour, 'dateFrom' => now()->addDays(18)->toDateString(), 'dateTo' => now()->addDays(21)->toDateString(), 'pct' => 90, 'label' => 'Đã tắt', 'isActive' => 0, 'createdAt' => now()],
        ]);
        $this->assertSame(['pct' => 15, 'label' => 'Cao điểm Tết'], array_intersect_key(PriceRules::for($tour, $dep), ['pct' => 1, 'label' => 1]), 'quy tắc riêng của tour thắng quy tắc chung');
        $this->assertSame(10, PriceRules::for($other, $dep)['pct']);
        $this->assertNull(PriceRules::for($tour, now()->addDays(40)->toDateString()));
        $this->assertSame(1150000, PriceRules::apply(1000000, 15));
        $this->assertSame(1000000, PriceRules::apply(1000000, 0));

        $t = DB::table('tbl_tours')->where('tourId', $tour)->first();
        $q = app(PricingService::class)->quote($t, 2, 1, null, $this->uid, [], $dep);
        $this->assertSame(1150000 * 2 + 575000, $q['subtotal']);
        $this->assertSame(['pct' => 15, 'label' => 'Cao điểm Tết'], $q['season']);
        $this->withSession($this->s)->getJson('/dat-tour/bao-gia?' . http_build_query(['tourId' => $tour, 'adults' => 2, 'children' => 1]))->assertOk()->assertJsonPath('season.label', 'Cao điểm Tết')
            ->assertJsonPath('subtotal', 1150000 * 2 + 575000);

        $r = app(BookingService::class)->create($this->uid, ['tourId' => $tour, 'fullName' => 'Khách', 'email' => 'k@example.com', 'tel' => '0900000000', 'address' => 'HN',
            'numAdults' => 2, 'numChildren' => 0, 'couponCode' => '']);
        $bk = DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->first();
        $this->assertSame(2300000, (int) $bk->totalPrice);
        $this->assertSame([15, 'Cao điểm Tết'], [(int) $bk->seasonPct, $bk->seasonLabel]);

        // quy tắc bị tắt sau khi đặt: thêm khách vẫn tính theo mức đã chốt với khách
        DB::table('tbl_price_rules')->update(['isActive' => 0]);
        $adj = app(BookingAdjustmentService::class)->adjust((int) $bk->bookingId, 3, 0, 'customer', $this->uid, $this->uid);
        $this->assertTrue($adj['ok'], json_encode($adj, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3450000, $adj['newTotal']);

        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'pr', 'passWord' => 'x', 'email' => 'pr@example.com', 'fullName' => 'Giá', 'address' => 'HN', 'role' => 'admin']);
        $this->withSession(['adminId' => $admin])->get('/admin/price-rules')->assertOk()->assertSee('Cao điểm Tết');
        $this->withSession(['adminId' => $admin])->post('/admin/price-rules', ['label' => 'Thấp điểm', 'dateFrom' => '2027-06-01', 'dateTo' => '2027-06-30', 'pct' => -20])->assertRedirect();
        $this->withSession(['adminId' => $admin])->post('/admin/price-rules', ['label' => 'Sai', 'dateFrom' => '2027-06-01', 'dateTo' => '2027-06-30', 'pct' => 500])->assertSessionHasErrors('pct');
        $this->assertSame(-20, (int) DB::table('tbl_price_rules')->where('label', 'Thấp điểm')->value('pct'));
    }
}
