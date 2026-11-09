<?php

namespace Tests\Feature;

use App\Services\Planner\Route\HaversineProvider;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Planner Engine: RouteService (ước tính, thời gian di chuyển, quãng vòng, tối ưu có điểm neo), TripHealth (5 nhóm ràng buộc),
 * khóa vị trí (isLocked) và hợp đồng JSON của các API trình soạn (lưu, tối ưu, sức khỏe).
 */
class PlannerEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $other;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'Khải', 'isActive' => 'y']);
        $this->other = DB::table('tbl_users')->insertGetId(['username' => 'u2', 'email' => 'u2@example.com', 'password' => 'x', 'fullName' => 'Lan', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function trip(array $o = []): int
    {
        return app(TripPlanner::class)->create($this->uid, $o + ['title' => 'Hà Nội 2 ngày', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
    }

    private function note(string $label, int $day, ?float $lat = 21.03, ?float $lng = 105.85, array $o = []): array
    {
        return $o + ['type' => 'note', 'label' => $label, 'day' => $day, 'lat' => $lat, 'lng' => $lng, 'source' => 'link'];
    }

    private function save(int $id, array $items, array $o = [])
    {
        return $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $o + ['title' => 'Hà Nội 2 ngày', 'days' => 2, 'groupSize' => 2, 'items' => $items]);
    }

    private function route(): RouteService
    {
        return new RouteService(new HaversineProvider(1.3, 40));
    }

    /* ---------------- RouteService ---------------- */

    public function test_travel_time_skips_same_area_and_uses_road_factor(): void
    {
        $r = $this->route();
        $t = $r->travelTime([[21.0, 105.0], [21.001, 105.001], [21.0, 105.1]]);   // điểm 2 cách điểm 1 ~150 m: bỏ qua
        $this->assertCount(1, $t['legs']);
        $this->assertSame(1, $t['legs'][0]['from']);
        $straight = HaversineProvider::straightKm([21.001, 105.001], [21.0, 105.1]);
        $this->assertEqualsWithDelta($straight * 1.3, $t['km'], 0.05);
        $this->assertSame((int) round($t['km'] / 40 * 60), $t['min']);
        $one = $r->travelTime([[21.0, 105.0]]);
        $this->assertSame(['legs' => [], 'km' => 0.0, 'min' => 0], array_intersect_key($one, ['legs' => 1, 'km' => 1, 'min' => 1]));
        $this->assertSame('verified', $one['confidence']);   // không có chặng nào để nghi ngờ
        $this->assertSame('estimated', $t['legs'][0]['status']);
        $this->assertSame('estimated', $t['confidence']);
    }

    public function test_detour_picks_cheapest_gap_and_tail(): void
    {
        $r = $this->route();
        $route = [[21.0, 105.0], [21.0, 105.1]];
        $this->assertSame(0, $r->detour($route, 21.0, 105.05)['after']);
        $tail = $r->detour($route, 21.0, 105.12);                         // sau điểm cuối: đi tiếp một chiều
        $this->assertSame(1, $tail['after']);
        $this->assertEqualsWithDelta(HaversineProvider::straightKm([21.0, 105.1], [21.0, 105.12]) * 1.3, $tail['detourKm'], 0.1);
    }

    public function test_optimize_reorders_free_stops_but_keeps_anchors(): void
    {
        $r = $this->route();
        // A(neo, khách sạn) -> C (xa) -> B (gần) -> D: thứ tự hợp lý là A B C D
        $stops = [
            ['key' => 'A', 'lat' => 21.0, 'lng' => 105.00, 'anchor' => true],
            ['key' => 'C', 'lat' => 21.0, 'lng' => 105.20, 'anchor' => false],
            ['key' => 'B', 'lat' => 21.0, 'lng' => 105.10, 'anchor' => false],
            ['key' => 'D', 'lat' => 21.0, 'lng' => 105.30, 'anchor' => false],
        ];
        $o = $r->optimize($stops);
        $this->assertSame(['A', 'B', 'C', 'D'], $o['order']);
        $this->assertTrue($o['changed']);
        $this->assertGreaterThan(0, $o['savedKm']);
        $this->assertLessThan($o['beforeKm'], $o['afterKm']);

        // C bị neo (giờ cố định / khóa) thì không được di chuyển khỏi chỗ thứ 2
        $stops[1]['anchor'] = true;
        $o2 = $r->optimize($stops);
        $this->assertSame('C', $o2['order'][1]);
        $this->assertSame('A', $o2['order'][0]);

        // điểm không có tọa độ giữ nguyên chỗ
        $o3 = $r->optimize([['key' => 'X', 'lat' => null, 'lng' => null], ['key' => 'Y', 'lat' => 21.0, 'lng' => 105.0], ['key' => 'Z', 'lat' => 21.0, 'lng' => 105.1]]);
        $this->assertSame('X', $o3['order'][0]);
    }

    /* ---------------- API: tối ưu ---------------- */

    public function test_optimize_endpoint_contract_and_access(): void
    {
        $id = $this->trip();
        $body = ['keepFirst' => true, 'stops' => [
            ['key' => 'h', 'lat' => 21.0, 'lng' => 105.00],
            ['key' => 'c', 'lat' => 21.0, 'lng' => 105.20],
            ['key' => 'b', 'lat' => 21.0, 'lng' => 105.10],
        ]];
        $j = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/toi-uu", $body)->assertOk()
            ->assertJsonStructure(['order', 'beforeKm', 'afterKm', 'savedKm', 'savedMin', 'changed', 'moved', 'provider'])->json();
        $this->assertSame(['h', 'b', 'c'], $j['order']);
        $this->assertSame('haversine', $j['provider']);
        // không ghi gì vào chuyến: tối ưu chỉ là đề xuất xem trước
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->count());

        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/toi-uu", ['stops' => [['key' => 'a']]])->assertStatus(422);
        $this->withSession(['userId' => $this->other, 'username' => 'u2'])->postJson("/len-ke-hoach/$id/toi-uu", $body)->assertNotFound();
    }

    /* ---------------- TripHealth ---------------- */

    public function test_health_detects_overlap_travel_conflict_and_overload(): void
    {
        $id = $this->trip();
        $items = [
            $this->note('Bảo tàng', 1, 21.03, 105.85, ['startMin' => 600, 'durationMin' => 120]),   // 10:00–12:00
            $this->note('Chùa', 1, 21.03, 105.85, ['startMin' => 630]),                             // 10:30: trùng giờ
            $this->note('Hồ', 2, 21.00, 105.80, ['startMin' => 540, 'durationMin' => 60]),          // 09:00–10:00
            $this->note('Làng gốm', 2, 21.00, 105.95, ['startMin' => 600]),                         // 10:00 nhưng cần ~30 phút đi đường
        ];
        $this->save($id, $items)->assertOk();
        $j = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()
            ->assertJsonStructure(['score', 'level', 'provider', 'days' => [['day', 'count', 'loadPct', 'level', 'activityMin', 'travelMin', 'travelKm',
                'mealMin', 'bufferMin', 'freeMin', 'availableMin', 'occupiedMin', 'start', 'end', 'cost', 'rows']], 'checks' => [['group', 'level', 'code', 'text', 'day', 'action']]])
            ->json();
        $codes = array_column($j['checks'], 'code');
        $this->assertContains('time.overlap', $codes);
        $this->assertContains('time.travel', $codes);
        $this->assertSame('bad', $j['level']);
        $this->assertLessThan(100, $j['score']);
        $this->assertSame('10:00', $j['days'][0]['rows'][0]['start']);
        $this->assertTrue($j['days'][0]['rows'][0]['fixed']);
        $this->assertSame(600, $j['days'][0]['availableMin']);

        // 11 mục 60 phút trong một ngày: vượt 600 phút khả dụng
        $many = [];
        for ($i = 0; $i < 11; $i++) {
            $many[] = $this->note('Điểm ' . $i, 1);
        }
        $this->save($id, $many)->assertOk();
        $j2 = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $over = collect($j2['checks'])->firstWhere('code', 'capacity.overload');
        $this->assertNotNull($over);
        $this->assertSame(1, $over['day']);
        $this->assertSame('balance', $over['action']);
        $this->assertGreaterThan(100, $j2['days'][0]['loadPct']);
    }

    public function test_health_budget_over_and_access(): void
    {
        $id = $this->trip(['budget' => 1000000]);
        $this->save($id, [$this->note('Khách sạn', 1, 21.03, 105.85, ['cost' => 1500000])], ['budget' => 1000000])->assertOk();
        $codes = array_column($this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json('checks'), 'code');
        $this->assertContains('budget.over', $codes);

        $this->withSession(['userId' => $this->other, 'username' => 'u2'])->getJson("/len-ke-hoach/$id/suc-khoe")->assertNotFound();
        $this->assertFalse($this->getJson("/len-ke-hoach/$id/suc-khoe")->isOk());   // chưa đăng nhập: không lộ dữ liệu
    }

    public function test_health_of_empty_trip_is_perfect(): void
    {
        $id = $this->trip();
        $j = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/suc-khoe")->assertOk()->json();
        $this->assertSame(100, $j['score']);
        $this->assertSame('ok', $j['level']);
        $this->assertCount(2, $j['days']);
    }

    /* ---------------- khóa vị trí + hợp đồng lưu ---------------- */

    public function test_locked_flag_is_saved_and_returned(): void
    {
        $id = $this->trip();
        $this->save($id, [$this->note('Khách sạn', 1, 21.03, 105.85, ['locked' => true]), $this->note('Quán', 1)])->assertOk()
            ->assertJsonStructure(['ok', 'savedAt', 'rev', 'contentWarnings', 'advice', 'fit', 'rain']);
        $rows = DB::table('tbl_trip_items')->where('tripId', $id)->orderBy('orderNo')->get();
        $this->assertSame(1, (int) $rows[0]->isLocked);
        $this->assertSame(0, (int) $rows[1]->isLocked);
        $html = $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->getContent();
        $this->assertStringContainsString('"locked":true', $html);
        $this->assertStringContainsString('suc-khoe', $html);   // trình soạn biết đường dẫn API sức khỏe
        $this->assertStringContainsString('Đã lưu', $html);

        $this->save($id, [$this->note('X', 1, null, null, ['locked' => 'abc'])])->assertStatus(422);
    }
}
