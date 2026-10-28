<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Giá tham khảo để ước tính chi phí khi người dùng chưa nhập (cấu hình, không lưu vào DB). */
class Week9CostReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_costs_are_sane_and_ordered(): void
    {
        $c = config('planner.costs');
        foreach (['meal', 'experience', 'lodging', 'transport'] as $k) {
            $this->assertLessThan($c[$k]['mid'], $c[$k]['low'], "$k low < mid");
            $this->assertLessThan($c[$k]['high'], $c[$k]['mid'], "$k mid < high");
            $this->assertGreaterThan(0, $c[$k]['low']);
        }
        $this->assertGreaterThanOrEqual(1, $c['mealsPerDay']);
        $this->assertGreaterThanOrEqual(1, $c['peoplePerRoom']);
        $this->assertLessThan($c['tierBudgetPerPersonDay']['mid'], $c['tierBudgetPerPersonDay']['low']);
    }

    public function test_editor_page_passes_reference_costs_to_the_script(): void
    {
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $id = app(TripPlanner::class)->create($uid, ['title' => 'Chuyến thử', 'days' => 3, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
        $this->withSession(['userId' => $uid, 'username' => 'u1'])->get("/len-ke-hoach/$id")->assertOk()
            ->assertSee('"costRef"', false)
            ->assertSee('"mealsPerDay":3', false)
            ->assertSee('id="tpCostBox"', false);
    }

    public function test_estimates_are_never_stored_with_the_trip(): void
    {
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $id = app(TripPlanner::class)->create($uid, ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
        $this->withSession(['userId' => $uid, 'username' => 'u1'])->postJson("/len-ke-hoach/$id/luu", ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'note', 'label' => 'Khách sạn Sen', 'day' => 1],
        ]])->assertOk();
        $this->assertNull(DB::table('tbl_trip_items')->where('tripId', $id)->value('cost'));
    }
}
