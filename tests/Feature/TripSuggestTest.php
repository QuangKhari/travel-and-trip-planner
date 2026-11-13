<?php

namespace Tests\Feature;

use App\Services\Ai\Copilot\TripSuggest;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La bàn "gợi ý để chọn" sau khi đã có chuyến: món ăn đúng mùa, điểm tham quan, lễ hội trùng dịp đi, cảnh báo dông / sự cố
 * kèm cách đổi lịch. Chạy bằng luật (không LLM); nút trên thẻ trả phiếu kiểm chứng, KHÔNG tự ghi vào chuyến.
 */
class TripSuggestTest extends TestCase
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
        config(['ai.enabled' => true, 'ai.features.copilot' => true, 'core_destinations.slugs' => [],
            'planner.weather.enabled' => true, 'planner.weather.endpoint' => 'https://wx.test/v1/forecast']);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'sg1', 'email' => 'sg1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'sg1'];
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

    private function place(string $name, float $lat, float $lng, string $kind, int $must = 0): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => $must]);
    }

    /** Chuyến 2 ngày 03–04/03/2027: ngày 1 điểm ngắm cảnh ngoài trời 08:00, ngày 2 bảo tàng. */
    private function trip(): array
    {
        $view = $this->place('Điểm ngắm cảnh hồ', 21.03, 105.85, 'viewpoint');
        $mus = $this->place('Bảo tàng lịch sử', 21.06, 105.86, 'museum');
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03', 'destinationId' => $this->hn]);
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-03',
            'items' => [['type' => 'place', 'refId' => $view, 'day' => 1, 'startMin' => 480, 'durationMin' => 120], ['type' => 'place', 'refId' => $mus, 'day' => 2]],
            'rev' => (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev')])->assertOk();

        return [$id, $view, $mus];
    }

    private function rev(int $id): int
    {
        return (int) DB::table('tbl_trips')->where('tripId', $id)->value('rev');
    }

    public function test_intent_routing_leaves_concrete_edits_to_copilot(): void
    {
        $this->assertSame('food', TripSuggest::intent('Gợi ý món ăn ngon ở đây'));
        $this->assertSame('food', TripSuggest::intent('muốn thêm đặc sản vào ngày 2'));
        $this->assertSame('festival', TripSuggest::intent('Thời gian đi có lễ hội gì không?'));
        $this->assertSame('alerts', TripSuggest::intent('ngày 2 có mưa giông không'));
        $this->assertSame('places', TripSuggest::intent('gợi ý thêm địa điểm tham quan'));
        $this->assertNull(TripSuggest::intent('thay bảo tàng bằng quán ăn'));
        $this->assertNull(TripSuggest::intent('ngày 2 nhẹ hơn'));
        $this->assertNull(TripSuggest::intent('món ăn ở đây', [['name' => 'Bảo tàng', 'day' => 2]]));
        $this->assertSame(2, TripSuggest::dayOf('món ăn gần ngày 2'));
    }

    public function test_food_cards_are_in_season_and_add_returns_checked_proposal_without_writing(): void
    {
        [$id] = $this->trip();
        DB::table('tbl_foods')->insert([
            ['slug' => 'bun-cha', 'name' => 'Bún chả', 'destinationId' => $this->hn, 'category' => 'Bún', 'description' => 'Thịt nướng than hoa.', 'reviewStatus' => 'published', 'isMustTry' => 1, 'monthFrom' => 1, 'monthTo' => 12],
            ['slug' => 'com-vong', 'name' => 'Cốm làng Vòng', 'destinationId' => $this->hn, 'category' => 'Quà', 'description' => 'Mùa thu.', 'reviewStatus' => 'published', 'isMustTry' => 1, 'monthFrom' => 8, 'monthTo' => 10],
            ['slug' => 'nhap', 'name' => 'Món nháp', 'destinationId' => $this->hn, 'category' => 'x', 'description' => 'x', 'reviewStatus' => 'draft', 'isMustTry' => 0, 'monthFrom' => null, 'monthTo' => null],
        ]);
        $this->place('Quán phở gần hồ', 21.031, 105.851, 'restaurant');
        $rev = $this->rev($id);

        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban", ['text' => 'Gợi ý món ăn nên thử ở đây'])->assertOk()->json();
        $this->assertSame('suggest', $r['kind']);
        $titles = array_column($r['cards'], 'title');
        $this->assertContains('Bún chả', $titles);
        $this->assertContains('Quán phở gần hồ', $titles);
        $this->assertNotContains('Cốm làng Vòng', $titles, 'trái mùa');
        $this->assertNotContains('Món nháp', $titles, 'chưa duyệt');

        $card = collect($r['cards'])->firstWhere('title', 'Bún chả');
        $ap = $card['actions'][0]['apply'];
        $this->assertSame('add', $ap['op']);
        $p = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/goi-y/ap-dung", array_merge($ap, ['toDay' => 2, 'rev' => $rev]))->assertOk()->json();
        $this->assertSame('proposal', $p['kind']);
        $this->assertSame('add', $p['changes'][0]['op']);
        $this->assertSame(2, $p['changes'][0]['toDay']);
        $this->assertArrayHasKey('receipt', $p);
        $this->assertSame($rev, $this->rev($id), 'gợi ý không tự ghi vào chuyến');

        // lịch đã đổi sau khi gợi ý -> báo cũ, không đề xuất
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/goi-y/ap-dung", array_merge($ap, ['rev' => $rev - 1]))->assertOk()->assertJsonPath('stale', true);
    }

    public function test_festival_overlapping_trip_dates_is_suggested_with_trip_day(): void
    {
        [$id] = $this->trip();
        DB::table('tbl_events')->insert([
            ['slug' => 'hoi-hoa', 'name' => 'Hội hoa xuân', 'destinationId' => $this->hn, 'isLunar' => 0, 'solarMonth' => 3, 'solarDay' => 4, 'durationDays' => 2, 'reviewStatus' => 'published', 'description' => 'Hoa'],
            ['slug' => 'trung-thu', 'name' => 'Hội trăng rằm', 'destinationId' => $this->hn, 'isLunar' => 0, 'solarMonth' => 9, 'solarDay' => 15, 'durationDays' => 1, 'reviewStatus' => 'published', 'description' => 'x'],
        ]);
        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/goi-y", ['intent' => 'festival'])->assertOk()->json();
        $this->assertSame(['Hội hoa xuân'], array_column($r['cards'], 'title'));
        $this->assertSame('Trùng ngày 2 của chuyến', $r['cards'][0]['tags'][0]['t']);
        $this->assertSame(2, $r['cards'][0]['actions'][0]['apply']['toDay']);
    }

    public function test_storm_and_travel_alert_produce_change_suggestions(): void
    {
        $this->storm = true;
        [$id, $view] = $this->trip();
        $alt = $this->place('Bảo tàng dự phòng', 21.031, 105.851, 'museum');
        DB::table('tbl_travel_alerts')->insert(['destinationId' => $this->hn, 'kind' => 'traffic', 'level' => 'warn', 'title' => 'Cấm đường phố đi bộ',
            'detail' => 'Phân luồng sự kiện.', 'startsOn' => '2027-03-04', 'endsOn' => '2027-03-04', 'isActive' => 1]);
        DB::table('tbl_travel_alerts')->insert(['destinationId' => $this->hn, 'kind' => 'closure', 'level' => 'bad', 'title' => 'Đã hết hạn',
            'startsOn' => '2027-01-01', 'endsOn' => '2027-01-02', 'isActive' => 1]);

        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/goi-y", ['intent' => 'alerts'])->assertOk()->json();
        $storm = collect($r['cards'])->first(fn($c) => str_contains($c['id'], 'weather.storm'));
        $this->assertNotNull($storm, 'có cảnh báo dông ngày 1');
        $this->assertSame(1, $storm['day']);
        $rep = collect($storm['actions'])->first(fn($a) => ($a['apply']['op'] ?? null) === 'replace');
        $this->assertSame($alt, $rep['apply']['refId']);
        $move = collect($storm['actions'])->first(fn($a) => ($a['apply']['op'] ?? null) === 'move');
        $this->assertSame(2, $move['apply']['toDay'], 'dời sang ngày khô ráo');
        $travel = collect($r['cards'])->first(fn($c) => $c['title'] === 'Cấm đường phố đi bộ');
        $this->assertSame(2, $travel['day']);
        $this->assertNull(collect($r['cards'])->firstWhere('title', 'Đã hết hạn'));

        $p = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban/goi-y/ap-dung", $rep['apply'] + ['rev' => $this->rev($id)])->assertOk()->json();
        $this->assertSame('proposal', $p['kind']);
        $this->assertSame('replace', $p['changes'][0]['op']);
        $this->assertSame('Bảo tàng dự phòng', $p['changes'][0]['with']['name']);
    }

    public function test_other_user_cannot_use_suggestions(): void
    {
        [$id] = $this->trip();
        $other = DB::table('tbl_users')->insertGetId(['username' => 'sg2', 'email' => 'sg2@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->withSession(['userId' => $other, 'username' => 'sg2'])->postJson("/len-ke-hoach/$id/la-ban/goi-y", ['intent' => 'food'])->assertNotFound();
    }
}
