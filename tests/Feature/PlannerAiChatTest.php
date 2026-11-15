<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trợ lý trò chuyện tạo chuyến và chip "đúng ý không": đọc câu nói thành checklist (không ghi gì), xem trước rồi mới tạo,
 * giới hạn đầu vào, AI tắt vẫn chạy bằng luật, đánh giá chỉ gắn vào chuyến người đó được xem.
 */
class PlannerAiChatTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => false]);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'b',
            'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'fullName' => 'A', 'isActive' => 'y']);
    }

    private function me(): static
    {
        return $this->withSession(['userId' => $this->uid, 'username' => 'a']);
    }

    public function test_chat_page_and_read_turn_sentences_into_slots_without_writing(): void
    {
        $this->me()->get('/len-ke-hoach/tro-ly')->assertOk();
        $trips = DB::table('tbl_trips')->count();

        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => ['Đi Đà Lạt 3 ngày tháng 12, 2 người, khoảng 5 triệu'], 'from' => '<b>Hà Nội</b>'])->assertOk()->json();
        $this->assertArrayHasKey('slots', $j);
        $this->assertArrayHasKey('next', $j);
        $this->assertContains('Ngân sách: 5.000.000đ', $j['extras']);
        $this->assertSame($trips, DB::table('tbl_trips')->count(), 'đọc câu không được tạo chuyến');

        // rỗng vẫn trả lời được, quá dài bị chặn
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => []])->assertOk()->assertJsonStructure(['slots', 'next']);
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => [str_repeat('a', 501)]])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['said' => array_fill(0, 13, 'x')])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/doc', ['suggest' => 1])->assertOk()->assertJsonStructure(['pick']);
    }

    public function test_create_shows_review_first_and_validates_adjustments(): void
    {
        $said = ['said' => ['Đà Lạt 2 ngày, 2 người lớn']];
        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said)->assertOk()->json();
        if (!empty($j['review'])) {
            $this->assertSame(2, $j['details']['days']);
            $this->assertSame(2, $j['details']['adults']);
        } else {
            $this->assertFalse($j['ok'], 'không có điểm đến đủ dữ liệu thì báo rõ, không tạo chuyến rỗng');
        }
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->uid)->where('kind', 'trip')->count());

        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['adults' => 40, 'children' => 20]])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['days' => 99]])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['hack' => 1]])->assertStatus(422);
    }

    public function test_preview_returns_real_draft_and_confirm_saves_that_draft(): void
    {
        $plan = ['days' => [['day' => 1, 'activityMin' => 150, 'travelMin' => 12, 'items' => [
            ['key' => 'place:1', 'type' => 'place', 'refId' => 1, 'name' => 'Hồ Xuân Hương', 'startMin' => 480, 'durationMin' => 60, 'travelMin' => 0, 'travelKm' => 0, 'slot' => 'morning',
                'reasons' => ['Hợp sở thích thiên nhiên', 'Mở cửa cả ngày', 'Gần trung tâm', 'Lý do thứ tư bị cắt'], 'lat' => 11.94, 'lng' => 108.44, 'coordsApprox' => false, 'priceMin' => 0],
            ['key' => 'place:2', 'type' => 'place', 'refId' => 2, 'name' => 'Vườn hoa', 'startMin' => 552, 'durationMin' => 90, 'travelMin' => 12, 'travelKm' => 3.1, 'slot' => 'morning',
                'reasons' => [], 'lat' => 11.95, 'lng' => 108.45, 'coordsApprox' => false]]]],
            'unplaced' => [['key' => 'place:3']], 'budget' => ['knownMin' => 100000, 'message' => 'x']];
        $result = ['ok' => true, 'destination' => ['id' => $this->dest, 'name' => 'Đà Lạt'], 'request' => ['startDate' => '2027-03-10'], 'plan' => $plan];
        $calls = 0;
        $saved = null;
        $mock = \Mockery::mock(\App\Services\Planner\PlannerService::class);
        $mock->shouldReceive('generate')->andReturnUsing(function () use (&$calls, $result) { $calls++; return $result; });
        $mock->shouldReceive('save')->andReturnUsing(function ($uid, $r) use (&$saved) {
            $saved = $r;
            return DB::table('tbl_trips')->insertGetId(['userId' => $uid, 'title' => 'Đà Lạt', 'days' => 1, 'groupSize' => 2, 'kind' => 'trip', 'createdAt' => now(), 'updatedAt' => now()]);
        });
        $this->app->instance(\App\Services\Planner\PlannerService::class, $mock);

        $said = ['said' => ['Đà Lạt 1 ngày, 2 người lớn, thích thiên nhiên']];
        $j = $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said)->assertOk()->json();
        $this->assertTrue($j['review']);
        $this->assertSame(2, $j['draft']['count']);
        $this->assertSame(12, $j['draft']['travelMin']);
        $this->assertSame('Vườn hoa', $j['draft']['days'][0]['items'][1]['name']);
        $this->assertCount(3, $j['draft']['days'][0]['items'][0]['reasons'], 'tối đa 3 lý do');
        $this->assertSame(1, $j['draft']['unplaced']);
        $this->assertSame(32, strlen($j['draftToken']));
        $this->assertSame(0, DB::table('tbl_trips')->where('userId', $this->uid)->count(), 'bản nháp chưa ghi gì');

        // xác nhận kèm mã nháp và cùng yêu cầu: lưu đúng bản đã xem, không xếp lại
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $j['draftToken']])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $calls);
        $this->assertSame($plan, $saved['plan']);
        // mã đã dùng thì hết hiệu lực; yêu cầu đổi (số ngày) thì xếp lại
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['confirmed' => true, 'draftToken' => $j['draftToken'], 'adjustments' => ['days' => 2]])->assertOk();
        $this->assertSame(2, $calls);
        // nhịp đi / sở thích sửa thẳng được, giá trị lạ bị chặn
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['pace' => 'relaxed', 'interests' => ['food']]])->assertOk();
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['pace' => 'sleep']])->assertStatus(422);
        $this->me()->postJson('/len-ke-hoach/tro-ly/tao', $said + ['adjustments' => ['interests' => ['hack']]])->assertStatus(422);
    }

    public function test_origin_city_is_not_taken_as_destination(): void
    {
        DB::table('tbl_destinations')->insert(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b', 'description' => 'x', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        \Illuminate\Support\Facades\Cache::flush();
        $i = app(\App\Services\Content\SearchIntent::class);
        $this->assertSame('da-lat', $i->parse('Mình đi Đà Lạt 3 ngày, xuất phát từ Hà Nội')['destination']['slug']);
        $this->assertSame('da-lat', $i->parse('từ Hà Nội bay vào Đà Lạt')['destination']['slug']);
        $this->assertSame('ha-noi', $i->parse('đi Hà Nội 2 ngày')['destination']['slug']);
    }

    public function test_brief_endpoint_works_with_ai_off(): void
    {
        $j = $this->me()->postJson('/len-ke-hoach/hieu-yeu-cau', ['text' => 'Đà Lạt 3 ngày cho 2 người'])->assertOk()->json();
        $this->assertTrue($j['ok']);
        $this->assertSame('rules', $j['engine']);
        $this->assertSame(3, (int) $j['prefill']['days']);
        $this->me()->postJson('/len-ke-hoach/hieu-yeu-cau', ['text' => ''])->assertStatus(422);
    }

    public function test_rate_stores_feedback_and_ignores_trips_user_cannot_see(): void
    {
        $other = DB::table('tbl_users')->insertGetId(['username' => 'b', 'email' => 'b@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        $foreign = DB::table('tbl_trips')->insertGetId(['userId' => $other, 'kind' => 'trip', 'title' => 'Của B', 'days' => 1, 'status' => 'planned', 'createdAt' => now()]);

        $this->me()->postJson('/la-ban/danh-gia', ['feature' => 'copilot', 'helpful' => false, 'reason' => 'too_generic', 'note' => '<script>x</script>Chung chung', 'tripId' => $foreign])
            ->assertOk()->assertJson(['ok' => true]);
        $f = DB::table('tbl_ai_feedback')->first();
        $this->assertNull($f->tripId, 'không gắn đánh giá vào chuyến của người khác');
        $this->assertSame('too_generic', $f->reason);
        $this->assertStringNotContainsString('<script>', $f->note);

        $this->me()->postJson('/la-ban/danh-gia', ['feature' => 'brief', 'helpful' => true, 'reason' => 'too_generic'])->assertOk();
        $this->assertNull(DB::table('tbl_ai_feedback')->orderByDesc('feedbackId')->value('reason'), 'đúng ý thì không lưu lý do');
        $this->me()->postJson('/la-ban/danh-gia', ['feature' => 'hack', 'helpful' => true])->assertStatus(422);
    }
}
