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

    public function test_timeout_on_primary_skips_it_for_a_short_while(): void
    {
        Http::fakeSequence('llm.test/*')
            ->pushFailedConnection()
            ->push(self::ok('{"a":1}'))
            ->push(self::ok('{"a":2}'));
        $llm = $this->pair();
        $this->assertSame(['a' => 1], $llm->generateJson('s', 'u', ['type' => 'object'])['data']);
        $this->assertSame(['a' => 2], $llm->generateJson('s', 'u', ['type' => 'object'])['data']);
        $models = Http::recorded()->map(fn($x) => $x[0]->data()['model'])->all();
        $this->assertSame(['chinh', 'du-phong', 'du-phong'], $models, 'không bắt người dùng chờ hết thời gian chờ ở mỗi lượt');
    }

    public function test_same_model_with_another_key_is_not_skipped_with_the_primary(): void
    {
        $chain = new FallbackLlm(new OpenAiJsonLlm('khoa-a', 'gemini', 'https://llm.test/v1', 900, 0.1, 5, 'object'),
            [new OpenAiJsonLlm('khoa-b', 'gemini', 'https://llm.test/v1', 900, 0.1, 5, 'object'), new OpenAiJsonLlm('g', 'groq', 'https://groq.test/v1', 900, 0.1, 5, 'object')]);
        Http::fakeSequence()
            ->push([['error' => ['code' => 429, 'message' => 'quota']]], 429)
            ->push(self::ok('{"a":1}'))
            ->push(self::ok('{"a":2}'));
        $chain->generateJson('s', 'u', ['type' => 'object']);
        $chain->generateJson('s', 'u', ['type' => 'object']);
        $keys = Http::recorded()->map(fn($x) => $x[0]->header('Authorization')[0])->all();
        $this->assertSame(['Bearer khoa-a', 'Bearer khoa-b', 'Bearer khoa-b'], $keys, 'khóa B vẫn được dùng khi khóa A hết lượt');
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
        config(['ai.fallback.only' => 1]);
        $this->assertSame('du-phong', app(AiLlmFactory::class)->make('copilot')->model(), '--use-fallback: chỉ model dự phòng');
        config(['ai.fallback.second.model' => 'lop-2', 'ai.fallback.only' => 2]);
        $this->assertSame('lop-2', app(AiLlmFactory::class)->make('copilot')->model(), '--use-fallback2: chỉ dự phòng lớp 2');
    }

    public function test_other_provider_backup_does_not_get_gemini_reasoning_and_retries_json_object(): void
    {
        config(['ai.enabled' => true, 'ai.features.copilot' => true, 'ai.driver' => 'openai', 'rag.llm.openai.key' => 'k', 'rag.llm.openai.base_url' => 'https://llm.test/v1',
            'ai.models.openai.copilot' => 'gemini', 'ai.reasoning_effort' => 'low',
            'ai.fallback.model' => 'llama', 'ai.fallback.key' => 'g', 'ai.fallback.base_url' => 'https://groq.test/v1']);
        Http::fakeSequence()
            ->push([['error' => ['code' => 429, 'message' => 'quota']]], 429)
            ->push(['error' => ['message' => 'json_schema is not supported with this model']], 400)
            ->push(self::ok('{"action":"reply"}'));
        $r = app(AiGateway::class)->json('copilot', null, 's', 'u', ['type' => 'object']);
        $this->assertTrue($r['ok']);
        $this->assertSame('llama', $r['model']);
        $sent = Http::recorded()->map(fn($x) => [$x[0]->url(), $x[0]->data()['response_format']['type'], $x[0]->data()['reasoning_effort'] ?? null])->all();
        $this->assertSame([['https://llm.test/v1/chat/completions', 'json_schema', 'low'],
            ['https://groq.test/v1/chat/completions', 'json_schema', null], ['https://groq.test/v1/chat/completions', 'json_object', null]], $sent);
    }

    public function test_three_layer_chain_reaches_the_second_backup(): void
    {
        $chain = new FallbackLlm(new OpenAiJsonLlm('k', 'gemini', 'https://llm.test/v1', 900, 0.1, 5, 'object'), [
            new OpenAiJsonLlm('g', 'groq', 'https://groq.test/v1', 900, 0.1, 5, 'object'),
            new OpenAiJsonLlm('c', 'cerebras', 'https://cerebras.test/v1', 900, 0.1, 5, 'object'),
        ]);
        Http::fakeSequence()
            ->push([['error' => ['code' => 429, 'message' => 'quota']]], 429)
            ->push(['error' => ['message' => 'Rate limit reached on tokens per day (TPD)']], 429)
            ->push(self::ok('{"a":1}'))
            ->push(self::ok('{"a":2}'));
        $this->assertSame(['a' => 1], $chain->generateJson('s', 'u', ['type' => 'object'])['data']);
        $this->assertSame('cerebras', $chain->model());
        $this->assertSame(['a' => 2], $chain->generateJson('s', 'u', ['type' => 'object'])['data']);
        $this->assertSame(['gemini', 'groq', 'cerebras', 'cerebras'], Http::recorded()->map(fn($x) => $x[0]->data()['model'])->all());
    }

    public function test_rag_and_f5_get_the_same_backup_chain_as_text_llms(): void
    {
        config(['rag.llm.driver' => 'openai', 'rag.llm.openai.key' => 'k', 'rag.llm.openai.base_url' => 'https://llm.test/v1', 'rag.llm.openai.model' => 'gemini',
            'ai.fallback.model' => 'groq', 'ai.fallback.key' => 'g', 'ai.fallback.base_url' => 'https://groq.test/v1',
            'ai.fallback.second.model' => 'cerebras', 'ai.fallback.second.key' => 'c', 'ai.fallback.second.base_url' => 'https://cerebras.test/v1']);
        $llm = app(\App\Services\Rag\Llm\LlmFactory::class)->make();
        $this->assertInstanceOf(FallbackLlm::class, $llm);
        Http::fake([
            'llm.test/*' => Http::response(['error' => ['message' => 'quota']], 429),
            'groq.test/*' => Http::response(['choices' => [['message' => ['content' => 'Ngày 2 có Văn Miếu [1].']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
        ]);
        $this->assertSame('Ngày 2 có Văn Miếu [1].', $llm->generate('s', 'u')['text']);
        $this->assertSame('groq', $llm->model());

        config(['ai.fallback.rag' => false]);
        $this->assertNotInstanceOf(FallbackLlm::class, app(\App\Services\Rag\Llm\LlmFactory::class)->make());
        config(['ai.fallback.rag' => true, 'rag.llm.driver' => 'none']);
        $this->assertInstanceOf(\App\Services\Rag\Llm\NullLlm::class, app(\App\Services\Rag\Llm\LlmFactory::class)->make(), 'driver none: cố ý không dùng LLM');
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
