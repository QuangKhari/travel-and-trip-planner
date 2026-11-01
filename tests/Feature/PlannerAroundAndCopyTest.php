<?php

namespace Tests\Feature;

use App\Services\Planner\RouteExplorer;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Khám phá quanh tuyến, nhân bản chuyến, loại chi phí (costType) và trang "Chuyến đi của tôi" dạng thẻ. */
class PlannerAroundAndCopyTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private int $other;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'Khải', 'isActive' => 'y']);
        $this->other = DB::table('tbl_users')->insertGetId(['username' => 'u2', 'email' => 'u2@example.com', 'password' => 'x', 'fullName' => 'Lan', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function place(string $name, string $kind, float $lat, float $lng, string $status = 'published'): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => $status, 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    private function trip(array $o = []): int
    {
        return app(TripPlanner::class)->create($this->uid, $o + ['title' => 'Hà Nội 2 ngày', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null, 'destinationId' => $this->hn]);
    }

    public function test_measure_detour_and_insert_position(): void
    {
        $route = [[21.0, 105.0], [21.0, 105.1], [21.0, 105.2]];
        $m = RouteExplorer::measure($route, 21.0, 105.15);           // nằm trên đoạn 2 -> 3
        $this->assertSame(1, $m['after']);
        $this->assertEqualsWithDelta(0.0, $m['offKm'], 0.01);
        $this->assertEqualsWithDelta(0.0, $m['detourKm'], 0.05);
        $m2 = RouteExplorer::measure($route, 21.01, 105.05);         // lệch ~1,1 km khỏi đoạn 1 -> 2
        $this->assertSame(0, $m2['after']);
        $this->assertEqualsWithDelta(1.11, $m2['offKm'], 0.05);
        $this->assertEqualsWithDelta(0.3, $m2['detourKm'], 0.05);   // lệch giữa đoạn dài: quãng vòng thêm nhỏ hơn nhiều so với 2 × độ lệch
        $one = RouteExplorer::measure([[21.0, 105.0]], 21.0, 105.01);   // chỉ một điểm: đi và về
        $this->assertSame(0, $one['after']);
        $this->assertEqualsWithDelta(2 * 1.04 * (float) config('planner.route.factor'), $one['detourKm'], 0.1);
    }

    public function test_around_returns_published_places_near_route_sorted_by_detour(): void
    {
        $near = $this->place('Cà phê sát tuyến', 'cafe', 21.0290, 105.8460);
        $mid = $this->place('Bảo tàng lệch chút', 'museum', 21.0330, 105.8480);
        $this->place('Xa tuốt', 'museum', 21.1200, 105.9500);
        $this->place('Bản nháp', 'cafe', 21.0289, 105.8461, 'draft');
        $have = $this->place('Đã có', 'restaurant', 21.0287, 105.8470);
        $id = $this->trip();
        $pts = '21.0285,105.8400;21.0285,105.8550';

        $j = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/quanh-tuyen?pts=$pts&km=1&have[]=place:$have")->assertOk()->json();
        $names = array_column($j['results'], 'name');
        $this->assertSame(['Cà phê sát tuyến', 'Bảo tàng lệch chút'], $names);
        $this->assertSame($near, $j['results'][0]['refId']);
        $this->assertSame(0, $j['results'][0]['after']);
        $this->assertLessThan($j['results'][1]['detourKm'], $j['results'][0]['detourKm']);
        $this->assertSame('great', $j['results'][0]['detourLevel']['key']);          // sát tuyến: <= 5 phút đi vòng
        $this->assertSame(app(\App\Services\Planner\Route\RouteService::class)->minutesFor($j['results'][1]['detourKm']), $j['results'][1]['detourMin']);

        $cafe = $this->withSession($this->s)->getJson("/len-ke-hoach/$id/quanh-tuyen?pts=$pts&km=1&group=cafe")->assertOk()->json('results');
        $this->assertSame(['Cà phê sát tuyến'], array_column($cafe, 'name'));
        $this->assertSame($mid, $this->withSession($this->s)->getJson("/len-ke-hoach/$id/quanh-tuyen?pts=$pts&km=1&group=sight")->json('results.0.refId'));
    }

    public function test_around_requires_edit_access_and_valid_points(): void
    {
        $id = $this->trip();
        $this->withSession(['userId' => $this->other, 'username' => 'u2'])->getJson("/len-ke-hoach/$id/quanh-tuyen?pts=21,105")->assertNotFound();
        $this->withSession($this->s)->getJson("/len-ke-hoach/$id/quanh-tuyen?pts=abc")->assertStatus(422);
    }

    public function test_duplicate_copies_trip_and_items_into_new_trip_of_current_user(): void
    {
        $p = $this->place('Hồ Gươm', 'attraction', 21.0287, 105.8522);
        $id = $this->trip();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội 2 ngày', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'place', 'refId' => $p, 'day' => 1, 'startMin' => 480, 'cost' => 0],
            ['type' => 'note', 'label' => 'Khách sạn Sen', 'day' => 1, 'cost' => 900000, 'costType' => 'actual'],
        ]])->assertOk();
        DB::table('tbl_trip_members')->insert(['tripId' => $id, 'userId' => $this->other, 'role' => 'view']);

        // người được mời (chỉ xem) nhân bản được thành chuyến của chính họ
        $r = $this->withSession(['userId' => $this->other, 'username' => 'u2'])->post("/len-ke-hoach/$id/nhan-ban");
        $copy = DB::table('tbl_trips')->where('userId', $this->other)->first();
        $r->assertRedirect(route('planner.edit', ['id' => $copy->tripId]));
        $this->assertSame('Hà Nội 2 ngày (bản sao)', $copy->title);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->shareToken);
        $this->assertSame(2, DB::table('tbl_trip_items')->where('tripId', $copy->tripId)->count());
        $this->assertSame(480, (int) DB::table('tbl_trip_items')->where('tripId', $copy->tripId)->where('refType', 'place')->value('startMin'));
        $this->assertSame(0, DB::table('tbl_trip_members')->where('tripId', $copy->tripId)->count());
        $this->assertSame(2, DB::table('tbl_trip_items')->where('tripId', $id)->count());   // chuyến gốc giữ nguyên

        // người lạ không nhân bản được
        $stranger = DB::table('tbl_users')->insertGetId(['username' => 'u3', 'email' => 'u3@example.com', 'password' => 'x', 'fullName' => 'X', 'isActive' => 'y']);
        $this->withSession(['userId' => $stranger, 'username' => 'u3'])->post("/len-ke-hoach/$id/nhan-ban")->assertNotFound();
    }

    public function test_cost_type_is_saved_only_with_a_cost(): void
    {
        $id = $this->trip();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'X', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'note', 'label' => 'Vé tàu', 'day' => 1, 'cost' => 500000, 'costType' => 'actual'],
            ['type' => 'note', 'label' => 'Ăn tối', 'day' => 1, 'cost' => 300000, 'costType' => 'estimate'],
            ['type' => 'note', 'label' => 'Chưa có giá', 'day' => 2, 'costType' => 'actual'],
        ]])->assertOk();
        $t = DB::table('tbl_trip_items')->where('tripId', $id)->pluck('costType', 'label');
        $this->assertSame(['Vé tàu' => 'actual', 'Ăn tối' => 'estimate', 'Chưa có giá' => null], $t->all());
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'X', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'note', 'label' => 'Sai', 'cost' => 1, 'costType' => 'free'],
        ]])->assertStatus(422);
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->assertSee('"costType":"actual"', false)->assertSee('id="tpSide"', false)->assertSee('quanh-tuyen', false);
    }

    public function test_trip_list_shows_cards_filters_and_three_step_form(): void
    {
        $this->trip(['title' => 'Hà Nội sắp đi', 'startDate' => now()->addDays(10)->format('Y-m-d')]);
        $this->trip(['title' => 'Chưa có ngày', 'destinationId' => null]);
        $this->withSession($this->s)->get('/len-ke-hoach')->assertOk()
            ->assertSee('Hà Nội sắp đi')->assertSee('Còn 10 ngày')->assertSee('data-phase="upcoming"', false)->assertSee('data-phase="planning"', false)
            ->assertSee('Đi đâu?')->assertSee('Khi nào?')->assertSee('Đi cùng ai?')
            ->assertSee('Tạo chuyến đi mới')->assertSee('Bắt đầu lên kế hoạch')->assertSee('nhan-ban', false);
    }
}
