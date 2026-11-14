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
