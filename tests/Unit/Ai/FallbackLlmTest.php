<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiGateway;
use App\Services\Ai\Llm\AiLlmFactory;
use App\Services\Ai\Llm\FallbackLlm;
use App\Services\Ai\Llm\OpenAiJsonLlm;
use App\Services\Ai\SchemaViolation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Gói dự phòng: model chính hết hạn mức thì model dự phòng trả lời, log ghi đúng model đã trả lời. */
class FallbackLlmTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function pair(): FallbackLlm
    {
        return new FallbackLlm(new OpenAiJsonLlm('k', 'chinh', 'https://llm.test/v1', 900, 0.1, 5, 'object'),
            new OpenAiJsonLlm('k', 'du-phong', 'https://llm.test/v1', 900, 0.1, 5, 'object'), 600);
    }

    private static function ok(string $json): array
    {
        return ['choices' => [['message' => ['content' => $json], 'finish_reason' => 'stop']]];
    }

    public function test_quota_on_primary_uses_backup_and_skips_primary_during_cooldown(): void
    {
        Http::fakeSequence('llm.test/*')
            ->push([['error' => ['code' => 429, 'message' => 'You exceeded your current quota']]], 429)
            ->push(self::ok('{"a":1}'))
            ->push(self::ok('{"a":2}'));
        $llm = $this->pair();
        $this->assertSame(['a' => 1], $llm->generateJson('s', 'u', ['type' => 'object'])['data']);
        $this->assertSame('du-phong', $llm->model());
        $this->assertSame(['a' => 2], $llm->generateJson('s', 'u', ['type' => 'object'])['data']);
        $models = Http::recorded()->map(fn($x) => $x[0]->data()['model'])->all();
        $this->assertSame(['chinh', 'du-phong', 'du-phong'], $models, 'trong thời gian nghỉ không gọi lại model chính');
    }

    public function test_bad_answer_does_not_switch_model(): void
    {
        Http::fake(['llm.test/*' => Http::response(self::ok('không phải json'))]);
        $this->expectException(SchemaViolation::class);
        try {
            $this->pair()->generateJson('s', 'u', ['type' => 'object']);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_factory_builds_fallback_only_when_configured_and_not_in_eval(): void
    {
        config(['ai.driver' => 'openai', 'rag.llm.openai.key' => 'k', 'ai.models.openai.copilot' => 'chinh']);
        $this->assertInstanceOf(OpenAiJsonLlm::class, app(AiLlmFactory::class)->make('copilot'));
        config(['ai.fallback.model' => 'du-phong']);
        $this->assertInstanceOf(FallbackLlm::class, app(AiLlmFactory::class)->make('copilot'));
        config(['ai.fallback.disabled' => true]);
        $this->assertInstanceOf(OpenAiJsonLlm::class, app(AiLlmFactory::class)->make('copilot'));
    }

    public function test_gateway_logs_the_model_that_answered(): void
    {
        config(['ai.enabled' => true, 'ai.features.copilot' => true, 'ai.driver' => 'openai', 'rag.llm.openai.key' => 'k',
            'rag.llm.openai.base_url' => 'https://llm.test/v1', 'ai.models.openai.copilot' => 'chinh', 'ai.fallback.model' => 'du-phong', 'ai.openai_json_mode' => 'object']);
        Http::fakeSequence('llm.test/*')
            ->push([['error' => ['code' => 503, 'message' => 'overloaded']]], 503)
            ->push(self::ok('{"action":"reply"}'));
        $r = app(AiGateway::class)->json('copilot', null, 's', 'u', ['type' => 'object']);
        $this->assertTrue($r['ok']);
        $this->assertSame('du-phong', $r['model']);
    }
}
