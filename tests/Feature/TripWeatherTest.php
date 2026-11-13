<?php

namespace Tests\Feature;

use App\Services\Planner\ExternalGuard;
use App\Services\Planner\PlannerEngine;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripWeather;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Thời tiết theo dự báo: T-22 cache theo giờ, T-15 số theo giờ, T-16 xung đột mưa, T-18 nắng nóng, T-19 dông,
 * T-21 phương tiện theo thời tiết, T-20 PlannerEngine đổi mục ngoài trời khỏi ngày mưa, A-10a icon ngày, A-10b WeatherFit.
 * Open-Meteo được giả lập bằng Http::fake; không gọi mạng thật.
 */
class TripWeatherTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;
    private int $calls = 0;
    private bool $down = false;
    /** @var callable(string $date, int $hour): array{p:int,t:int,code:int,wind:int} */
    private $wx;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-01 09:20:00');
        config(['planner.weather.enabled' => true, 'planner.weather.endpoint' => 'https://wx.test/v1/forecast']);
        Cache::flush();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'w1', 'email' => 'w1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'w1'];
        $this->wx = fn(string $date, int $h) => ['p' => 10, 't' => 26, 'code' => 1, 'wind' => 8];
        Http::fake(['wx.test/*' => function ($req) {
            $this->calls++;
            if ($this->down) {
                return Http::response('down', 503);
            }
            $lats = explode(',', (string) $req['latitude']);
            $one = fn() => $this->fakeForecast();

            return Http::response(count($lats) === 1 ? $one() : array_map(fn() => $one(), $lats));
        }]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeForecast(): array
    {
        $h = ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'precipitation' => [], 'weather_code' => [], 'wind_speed_10m' => []];
        $d = ['time' => [], 'weather_code' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => []];
        for ($i = 0; $i < 16; $i++) {
            $date = now()->startOfDay()->addDays($i)->toDateString();
            $mx = 0;
            $tmax = -99;
            for ($k = 0; $k < 24; $k++) {
                $x = ($this->wx)($date, $k);
                $h['time'][] = $date . 'T' . sprintf('%02d', $k) . ':00';
                $h['temperature_2m'][] = $x['t'];
                $h['precipitation_probability'][] = $x['p'];
                $h['precipitation'][] = $x['p'] > 50 ? 2.5 : 0;
                $h['weather_code'][] = $x['code'];
                $h['wind_speed_10m'][] = $x['wind'];
                $mx = max($mx, $x['p']);
                $tmax = max($tmax, $x['t']);
            }
            $d['time'][] = $date;
            $d['weather_code'][] = $mx >= 60 ? 63 : 1;
            $d['temperature_2m_max'][] = $tmax;
            $d['temperature_2m_min'][] = 18;
            $d['precipitation_probability_max'][] = $mx;
        }

        return ['hourly' => $h, 'daily' => $d];
    }

    private function place(string $name, float $lat, float $lng, string $kind): int
    {
        $have = DB::table('tbl_external_places')->where('externalId', $name)->value('placeId');
        if ($have) {
            return (int) $have;
        }

        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    /** Chuyến 1 ngày (ngày 03/03/2027): 08:00 điểm ngắm cảnh (ngoài trời, 120') rồi bảo tàng. */
    private function tripWithOutdoor(string $start = '2027-03-03', array $rainPlan = []): int
    {
        $view = $this->place('Điểm ngắm cảnh hồ', 21.03, 105.85, 'viewpoint');
        $mus = $this->place('Bảo tàng gần hồ', 21.06, 105.86, 'museum');
        $this->place('Bảo tàng dự phòng', 21.031, 105.851, 'museum');
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => $start, 'destinationId' => $this->hn]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => $start,
            'items' => [['type' => 'place', 'refId' => $view, 'day' => 1, 'durationMin' => 120], ['type' => 'place', 'refId' => $mus, 'day' => 1, 'durationMin' => 60]],
            'rainPlan' => (object) $rainPlan])->assertOk();

        return $id;
    }

    private function health(int $id): array
    {
        return $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
    }

    // ------------------------------------------------------------------ T-22: cache theo giờ

    public function test_forecast_is_cached_per_hour_and_refetched_next_hour(): void
    {
        $svc = app(TripWeather::class);
        $a = $svc->forPoints([[21.0285, 105.8542], [21.03, 105.85]]);   // cùng ô lưới 0,1° → một điểm
        $this->assertSame(1, $this->calls);
        $this->assertCount(1, $a);
        $svc->forPoints([[21.0285, 105.8542]]);
        $this->assertSame(1, $this->calls, 'trong cùng giờ không gọi lại');

        Carbon::setTestNow('2027-03-01 10:05:00');
        $svc->forPoints([[21.0285, 105.8542]]);
        $this->assertSame(2, $this->calls, 'sang giờ mới thì lấy bản mới');
        $this->assertSame('wx:fc:21:105.9:2027030110', TripWeather::cacheKey(21.0285, 105.8542));
    }

    public function test_failure_is_not_cached_trips_breaker_and_uses_last_good(): void
    {
        $svc = app(TripWeather::class);
        $svc->forPoints([[21.0285, 105.8542]]);
        Carbon::setTestNow('2027-03-01 11:05:00');
        $this->down = true;
        $r = $svc->forPoints([[21.0285, 105.8542]]);
        $f = reset($r);
        $this->assertTrue($f['stale'], 'lỗi thì dùng bản tốt gần nhất, đánh dấu cũ');
        $this->assertTrue(ExternalGuard::isDown(TripWeather::SERVICE));
        $this->assertNull(Cache::get(TripWeather::cacheKey(21.0285, 105.8542)), 'lỗi không được ghi vào cache của giờ');
    }

    public function test_trip_outside_forecast_range_makes_no_call(): void
    {
        $id = $this->tripWithOutdoor('2027-04-20');   // > 16 ngày
        $h = $this->health($id);
        $this->assertSame(0, $this->calls);
        $this->assertSame('none', $h['weather']['source']);
    }

    // ------------------------------------------------------------------ T-16 / T-18 / T-19 / A-10

    public function test_outdoor_item_in_rainy_hours_warns_and_rain_plan_downgrades(): void
    {
        $this->wx = fn(string $date, int $h) => ['p' => $date === '2027-03-03' && $h >= 8 && $h < 10 ? 85 : 10, 't' => 26, 'code' => 1, 'wind' => 8];
        $id = $this->tripWithOutdoor();
        $h = $this->health($id);
        $c = collect($h['checks'])->firstWhere('code', 'weather.rain');
        $this->assertNotNull($c);
        $this->assertSame('warn', $c['level']);
        $this->assertStringContainsString('85%', $c['text']);
        $this->assertSame(1, $c['day']);
        // A-10a icon ngày + T-15 theo giờ + A-10b WeatherFit (120' ngoài trời 08:00–10:00 đều mưa → 0%)
        $d = $h['weather']['days'][0];
        $this->assertSame('2027-03-03', $d['date']);
        $this->assertNotEmpty($d['icon']);
        $this->assertCount(17, $d['hours']);   // 6h–22h
        $this->assertSame(0, $d['fitPct']);
        $this->assertNotNull(collect($h['checks'])->firstWhere('code', 'weather.source'));

        // cách xử lý: dùng chung phương án mưa (nơi trong nhà gần đó)
        $res = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=weather.rain&day=1&index=0")->assertOk()->json();
        $this->assertContains('rain-backup', array_column($res['options'], 'id'));

        $id2 = $this->tripWithOutdoor('2027-03-03', ['1' => [['type' => 'place', 'refId' => (int) DB::table('tbl_external_places')->where('name', 'Bảo tàng dự phòng')->value('placeId')]]]);
        $c2 = collect($this->health($id2)['checks'])->firstWhere('code', 'weather.rain');
        $this->assertSame('info', $c2['level']);
    }

    public function test_storm_heat_and_dry_day(): void
    {
        $this->wx = fn(string $date, int $h) => ['p' => 20, 't' => $h >= 11 && $h < 15 ? 37 : 30, 'code' => $h === 9 ? 95 : 1, 'wind' => 10];
        $start = '2027-03-03';
        $view = $this->place('Đồi cỏ', 21.03, 105.85, 'viewpoint');
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => $start, 'destinationId' => $this->hn]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 1, 'groupSize' => 2, 'startDate' => $start,
            'items' => [['type' => 'place', 'refId' => $view, 'day' => 1, 'durationMin' => 300]]])->assertOk();   // 08:00–13:00
        $codes = array_column($this->health($id)['checks'], 'code');
        $this->assertContains('weather.storm', $codes);
        $this->assertContains('weather.heat', $codes);
        $this->assertNotContains('weather.ok', $codes);

        $this->wx = fn(string $date, int $h) => ['p' => 5, 't' => 25, 'code' => 0, 'wind' => 5];
        Cache::flush();
        $h = $this->health($id);
        $this->assertContains('weather.ok', array_column($h['checks'], 'code'));
        $this->assertSame(100, $h['weather']['fitPct']);
    }

    public function test_forecast_day_replaces_seasonal_rain_warning_and_motorbike_advice(): void
    {
        config(['planner.route.mode' => 'motorbike']);
        DB::table('tbl_destination_climate')->insert(['destinationId' => $this->hn, 'month' => 3, 'rainyDays' => 20, 'years' => 10, 'periodFrom' => 2015, 'periodTo' => 2024, 'fetchedAt' => now()]);
        $this->wx = fn(string $date, int $h) => ['p' => $h >= 12 ? 80 : 0, 't' => 25, 'code' => 1, 'wind' => 5];
        $id = $this->tripWithOutdoor();
        $codes = array_column($this->health($id)['checks'], 'code');
        $this->assertNotContains('context.rain', $codes, 'đã có dự báo theo giờ thì không cảnh báo theo mùa nữa');
        $this->assertContains('weather.transport', $codes);
    }

    // ------------------------------------------------------------------ T-20: PlannerEngine

    public function test_engine_moves_outdoor_item_off_forecast_rain_day(): void
    {
        $engine = new PlannerEngine();
        $mk = fn(string $k, bool $indoor, float $lat) => ['key' => $k, 'type' => 'experience', 'refId' => 1, 'name' => $k, 'lat' => $lat, 'lng' => 105.85,
            'durationMin' => 120, 'difficulty' => 'easy', 'indoor' => $indoor, 'mustTry' => false, 'rank' => 0];
        $cands = [$mk('ngoai-troi-a', false, 21.00), $mk('ngoai-troi-a2', false, 21.001), $mk('trong-nha-b', true, 21.10), $mk('trong-nha-b2', true, 21.101)];
        $req = ['month' => 3, 'days' => 2, 'groupSize' => 2, 'pace' => 'relaxed', 'interests' => [], 'dislikes' => []];
        $keys = fn(array $day) => array_map(fn($i) => $i['key'], $day['items']);

        // ngày 2 mưa: hai mục trong nhà (điểm cao hơn khi trời mưa) được xếp trước vào ngày 1, mục ngoài trời rơi vào ngày 2 → phải đổi chỗ
        $wet = $engine->plan($cands, $req, ['center' => [21.05, 105.85], 'dayRain' => [1 => 0, 2 => 90]]);
        $this->assertEqualsCanonicalizing(['trong-nha-b', 'trong-nha-b2'], $keys($wet['days'][1]));
        $this->assertEqualsCanonicalizing(['ngoai-troi-a', 'ngoai-troi-a2'], $keys($wet['days'][0]));
        $swaps = array_values(array_filter($wet['filterLog'], fn($l) => $l['code'] === 'weather_swap'));
        $this->assertCount(2, $swaps);
        $this->assertStringContainsString('dự báo mưa 90%', $swaps[0]['reason']);

        // ngày 1 mưa: không cần đổi, mục trong nhà đã ở ngày 1
        $wet1 = $engine->plan($cands, $req, ['center' => [21.05, 105.85], 'dayRain' => [1 => 90, 2 => 0]]);
        $this->assertEqualsCanonicalizing(['trong-nha-b', 'trong-nha-b2'], $keys($wet1['days'][0]));
        $this->assertNotContains('weather_swap', array_column($wet1['filterLog'], 'code'));
    }
}
