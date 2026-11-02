<?php

namespace Tests\Feature;

use App\Services\Ai\Llm\FakeLlm;
use App\Services\Ai\Llm\StructuredLlm;
use App\Services\Planner\TripPlanner;
use App\Services\Rag\ChunkBuilder;
use App\Services\Rag\ChunkIndexer;
use App\Services\Rag\Embedding\EmbedderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gói 3: F5 "Hỏi về chuyến" trong ngăn La bàn + tab "AI La bàn" ở trang nhật ký admin.
 * F5 không cần LLM: tắt LLM thì AskService trả lời bằng trích đoạn nguyên văn của nguồn (dữ kiện chuyến + cẩm nang).
 */
class AiAskAndLogTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private array $s;
    private int $hn;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ai.enabled' => true, 'ai.features.copilot' => true, 'ai.features.ask' => true, 'rag.embed.driver' => 'local', 'rag.llm.driver' => 'none',
            'core_destinations.slugs' => []]);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'Thủ đô.', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'K', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function trip(): int
    {
        $id = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2026-11-16', 'budget' => null, 'destinationId' => $this->hn]);
        $items = [
            ['type' => 'note', 'label' => 'Văn Miếu', 'day' => 1, 'lat' => 21.028, 'lng' => 105.836, 'source' => 'link'],
            ['type' => 'note', 'label' => 'Công viên nước Hồ Tây', 'day' => 2, 'lat' => 21.07, 'lng' => 105.82, 'source' => 'link'],
        ];
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2, 'startDate' => '2026-11-16', 'items' => $items])->assertOk();

        return $id;
    }

    public function test_question_is_answered_from_trip_facts_without_llm_and_never_calls_copilot(): void
    {
        $id = $this->trip();
        $fake = new FakeLlm(['copilot' => ['action' => 'reply', 'say' => 'không được gọi']]);
        $this->app->instance(StructuredLlm::class, $fake);
        $rev = DB::table('tbl_trips')->where('tripId', $id)->value('rev');

        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban", ['text' => 'Ngày 2 có gì?'])->assertOk()->json();

        $this->assertSame('answer', $r['kind']);
        $this->assertSame(0, $fake->calls, 'câu hỏi không đi qua Copilot');
        $this->assertStringContainsString('Công viên nước Hồ Tây', $r['say'][0]['t'], 'trích đoạn từ dữ kiện chuyến');
        $days = array_values(array_filter(array_column($r['sources'], 'day')));
        $this->assertContains(2, $days, 'nguồn trỏ về Ngày 2 trong lịch');
        $this->assertNotContains(1, $days, 'hỏi "ngày 2" thì không kèm lịch ngày 1');
        $this->assertStringNotContainsString('Văn Miếu', $r['say'][0]['t']);
        $this->assertSame($rev, DB::table('tbl_trips')->where('tripId', $id)->value('rev'), 'hỏi không đổi lịch');
        $log = DB::table('tbl_chat_logs')->first();
        $this->assertSame('trip', $log->mode);
        $this->assertSame('extractive', $log->engine);
    }

    public function test_question_uses_guide_index_scoped_to_trip_destination(): void
    {
        $cat = DB::table('tbl_guide_categories')->insertGetId(['slug' => 'chuan-bi', 'name' => 'Chuẩn bị', 'displayOrder' => 1]);
        DB::table('tbl_guides')->insert(['slug' => 'ao-mua', 'categoryId' => $cat, 'title' => 'Mang áo mưa khi đi du lịch', 'updatedOn' => '2026-10-01',
            'body' => "## Áo mưa\nMùa mưa nên mang áo mưa mỏng gọn nhẹ và túi chống nước cho điện thoại.", 'license' => 'Tự viết', 'reviewStatus' => 'published']);
        app(ChunkIndexer::class)->sync(ChunkBuilder::make()->build(), app(EmbedderFactory::class)->make('local'));
        $id = $this->trip();

        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban", ['text' => 'Có cần mang áo mưa không?'])->assertOk()->json();

        $this->assertSame('answer', $r['kind']);
        $this->assertTrue(collect($r['sources'])->contains(fn($s) => !$s['trip'] && str_contains($s['title'], 'áo mưa')), 'có nguồn cẩm nang');
    }

    public function test_edit_request_still_goes_to_copilot(): void
    {
        $id = $this->trip();
        $fake = new FakeLlm(['copilot' => ['action' => 'reply', 'say' => 'Bạn muốn chuyển mục nào?']]);
        $this->app->instance(StructuredLlm::class, $fake);

        $r = $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban", ['text' => 'Thêm quán cà phê được không?'])->assertOk()->json();

        $this->assertSame('reply', $r['kind']);
        $this->assertSame(1, $fake->calls);
    }

    public function test_ask_can_be_turned_off(): void
    {
        config(['ai.features.ask' => false]);
        $id = $this->trip();
        $this->app->instance(StructuredLlm::class, $fake = new FakeLlm(['copilot' => ['action' => 'reply', 'say' => 'ok']]));
        $this->withSession($this->s)->postJson("/len-ke-hoach/$id/la-ban", ['text' => 'Ngày 2 có gì?'])->assertOk()->assertJson(['kind' => 'reply']);
        $this->assertSame(1, $fake->calls);
    }

    public function test_admin_ai_tab_shows_acceptance_and_exports_csv(): void
    {
        $now = now();
        $row = fn($f, $e, $o, $ms = 1000, $err = null) => ['feature' => $f, 'engine' => $e, 'outcome' => $o, 'promptVersion' => 'x', 'inputChars' => 10, 'latencyMs' => $ms,
            'inputTokens' => 100, 'outputTokens' => 20, 'error' => $err, 'createdAt' => $now];
        DB::table('tbl_ai_runs')->insert([$row('copilot', 'llm', 'applied'), $row('copilot', 'llm', 'applied'), $row('copilot', 'llm', 'applied'),
            $row('copilot', 'llm', 'skipped'), $row('brief', 'rules', 'fallback', 2), $row('brief', 'llm', 'ok'),
            $row('explain', 'llm', 'ok', 900, '=HYPERLINK("x")')]);
        $this->get('/admin/chat-logs/ai')->assertRedirect(route('admin.login'));
        $admin = DB::table('tbl_admin')->insertGetId(['userName' => 'ad', 'passWord' => 'x', 'email' => 'ad@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'admin']);

        $this->withSession(['adminId' => $admin])->get('/admin/chat-logs/ai')->assertOk()
            ->assertSee('F3 Copilot')->assertSee('75%')->assertSee('(3/4, chờ 0)', false)->assertSee('50%');
        $this->withSession(['adminId' => $admin])->get('/admin/chat-logs/ai?feature=brief&outcome=fallback')->assertOk()->assertSee('chế độ cơ bản');
        $this->withSession(['adminId' => $admin])->get('/admin/chat-logs')->assertOk()->assertSee('AI La bàn (F1 · F2 · F3)');
        $csv = $this->withSession(['adminId' => $admin])->get('/admin/chat-logs/ai/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('copilot,llm,applied', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'chống CSV injection');
    }
}
