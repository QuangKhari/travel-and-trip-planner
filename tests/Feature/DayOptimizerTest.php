<?php

namespace Tests\Feature;

use App\Services\Planner\Route\DayOptimizer;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt D (AR1–AR5): tối ưu thứ tự ngày theo lịch. */
class DayOptimizerTest extends TestCase
{
    use RefreshDatabase;

    private function it(string $name, float $dLng, ?array $hours = null, array $o = []): array
    {
        return $o + ['type' => 'note', 'refId' => null, 'name' => $name, 'kind' => 'museum', 'lat' => 11.94, 'lng' => 108.43 + $dLng,
            'durationMin' => 60, 'startMin' => null, 'slot' => null, 'hours' => $hours];
    }

    private function route(): RouteService
    {
        config(['planner.route.provider' => 'haversine']);

        return RouteService::make();
    }

    public function test_fixes_a_closing_time_violation_that_distance_only_ordering_keeps(): void
    {
        // A – B – C nằm trên một đường: theo km thứ tự A, B, C là ngắn nhất, nhưng C đóng cửa lúc 11:00
        $items = [$this->it('A', 0), $this->it('B', 0.01), $this->it('C', 0.02, ['1' => [[480, 660]]])];
        $opt = new DayOptimizer(1, $items, $this->route(), null, 1, null);
        $before = $opt->evaluate([0, 1, 2]);
        $this->assertGreaterThan(0, $before['hours']);

        $r = $opt->optimize(true);
        $this->assertTrue($r['changed']);
        $this->assertSame([0, 2, 1], $r['order']);
        $this->assertSame(0, $r['after']['hours'] + $r['after']['hard']);
        $this->assertLessThanOrEqual($r['before']['cost'], $r['after']['cost']);

        $x = (new DayOptimizer(1, $items, $this->route(), null, 1, null))->exact(true);
        $this->assertEqualsWithDelta($x['after']['cost'], $r['after']['cost'], 0.01);
    }

    public function test_reduces_travel_and_never_moves_anchored_items(): void
    {
        // thứ tự nhập zig-zag; mục 2 có giờ cố định, mục 4 đã khóa: phải nằm yên
        $items = [$this->it('Đầu', 0), $this->it('Xa', 0.08), $this->it('Cố định', 0.02, null, ['startMin' => 720]), $this->it('Gần', 0.01),
            $this->it('Khóa', 0.05, null, ['locked' => true]), $this->it('Giữa', 0.04), $this->it('Cuối', 0.09)];
        $opt = new DayOptimizer(1, $items, $this->route(), null, null, null);
        $r = $opt->optimize(true);
        $this->assertTrue($r['changed']);
        $this->assertLessThan($r['before']['travelMin'], $r['after']['travelMin']);
        foreach ([0, 2, 4] as $slot) {
            $this->assertSame($slot, $r['order'][$slot], "mục neo ở vị trí $slot bị đổi");
        }
        $this->assertSame(range(0, 6), (fn($a) => (sort($a) ? $a : $a))($r['order']));   // vẫn đủ mục, không trùng
    }

    public function test_hotel_start_frees_the_first_item_and_counts_legs(): void
    {
        $items = [$this->it('Xa', 0.06), $this->it('Gần', 0.005), $this->it('Giữa', 0.03)];
        $hotel = ['name' => 'KS', 'lat' => 11.94, 'lng' => 108.43];
        $opt = new DayOptimizer(1, $items, $this->route(), null, null, ['from' => $hotel, 'to' => $hotel, 'arrive' => null, 'leave' => null, 'blocks' => []]);
        $this->assertFalse($opt->isFree($items[0], 0, true));
        $r = $opt->optimize(false);
        $this->assertSame(1, $r['order'][0]);   // xuất phát từ khách sạn thì nơi gần nhất đi trước
    }

    public function test_permutations_are_complete(): void
    {
        $all = iterator_to_array(DayOptimizer::permutations([1, 2, 3, 4]), false);
        $this->assertCount(24, $all);
        $this->assertCount(24, array_unique(array_map('json_encode', $all)));
    }

    public function test_endpoint_and_resolver_use_schedule_optimizer_on_saved_trip(): void
    {
        config(['planner.route.provider' => 'haversine']);
        $u = DB::table('tbl_users')->insertGetId(['username' => 'opt', 'email' => 'opt@example.com', 'password' => PasswordHasher::make('12345678'), 'fullName' => 'O', 'isActive' => 'y']);
        $trip = app(TripPlanner::class)->create($u, ['title' => 'Tối ưu', 'days' => 1, 'groupSize' => 2]);
        $note = fn($label, $dLng) => ['type' => 'note', 'day' => 1, 'label' => $label, 'lat' => 11.94, 'lng' => 108.43 + $dLng, 'durationMin' => 60];
        $this->withSession(['userId' => $u, 'username' => 'opt'])->postJson("/len-ke-hoach/$trip/luu", ['title' => 'Tối ưu', 'days' => 1, 'groupSize' => 2,
            'items' => [$note('Đầu', 0), $note('Xa', 0.08), $note('Gần', 0.01), $note('Giữa', 0.04), $note('Cuối', 0.09)]])->assertOk();

        $j = $this->postJson("/len-ke-hoach/$trip/toi-uu", ['day' => 1])->assertOk()->json();
        $this->assertSame('schedule', $j['method']);
        $this->assertTrue($j['changed']);
        $this->assertSame(0, $j['order'][0]);
        $this->assertLessThan($j['beforeMin'], $j['afterMin']);
        $this->assertCount(5, $j['order']);

        $r = $this->getJson("/len-ke-hoach/$trip/suc-khoe/xu-ly?code=day.optimize&day=1")->assertOk()->json();
        $this->assertSame('reorder', $r['options'][0]['changes'][0]['op']);
        $this->assertSame($j['order'], $r['options'][0]['changes'][0]['order']);
        $this->assertNotEmpty($r['options'][0]['after']);

        // kiểu cũ (gửi danh sách điểm) vẫn chạy
        $this->postJson("/len-ke-hoach/$trip/toi-uu", ['stops' => [['key' => 'a', 'lat' => 11.94, 'lng' => 108.43], ['key' => 'b', 'lat' => 11.95, 'lng' => 108.44]]])->assertOk()->assertJsonStructure(['order', 'changed']);
    }
}
