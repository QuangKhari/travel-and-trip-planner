<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use App\Services\Travel\FlightSearchService;
use App\Services\Travel\FlightStatusService;
use App\Services\Travel\HotelSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** T-36…T-38 và N-11: API du lịch lỗi / quá giờ / hết lượt thì trang chuyến đi vẫn chạy bằng dữ liệu đã lưu hoặc giả lập có nhãn. */
class TravelApisTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['travel_apis.simulate' => false, 'travel_apis.flight_search.duffel_token' => 'duffel_test_x',
            'travel_apis.flight_status.aerodatabox_key' => 'k1', 'travel_apis.flight_status.aviationstack_key' => 'k2', 'travel_apis.hotel_search.geoapify_key' => 'g']);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-nang', 'name' => 'Đà Nẵng', 'province' => 'Đà Nẵng', 'domain' => 't',
            'description' => 'x', 'latitude' => 16.05, 'longitude' => 108.2, 'isActive' => 1]);
    }

    private function trip(): int
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Đà Nẵng', 'days' => 3, 'groupSize' => 2, 'destinationId' => $this->dest,
            'startDate' => now()->addDays(10)->toDateString()]);
    }

    private function duffelOffer(): array
    {
        $d = now()->addDays(10)->toDateString();

        return ['data' => ['offers' => [[
            'id' => 'off_1', 'total_amount' => '1850000', 'total_currency' => 'VND', 'expires_at' => now()->addMinutes(5)->toIso8601String(), 'owner' => ['name' => 'Duffel Airways'],
            'slices' => [['segments' => [['origin' => ['iata_code' => 'SGN'], 'destination' => ['iata_code' => 'DAD'], 'departing_at' => "{$d}T08:00:00",
                'arriving_at' => "{$d}T09:20:00", 'marketing_carrier' => ['iata_code' => 'ZZ'], 'marketing_carrier_flight_number' => '123']]]],
        ]]]];
    }

    public function test_flight_search_uses_duffel_and_cache_respects_offer_expiry(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response($this->duffelOffer())]);
        $d = now()->addDays(10)->toDateString();
        $r = app(FlightSearchService::class)->search('sgn', 'dad', $d, 2);
        $this->assertFalse($r['simulated']);
        $this->assertSame('ZZ123', $r['offers'][0]['flightNo']);
        $this->assertSame(80, $r['offers'][0]['durationMin']);
        // hạn cache = hạn offer (5 phút) chứ không phải 15 phút
        $exp = DB::table('tbl_flight_offer_cache')->value('expiresAt');
        $this->assertTrue(now()->addMinutes(6)->gt($exp));
        $this->assertTrue(app(FlightSearchService::class)->search('SGN', 'DAD', $d, 2)['cached']);
        Http::assertSentCount(1);
    }

    public function test_flight_search_500_or_timeout_falls_back_to_labelled_simulation_and_trips_breaker(): void
    {
        Http::fake(['api.duffel.com/*' => Http::response(['errors' => []], 500)]);
        $r = app(FlightSearchService::class)->search('SGN', 'DAD', now()->addDays(10)->toDateString(), 1);
        $this->assertTrue($r['simulated']);
        $this->assertSame('Dữ liệu thử nghiệm', $r['label']);
        $this->assertNotNull($r['error']);
        $this->assertNotEmpty($r['offers']);

        // cầu dao: lần sau không gọi API nữa trong thời gian ngắt
        app(FlightSearchService::class)->search('SGN', 'HAN', now()->addDays(10)->toDateString(), 1);
        Http::assertSentCount(1);

        Cache::flush();
        Http::fake(['api.duffel.com/*' => fn() => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->assertTrue(app(FlightSearchService::class)->search('SGN', 'PQC', now()->addDays(11)->toDateString(), 1)['simulated']);
    }

    public function test_simulation_is_stable_for_same_query(): void
    {
        $s = app(FlightSearchService::class);
        $a = $s->simulate('HAN', 'DAD', '2026-12-01', 2);
        $this->assertSame($a, $s->simulate('HAN', 'DAD', '2026-12-01', 2));
        $this->assertNotSame($a, $s->simulate('HAN', 'DAD', '2026-12-02', 2));
    }

    public function test_flight_status_chain_falls_back_provider_then_saved_then_simulated(): void
    {
        $date = now()->toDateString();
        $down = false;   // Http::fake cộng dồn stub nên dùng cờ để đổi hành vi giữa các bước
        Http::fake([
            'aerodatabox.p.rapidapi.com/*' => Http::response([], 503),
            'api.aviationstack.com/*' => function () use (&$down) {
                return $down ? Http::response([], 500) : Http::response(['data' => [['flight_status' => 'active', 'departure' => ['delay' => 25]]]]);
            },
        ]);
        $r = app(FlightStatusService::class)->get('vn 123', $date);
        $this->assertSame('aviationstack', $r['source']);
        $this->assertSame('departed', $r['status']);
        $this->assertSame(1, (int) DB::table('tbl_api_quota')->where('provider', 'aerodatabox')->value('used'));

        // cả hai lỗi -> trạng thái đã lưu, có nhãn thời gian
        Cache::flush();
        $down = true;
        $r = app(FlightStatusService::class)->get('VN123', $date);
        $this->assertTrue($r['stale']);
        $this->assertSame('aviationstack', $r['source']);

        // chuyến chưa từng lưu -> giả lập
        $r = app(FlightStatusService::class)->get('VJ999', $date);
        $this->assertTrue($r['simulated']);
    }

    public function test_quota_stops_calls_before_limit(): void
    {
        config(['travel_apis.flight_status.quota.aerodatabox' => 10, 'travel_apis.flight_status.chain' => ['aerodatabox']]);
        DB::table('tbl_api_quota')->insert(['provider' => 'aerodatabox', 'period' => now()->format('Y-m'), 'used' => 9]);
        Http::fake();
        $r = (new FlightStatusService())->get('VN1', now()->toDateString());
        $this->assertTrue($r['simulated']);
        Http::assertNothingSent();
    }

    public function test_hotel_search_estimates_price_and_falls_back(): void
    {
        $down = false;
        Http::fake(['api.geoapify.com/*' => function () use (&$down) { return $down ? Http::response([], 500) : Http::response(['features' => [['properties' => ['name' => 'Khách sạn A', 'place_id' => 'p1', 'lat' => 16.06, 'lon' => 108.21,
            'distance' => 800, 'address_line2' => 'Sơn Trà', 'accommodation' => ['stars' => 4]]]]]); }]);
        $r = app(HotelSearchService::class)->search(16.05, 108.2, '2026-12-01', 2, 3);
        $this->assertFalse($r['simulated']);
        $this->assertSame(2, $r['rooms']);
        $this->assertTrue($r['hotels'][0]['priceEstimated']);

        $down = true;
        $r = app(HotelSearchService::class)->search(16.07, 108.2, '2026-12-01', 2, 2);
        $this->assertTrue($r['simulated']);
    }

    public function test_add_flight_from_server_cache_creates_item_and_budget(): void
    {
        config(['travel_apis.simulate' => true]);
        $id = $this->trip();
        $q = ['from' => 'SGN', 'to' => 'DAD', 'date' => now()->addDays(10)->toDateString(), 'adults' => 2];
        $offers = $this->withSession(['userId' => $this->uid])->getJson("/len-ke-hoach/$id/chuyen-bay?" . http_build_query($q))->assertOk()->assertJson(['simulated' => true])->json('offers');

        // giá do trình duyệt gửi bị bỏ qua: chỉ offerId
        $this->withSession(['userId' => $this->uid])->postJson("/len-ke-hoach/$id/chuyen-bay", $q + ['offerId' => $offers[0]['id'], 'price' => 1])->assertOk();
        $item = DB::table('tbl_trip_items')->where('tripId', $id)->first();
        $this->assertSame('transport', $item->refType);
        $this->assertSame(1, (int) $item->dayNo);
        $this->assertSame($offers[0]['price'], (int) $item->cost);
        $this->assertStringContainsString('Dữ liệu thử nghiệm', $item->note);
        $this->assertSame(1, DB::table('tbl_trip_external')->where('tripId', $id)->count());

        $this->withSession(['userId' => $this->uid])->postJson("/len-ke-hoach/$id/chuyen-bay", $q + ['offerId' => 'khong-co'])->assertStatus(422);

        // bỏ khỏi danh sách -> bỏ luôn mục lịch trình
        $ext = DB::table('tbl_trip_external')->value('externalId');
        $this->withSession(['userId' => $this->uid])->postJson("/len-ke-hoach/$id/ngoai/$ext/xoa")->assertOk();
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->count());
    }

    public function test_viewer_cannot_add_and_trip_page_still_loads_when_apis_down(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $id = $this->trip();
        $viewer = DB::table('tbl_users')->insertGetId(['username' => 'v', 'email' => 'v@example.com', 'password' => 'x', 'fullName' => 'V', 'isActive' => 'y']);
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $viewer, 'role' => 'view', 'createdAt' => now()]);
        $q = ['from' => 'SGN', 'to' => 'DAD', 'date' => now()->addDays(10)->toDateString(), 'adults' => 1];
        $this->withSession(['userId' => $viewer])->postJson("/len-ke-hoach/$id/chuyen-bay", $q + ['offerId' => 'x'])->assertForbidden();
        $this->withSession(['userId' => $this->uid])->get("/len-ke-hoach/$id")->assertOk()->assertSee('Chuyến bay và khách sạn');
    }
}
