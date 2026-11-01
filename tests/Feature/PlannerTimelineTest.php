<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Timeline trong ngày: giờ bắt đầu cố định (startMin) và thời lượng ở lại (durationMin) được lưu, trả lại cho trang sửa; bố cục Timeline + Bản đồ. */
class PlannerTimelineTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function trip(): int
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội 2 ngày', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
    }

    private function food(): int
    {
        return DB::table('tbl_foods')->insertGetId(['slug' => 'pho-bo', 'name' => 'Phở bò', 'destinationId' => $this->hn, 'category' => 'bún phở',
            'description' => 'Mô tả đủ dài cho món phở', 'license' => 'Tự viết', 'reviewStatus' => 'published']);
    }

    public function test_start_time_and_duration_are_saved_and_returned_to_editor(): void
    {
        $id = $this->trip();
        $food = $this->food();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội 2 ngày', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'food', 'refId' => $food, 'day' => 1, 'startMin' => 690, 'durationMin' => 45],
            ['type' => 'note', 'label' => 'Khách sạn Sen', 'day' => 1, 'lat' => 21.03, 'lng' => 105.85, 'source' => 'link'],
        ]])->assertOk()->assertJson(['ok' => true]);

        $rows = DB::table('tbl_trip_items')->where('tripId', $id)->orderBy('orderNo')->get();
        $this->assertSame(690, (int) $rows[0]->startMin);
        $this->assertSame(45, (int) $rows[0]->durationMin);
        $this->assertNull($rows[1]->startMin);
        $this->assertNull($rows[1]->durationMin);

        $html = $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()->getContent();
        $this->assertStringContainsString('"startMin":690', $html);
        $this->assertStringContainsString('"durationMin":45', $html);
        $this->assertStringContainsString('"defDur":60', $html);          // món ăn: 60 phút mặc định (config planner.default_duration_min.food)
        $this->assertStringContainsString('"itemBudgetMin":480', $html);   // quỹ thời lượng các mục, suy ra từ PlannerRules
    }

    public function test_invalid_time_values_are_rejected(): void
    {
        $id = $this->trip();
        $food = $this->food();
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'X', 'days' => 2, 'groupSize' => 2, 'items' => [
            ['type' => 'food', 'refId' => $food, 'day' => 1, 'startMin' => 1500, 'durationMin' => 2],
        ]])->assertStatus(422)->assertJsonValidationErrors(['items.0.startMin', 'items.0.durationMin']);
    }

    public function test_default_duration_follows_planner_config(): void
    {
        $this->assertSame(120, TripPlanner::defaultDuration(['type' => 'place', 'kind' => 'viewpoint']));   // nhóm thiên nhiên
        $this->assertSame(90, TripPlanner::defaultDuration(['type' => 'place', 'kind' => 'museum']));       // nhóm văn hóa
        $this->assertSame(60, TripPlanner::defaultDuration(['type' => 'place', 'kind' => 'kind-moi']));     // chưa có nhóm
        $this->assertSame(30, TripPlanner::defaultDuration(['type' => 'transport']));
        $this->assertSame(240, TripPlanner::defaultDuration(['type' => 'tour']));
    }

    public function test_editor_uses_timeline_and_map_layout(): void
    {
        $id = $this->trip();
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()
            ->assertSee('id="tpDayNav"', false)
            ->assertSee('id="tpKpis"', false)
            ->assertSee('id="tpAddDrawer"', false)
            ->assertSee('id="tpMapCol"', false)
            ->assertSee('Bạn muốn thêm gì vào chuyến đi?')
            ->assertSee('Thông tin &amp; chia sẻ', false);
    }
}
