<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vòng thiết kế lại giao diện (lịch là phần chính): thứ tự khối trên trang chuyến, một câu hỏi ba lối vào, tên gọi AI thống nhất,
 * trang tour gọn (miền chỉ còn ở bộ lọc), giải thích đề xuất gập lại.
 */
class TimelineFirstUiTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => false, 'planner.weather.enabled' => false]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u', 'email' => 'u@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
    }

    public function test_trip_page_puts_timeline_before_secondary_blocks(): void
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Thử', 'days' => 2, 'groupSize' => 2, 'startDate' => '2027-03-10']);
        $html = $this->withSession(['userId' => $this->uid])->get("/len-ke-hoach/$id")->assertOk()->getContent();
        $this->assertStringNotContainsString('Lên kế hoạch dễ dàng hơn', $html);
        $days = strpos($html, 'id="tpDaysBox"');
        foreach (['id="aiStory"', 'id="tpHealth"'] as $after) {
            $this->assertGreaterThan($days, strpos($html, $after), $after . ' phải nằm sau lịch');
        }
        $this->assertLessThan($days, strpos($html, 'id="tpuxHealthBtn"'), 'nút tình trạng chuyến nằm ở thanh tiêu đề');
        $this->assertStringContainsString('Trợ lý hành trình · La bàn', $html);
        $this->assertStringContainsString('planner-focus.css', $html);
    }

    public function test_planner_index_offers_three_ways_to_start(): void
    {
        $html = $this->withSession(['userId' => $this->uid])->get('/len-ke-hoach')->assertOk()->getContent();
        $this->assertStringContainsString('Bạn muốn bắt đầu chuyến đi như thế nào?', $html);
        foreach (['Tôi đã biết đi đâu', 'Gợi ý điểm đến', 'Tạo lịch với AI'] as $t) {
            $this->assertStringContainsString($t, $html);
        }
        $this->assertStringNotContainsString('Để AI tạo giúp', $html);
    }

    public function test_tours_page_search_keeps_core_fields_only(): void
    {
        $html = $this->get('/tours')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="tvDomain"', $html);
        $this->assertStringNotContainsString('bl-tour-benefits', $html);
        $this->assertStringContainsString('data-filter="domain"', $html, 'miền vẫn lọc được ở thanh bên');
    }
}
