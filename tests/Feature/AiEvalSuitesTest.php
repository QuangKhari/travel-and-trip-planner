<?php

namespace Tests\Feature;

use App\Services\Ai\Llm\FakeLlm;
use App\Services\Ai\Llm\StructuredLlm;
use App\Services\Ai\PromptLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gói 4: bộ đánh giá (thí nghiệm A, B, F2) chạy được, đo bằng TripHealth và KHÔNG để lại gì trong CSDL;
 * lời nhắc được đóng băng theo prompt_version.
 */
class AiEvalSuitesTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private array $pl = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['core_destinations.slugs' => []]);
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'Thủ đô.', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        foreach ([['Văn Miếu', 21.0277, 105.8355, 'historic'], ['Hoàng thành Thăng Long', 21.035, 105.84, 'historic'], ['Bảo tàng Dân tộc học', 21.04, 105.80, 'museum'],
            ['Hồ Tây', 21.06, 105.82, 'viewpoint'], ['Nhà hát Lớn', 21.024, 105.857, 'attraction'], ['Cà phê Giảng', 21.034, 105.853, 'cafe'], ['Chùa Trấn Quốc', 21.048, 105.836, 'place_of_worship']] as $i => [$n, $la, $lo, $k]) {
            $this->pl[$n] = DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->hn, 'source' => 'manual', 'externalId' => $n, 'name' => $n,
                'category' => 'sight', 'kind' => $k, 'latitude' => $la, 'longitude' => $lo, 'rank' => $i + 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        }
        app(\App\Services\Planner\OpeningHours::class)->store('place', $this->pl['Bảo tàng Dân tộc học'], 'Tu-Su 08:30-17:30; Mo off');
    }

    protected function tearDown(): void
    {
        foreach (glob(base_path('storage/framework/testing/{plan,copilot,explain,plan-engine,plan-llm,b1,b2,ex}.{json,csv}'), GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function file(string $name, array $cases): string
    {
        $p = 'storage/framework/testing/' . $name . '.json';
        @mkdir(base_path('storage/framework/testing'), 0777, true);
        file_put_contents(base_path($p), json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE));

        return $p;
    }

    private function counts(): array
    {
        return [DB::table('tbl_trips')->count(), DB::table('tbl_trip_items')->count(), DB::table('tbl_ai_runs')->count(), DB::table('tbl_users')->count()];
    }

    public function test_plan_engine_vs_llm_with_data_measured_by_trip_health_and_rolled_back(): void
    {
        $before = $this->counts();
        $f = $this->file('plan', [['id' => 'p1', 'slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-07', 'interests' => ['culture'], 'pace' => 'normal']]);

        $this->artisan('ai:eval', ['--suite' => 'plan', '--mode' => 'engine', '--file' => $f, '--out' => 'storage/framework/testing/plan-engine.csv'])
            ->expectsOutputToContain('Tỷ lệ mục có thật trong CSDL')->assertSuccessful();
        $csv = file_get_contents(base_path('storage/framework/testing/plan-engine.csv'));
        $this->assertStringContainsString('hard', $csv);

        // A1b: LLM đặt bảo tàng vào Thứ Hai (đóng cửa) và đè giờ hai mục, kèm một khóa bịa
        $mu = 'place:' . $this->pl['Bảo tàng Dân tộc học'];
        $this->app->instance(StructuredLlm::class, new FakeLlm(['copilot' => ['days' => [
            ['day' => 1, 'items' => [['key' => $mu, 'start' => '09:00'], ['key' => 'place:' . $this->pl['Văn Miếu'], 'start' => '09:30'], ['key' => 'place:999999', 'start' => '12:00']]],
            ['day' => 2, 'items' => [['key' => 'place:' . $this->pl['Hồ Tây'], 'start' => '08:00']]],
        ]]]));
        config(['ai.driver' => 'openai']);
        $this->artisan('ai:eval', ['--suite' => 'plan', '--mode' => 'llm_data', '--file' => $f, '--out' => 'storage/framework/testing/plan-llm.csv'])->assertSuccessful();
        $rows = array_map('str_getcsv', file(base_path('storage/framework/testing/plan-llm.csv')));
        $h = array_flip(array_map(fn($x) => trim($x, "\xEF\xBB\xBF"), $rows[0]));
        $this->assertSame('0.75', $rows[1][$h['exist']], '3/4 khóa có thật');
        $this->assertGreaterThanOrEqual(1, (int) $rows[1][$h['hard']], 'TripHealth bắt lỗi cứng (đóng cửa Thứ Hai / chồng giờ)');

        $this->assertSame($before, $this->counts(), 'hộp cát: không để lại chuyến, mục, log, người dùng tạm');
    }

    public function test_copilot_unchecked_lets_hard_issue_through_but_verified_does_not(): void
    {
        $f = $this->file('copilot', [['id' => 'c1', 'group' => 'bay', 'trip' => ['slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-07', 'interests' => ['culture']],
            'request' => 'Cho {d1i1} và {d1i2} cùng bắt đầu lúc 10:00', 'expect' => ['kind' => 'any']]]);
        // làm đúng nguyên văn thì hai mục chồng giờ (time.overlap, lỗi cứng)
        $this->app->instance(StructuredLlm::class, new FakeLlm(['copilot' => ['action' => 'propose', 'say' => 'Dời.',
            'changes' => [['op' => 'time', 'item' => 'i1', 'startMin' => 600], ['op' => 'time', 'item' => 'i2', 'startMin' => 600]]]]));
        $before = $this->counts();

        $this->artisan('ai:eval', ['--suite' => 'copilot', '--mode' => 'unchecked', '--file' => $f, '--out' => 'storage/framework/testing/b1.csv'])->assertSuccessful();
        $this->artisan('ai:eval', ['--suite' => 'copilot', '--mode' => 'verified', '--file' => $f, '--out' => 'storage/framework/testing/b2.csv'])->assertSuccessful();
        $read = function ($p) {
            $rows = array_map('str_getcsv', file(base_path($p)));
            $h = array_flip(array_map(fn($x) => trim($x, "\xEF\xBB\xBF"), $rows[0]));

            return [$rows[1][$h['kind']], (int) $rows[1][$h['newHard']]];
        };
        [$k1, $hard1] = $read('storage/framework/testing/b1.csv');
        [$k2, $hard2] = $read('storage/framework/testing/b2.csv');
        $this->assertSame('proposal', $k1);
        $this->assertGreaterThanOrEqual(1, $hard1, 'B1: không có engine kiểm chứng thì lỗi cứng tới tay người dùng');
        $this->assertSame('reply', $k2, 'B2: bị chặn sau 2 lần sửa');
        $this->assertSame(0, $hard2);
        $this->assertSame($before, $this->counts());
    }

    public function test_explain_suite_reports_guard_pass(): void
    {
        $f = $this->file('explain', [['id' => 'e1', 'slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-08', 'interests' => ['culture'], 'pace' => 'normal']]);
        $this->app->instance(StructuredLlm::class, new FakeLlm(['explain' => ['headline' => '{{dest}} hai ngày', 'days' => [], 'tips' => [['text' => 'Vé chỉ 35.000đ', 'basedOn' => ['health:x']]]]]));
        $this->artisan('ai:eval', ['--suite' => 'explain', '--file' => $f, '--out' => 'storage/framework/testing/ex.csv'])
            ->expectsOutputToContain('Qua guard trọn vẹn')->assertSuccessful();
        $this->assertStringContainsString('judgeFaithful', file_get_contents(base_path('storage/framework/testing/ex.csv')));
    }

    public function test_explain_suite_shows_why_it_fell_back_to_template(): void
    {
        $f = $this->file('explain', [['id' => 'e1', 'slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-08', 'interests' => ['culture'], 'pace' => 'normal']]);
        $this->app->instance(StructuredLlm::class, new FakeLlm([], 'schema'));
        $this->artisan('ai:eval', ['--suite' => 'explain', '--file' => $f, '--out' => 'storage/framework/testing/ex.csv'])
            ->expectsOutputToContain('invalid: fake schema')
            ->expectsOutputToContain('Lý do lùi về câu mẫu')->assertSuccessful();
        $this->assertStringContainsString('llmError', file_get_contents(base_path('storage/framework/testing/ex.csv')));
    }

    public function test_copilot_hours_placeholder_picks_an_item_with_opening_hours(): void
    {
        // {h1} = mục có giờ mở cửa đúng thứ của ngày đó (ở đây chỉ Bảo tàng Dân tộc học có): bẫy dời 23:00 chắc chắn thành hours.late nếu làm theo
        $f = $this->file('copilot', [['id' => 'c1', 'group' => 'bay', 'trap' => true, 'trip' => ['slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-08', 'interests' => ['culture']],
            'request' => 'Dời {h1} sang 23:00', 'expect' => ['kind' => 'any']]]);
        $this->app->instance(StructuredLlm::class, new FakeLlm(['copilot' => ['action' => 'reply', 'say' => 'Bảo tàng đóng cửa lúc đó.']]));
        $this->artisan('ai:eval', ['--suite' => 'copilot', '--mode' => 'unchecked', '--file' => $f, '--out' => 'storage/framework/testing/b1.csv'])->assertSuccessful();
        $rows = array_map('str_getcsv', file(base_path('storage/framework/testing/b1.csv')));
        $h = array_flip(array_map(fn($x) => trim($x, "\xEF\xBB\xBF"), $rows[0]));
        if (isset($h['error'])) {
            $this->assertStringContainsString('giờ mở cửa', $rows[1][$h['error']], 'chuyến không có bảo tàng thì mẫu bị bỏ, có ghi lý do');

            return;
        }
        $this->assertSame('Dời Bảo tàng Dân tộc học sang 23:00', $rows[1][$h['request']]);
        $this->assertSame('1', $rows[1][$h['trap']]);
        $this->assertSame('Bảo tàng đóng cửa lúc đó.', $rows[1][$h['say']]);
    }

    public function test_provider_errors_are_excluded_not_counted_as_answers(): void
    {
        $f = $this->file('copilot', [['id' => 'c1', 'trip' => ['slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-07', 'interests' => ['culture']],
            'request' => 'Ngày 1 nhẹ hơn', 'expect' => ['kind' => 'proposal']]]);
        $this->app->instance(StructuredLlm::class, new FakeLlm([], 'error'));
        $this->artisan('ai:eval', ['--suite' => 'copilot', '--mode' => 'verified', '--file' => $f])
            ->expectsOutputToContain('lỗi: LLM: fake down')
            ->expectsOutputToContain('Không có mẫu nào chạy được')->assertFailed();
    }

    public function test_existing_result_is_never_overwritten(): void
    {
        $f = $this->file('plan', [['id' => 'p1', 'slug' => 'ha-noi', 'days' => 2, 'startDate' => '2026-12-07', 'interests' => ['culture'], 'pace' => 'normal']]);
        file_put_contents(base_path('storage/framework/testing/plan-engine.csv'), 'cũ');
        $this->artisan('ai:eval', ['--suite' => 'plan', '--mode' => 'engine', '--file' => $f, '--out' => 'storage/framework/testing/plan-engine.csv'])
            ->expectsOutputToContain('không ghi đè')->assertSuccessful();
        $this->assertSame('cũ', file_get_contents(base_path('storage/framework/testing/plan-engine.csv')));
        $this->assertFileExists(base_path('storage/framework/testing/plan-engine-2.csv'));
        @unlink(base_path('storage/framework/testing/plan-engine-2.csv'));
    }

    public function test_brief_with_every_call_failing_writes_no_csv(): void
    {
        $f = $this->file('plan', [['id' => 'b1', 'group' => 'full', 'q' => 'Đi Hà Nội 2 ngày', 'expect' => ['destination' => 'ha-noi']]]);
        config(['ai.enabled' => true, 'ai.features.brief' => true, 'ai.driver' => 'openai']);
        $this->app->instance(StructuredLlm::class, new FakeLlm([], 'error'));
        $this->artisan('ai:eval', ['--suite' => 'brief', '--engine' => 'llm', '--retry' => 0, '--file' => $f, '--out' => 'storage/framework/testing/ex.csv'])
            ->expectsOutputToContain('không ghi CSV')->assertFailed();
        $this->assertFileDoesNotExist(base_path('storage/framework/testing/ex.csv'));
    }

    public function test_brief_reports_which_model_answered(): void
    {
        $f = $this->file('plan', [['id' => 'b1', 'group' => 'full', 'q' => 'Đi Hà Nội 2 ngày', 'expect' => ['destination' => 'ha-noi']]]);
        config(['ai.enabled' => true, 'ai.features.brief' => true, 'ai.driver' => 'openai', 'ai.models.openai.brief' => 'gemini-x']);
        $this->app->instance(StructuredLlm::class, new FakeLlm(['brief' => ['destination' => 'ha-noi']]));
        $this->artisan('ai:eval', ['--suite' => 'brief', '--engine' => 'llm', '--file' => $f, '--out' => 'storage/framework/testing/ex.csv'])
            ->expectsOutputToContain('Model đã trả lời: fake × 1')->assertSuccessful();
        $this->assertStringContainsString('model', file_get_contents(base_path('storage/framework/testing/ex.csv')));
    }

    public function test_use_fallback_runs_only_the_backup_model(): void
    {
        $f = $this->file('plan', [['id' => 'b1', 'group' => 'full', 'q' => 'Đi Hà Nội 2 ngày', 'expect' => ['destination' => 'ha-noi']]]);
        config(['ai.enabled' => true, 'ai.features.brief' => true, 'ai.driver' => 'openai', 'ai.fallback.model' => 'openai/gpt-oss-120b']);
        $this->app->instance(StructuredLlm::class, new FakeLlm(['brief' => ['destination' => 'ha-noi']]));
        $this->artisan('ai:eval', ['--suite' => 'brief', '--engine' => 'llm', '--use-fallback' => true, '--file' => $f])
            ->expectsOutputToContain('model=openai/gpt-oss-120b')->assertSuccessful();
    }

    public function test_empty_suite_fails_with_a_clear_message_and_no_csv(): void
    {
        $f = $this->file('plan', []);
        $this->artisan('ai:eval', ['--suite' => 'brief', '--engine' => 'rules', '--file' => $f, '--out' => 'storage/framework/testing/ex.csv'])
            ->expectsOutputToContain('chưa có câu nào')->assertFailed();
        $this->assertFileDoesNotExist(base_path('storage/framework/testing/ex.csv'));
    }

    public function test_prompts_are_frozen_by_version(): void
    {
        foreach (PromptLock::status() as $f => $s) {
            $this->assertSame('khớp', $s['state'], "Lời nhắc $f đổi mà chưa tăng config('ai.prompt_version.$f') rồi chạy php artisan ai:prompts --lock");
        }
    }
}
