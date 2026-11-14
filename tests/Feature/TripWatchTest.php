<?php

namespace Tests\Feature;

use App\Services\Planner\ItemInsights;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Theo dõi chuyến (TripWatch): so với lần trước, chỉ báo cái MỚI, báo "đã ổn" khi hết, im lặng khi không đổi;
 * chuông trong trang (feed / đã xem / kiểm tra ngay); bản tin theo mốc D-7, D-1, mỗi sáng; email một lần.
 * Kèm: xếp lại phần còn lại của hôm nay, so sánh 2 phương án, cột trong nhà của địa điểm.
 */
class TripWatchTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;
    private bool $storm = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-01 09:20:00');
        Cache::flush();
        config(['core_destinations.slugs' => [], 'planner.weather.enabled' => true, 'planner.weather.endpoint' => 'https://wx.test/v1/forecast']);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'tw1', 'email' => 'tw1@example.com', 'password' => 'x', 'fullName' => 'Khách', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'tw1'];
        Http::fake(['wx.test/*' => function ($req) {
            $n = count(explode(',', (string) $req['latitude']));

            return Http::response($n === 1 ? $this->forecast() : array_fill(0, $n, $this->forecast()));
        }]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function forecast(): array
    {
        $h = ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'precipitation' => [], 'weather_code' => [], 'wind_speed_10m' => []];
        $d = ['time' => [], 'weather_code' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => []];
        for ($i = 0; $i < 16; $i++) {
            $date = now()->startOfDay()->addDays($i)->toDateString();
            $bad = $this->storm && $date === '2027-03-03';
            for ($k = 0; $k < 24; $k++) {
                $s = $bad && $k >= 7 && $k <= 12;
                $h['time'][] = $date . 'T' . sprintf('%02d', $k) . ':00';
                $h['temperature_2m'][] = 25;
                $h['precipitation_probability'][] = $s ? 90 : 5;
                $h['precipitation'][] = $s ? 6 : 0;
                $h['weather_code'][] = $s ? 95 : 1;
                $h['wind_speed_10m'][] = $s ? 45 : 6;
            }
            $d['time'][] = $date;
            $d['weather_code'][] = $bad ? 95 : 1;
            $d['temperature_2m_max'][] = 25;
            $d['temperature_2m_min'][] = 18;
            $d['precipitation_probability_max'][] = $bad ? 90 : 5;
        }

        return ['hourly' => $h, 'daily' => $d];
    }

    private function place(string $name, float $lat, float $lng, string $kind, array $extra = []): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now()] + $extra);
    }

    /** Chuyến 2 ngày 03–04/03/2027: ngày 1 điểm ngắm cảnh ngoài trời 08:00, ngày 2 bảo tàng. */
    private function trip(string $start = '2027-03-03', array $more = []): array
    {
        $view = $this->place('Điểm ngắm cảnh hồ', 21.03, 105.85, 'viewpoint');
        $mus = $this->place('Bảo tàng lịch sử', 21.06, 105.86, 'museum');
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => $start, 'destinationId' => $this->hn]);
        $items = array_merge([['type' => 'place', 'refId' => $view, 'day' => 1, 'startMin' => 480, 'durationMin' => 120], ['type' => 'place', 'refId' => $mus, 'day' => 2]], $more);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => $start,
            'items' => $items, 'rev' => (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev')])->assertOk();

        return [$id, $view, $mus];
    }

    private function row(int $id): object
    {
        return DB::table('tbl_trips')->where('tripId', $id)->first();
    }

    public function test_observe_reports_only_changes_and_resolves(): void
    {
        [$id] = $this->trip();
        $w = app(TripWatch::class);
        $first = $w->observe($this->row($id));
        $this->assertSame([], $first['new'], 'trời đẹp, không cảnh báo: lần đầu im lặng');

        $alertId = DB::table('tbl_travel_alerts')->insertGetId(['destinationId' => $this->hn, 'kind' => 'closure', 'level' => 'bad', 'title' => 'Cấm đường quanh hồ',
            'detail' => 'Phân luồng.', 'startsOn' => '2027-03-03', 'endsOn' => '2027-03-03', 'isActive' => 1]);
        $r = $w->observe($this->row($id));
        $this->assertCount(1, $r['new']);
        $this->assertSame('Cấm đường quanh hồ', $r['new'][0]['title']);
        $this->assertSame(1, DB::table('tbl_trip_events')->where(['tripId' => $id, 'kind' => 'alert'])->count());

        $again = $w->observe($this->row($id));
        $this->assertSame([], $again['new'], 'không đổi gì: không báo lại');
        $this->assertSame([], $again['resolved']);

        DB::table('tbl_travel_alerts')->where('alertId', $alertId)->update(['isActive' => 0]);
        $done = $w->observe($this->row($id));
        $this->assertCount(1, $done['resolved']);
        $this->assertSame(1, DB::table('tbl_trip_events')->where(['tripId' => $id, 'kind' => 'resolved'])->count());
    }

    public function test_storm_is_detected_and_mailed_once_to_members(): void
    {
        $this->storm = true;
        Mail::fake();
        [$id] = $this->trip();
        $w = app(TripWatch::class);
        $r = $w->observe($this->row($id));
        $storm = collect($r['new'])->first(fn($e) => str_contains($e['id'], 'weather.storm'));
        $this->assertNotNull($storm, 'dông ngày 1 lúc đang ở điểm ngoài trời');
        $this->assertSame('bad', $storm['tone']);
        $this->assertTrue($w->shouldMail($this->row($id), $r));
        $this->assertSame(1, $w->mailAlerts($this->row($id), $r));
        $this->assertFalse($w->shouldMail($this->row($id), $w->observe($this->row($id))), 'lần sau không có gì mới: không gửi');

        $this->artisan('trips:watch', ['--no-mail' => true])->assertExitCode(0);
    }

    public function test_bell_feed_seen_and_check_now(): void
    {
        [$id] = $this->trip();
        DB::table('tbl_travel_alerts')->insert(['destinationId' => $this->hn, 'kind' => 'traffic', 'level' => 'warn', 'title' => 'Phân luồng lễ hội',
            'startsOn' => '2027-03-04', 'endsOn' => '2027-03-04', 'isActive' => 1]);
        $c = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/kiem-tra")->assertOk()->json();
        $this->assertSame('Phân luồng lễ hội', $c['events'][0]['title']);

        $f = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/thong-bao")->assertOk()->json();
        $this->assertSame(1, $f['unread']);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/thong-bao/da-xem")->assertOk();
        $this->assertSame(0, $this->withSession($this->s)->getJson("/len-ke-hoach/$id/thong-bao")->json('unread'));

        $other = DB::table('tbl_users')->insertGetId(['username' => 'tw2', 'email' => 'tw2@example.com', 'password' => 'x', 'fullName' => 'Lạ', 'isActive' => 'y']);
        $this->withSession(['userId' => $other, 'username' => 'tw2'])->getJson("/len-ke-hoach/$id/thong-bao")->assertNotFound();
    }

    public function test_digest_milestones_are_sent_once(): void
    {
        Mail::fake();
        [$id] = $this->trip('2027-03-08');
        $trip = $this->row($id);
        $this->assertSame('d7', TripWatch::milestone($trip, Carbon::parse('2027-03-01')));
        $this->assertSame('d1', TripWatch::milestone($trip, Carbon::parse('2027-03-07')));
        $this->assertSame('day1', TripWatch::milestone($trip, Carbon::parse('2027-03-08')));
        $this->assertSame('day2', TripWatch::milestone($trip, Carbon::parse('2027-03-09')));
        $this->assertNull(TripWatch::milestone($trip, Carbon::parse('2027-03-04')));
        $this->assertNull(TripWatch::milestone($trip, Carbon::parse('2027-03-10')));

        $w = app(TripWatch::class);
        $this->assertSame('d7', $w->digest($trip, Carbon::parse('2027-03-01')));
        $this->assertNull($w->digest($trip, Carbon::parse('2027-03-01')), 'mỗi mốc một lần');
        $this->assertSame(1, DB::table('tbl_trip_events')->where(['tripId' => $id, 'kind' => 'digest'])->count());
    }

    public function test_place_indoor_column_overrides_kind_guess(): void
    {
        $ctx = ['indoorKinds' => ['museum'], 'outdoorKinds' => ['viewpoint']];
        $this->assertTrue(ItemInsights::classify('place', ['kind' => 'archaeological_site', 'indoor' => false], $ctx)['outdoor']);
        $this->assertFalse(ItemInsights::classify('place', ['kind' => 'attraction', 'indoor' => true], $ctx)['outdoor']);
        $this->assertNull(ItemInsights::classify('place', ['kind' => 'attraction'], $ctx)['outdoor'], 'không biết thì không đoán');
    }

    public function test_replan_today_keeps_fixed_and_drops_what_no_longer_fits(): void
    {
        Carbon::setTestNow('2027-03-03 15:00:00');
        $a = $this->place('Chợ chiều', 21.031, 105.851, 'attraction', ['durationMinutes' => 120]);
        $b = $this->place('Phố sách', 21.032, 105.852, 'attraction', ['durationMinutes' => 120]);
        $c = $this->place('Đền tối', 21.033, 105.853, 'place_of_worship', ['durationMinutes' => 180]);
        [$id] = $this->trip('2027-03-03', [['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1],
            ['type' => 'place', 'refId' => $c, 'day' => 1, 'startMin' => 19 * 60, 'durationMin' => 60]]);

        $r = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=today.replan&day=1&now=900")->assertOk()->json();
        $opt = $r['options'][0] ?? null;
        $this->assertNotNull($opt);
        $this->assertSame('replan', $opt['id']);
        $this->assertNotEmpty($opt['changes']);
        $this->assertFalse(collect($opt['changes'])->contains(fn($x) => ($x['index'] ?? null) === 3 && $x['op'] === 'move'), 'mục cố định 19:00 được giữ');
    }

    public function test_compare_returns_two_paces_with_metrics(): void
    {
        foreach (range(1, 12) as $i) {
            $this->place('Nơi ' . $i, 21.02 + $i / 500, 105.84 + $i / 500, $i % 3 ? 'attraction' : 'museum', ['durationMinutes' => 60, 'priceMin' => 20000, 'indoor' => $i % 3 ? 0 : 1]);
        }
        $this->post('/len-ke-hoach/so-sanh', ['destinationId' => $this->hn, 'days' => 2, 'adults' => 2, 'startDate' => '2027-03-10'])->assertRedirect();
        $r = $this->withSession($this->s)->postJson('/len-ke-hoach/so-sanh', ['destinationId' => $this->hn, 'days' => 2, 'adults' => 2, 'startDate' => '2027-03-10'])->assertOk()->json();
        $this->assertSame(['relaxed', 'packed'], array_column($r['options'], 'pace'));
        $this->assertGreaterThanOrEqual($r['options'][0]['activities'], $r['options'][1]['activities']);
        $this->assertGreaterThan(0, $r['options'][0]['cost'], 'giá vé đã nhập cho địa điểm được cộng vào chi phí');
        $this->assertNotEmpty($r['why']);
    }

    public function test_health_reports_trip_quality_indexes(): void
    {
        $this->storm = true;
        [$id] = $this->trip();
        $q = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json('quality');
        foreach (['feasible', 'weather', 'interest', 'budget', 'travel'] as $k) {
            $this->assertArrayHasKey($k, $q);
            $this->assertArrayHasKey('note', $q[$k]);
        }
        $this->assertSame(100, $q['feasible']['pct'], 'không lỗi cứng');
        $this->assertLessThan(100, $q['weather']['pct'], 'điểm ngoài trời trùng giờ dông');
    }

    public function test_storm_day_without_indoor_alternatives_can_swap_with_dry_day(): void
    {
        $this->storm = true;
        $a = $this->place('Đồi thông', 21.03, 105.85, 'viewpoint');
        $b = $this->place('Thác nước', 21.031, 105.851, 'waterfall', ['indoor' => 0]);
        $c = $this->place('Công viên ven hồ', 21.032, 105.852, 'park', ['indoor' => 0]);
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Mưa', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->hn]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Mưa', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03',
            'items' => [['type' => 'place', 'refId' => $a, 'day' => 1], ['type' => 'place', 'refId' => $b, 'day' => 1], ['type' => 'place', 'refId' => $c, 'day' => 2]],
            'rev' => (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev')])->assertOk();

        $r = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe/xu-ly?code=weather.storm&day=1&index=0")->assertOk()->json();
        $swap = collect($r['options'])->firstWhere('id', 'rain-swapday');
        $this->assertNotNull($swap, 'không có nơi trong nhà nào: vẫn có cách đổi ngày');
        $this->assertTrue($swap['resolves']);
        $this->assertSame(3, count($swap['changes']));
    }
}
