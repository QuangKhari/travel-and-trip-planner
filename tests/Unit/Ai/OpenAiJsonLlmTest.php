<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Llm\OpenAiJsonLlm;
use App\Services\Ai\SchemaViolation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cổng chuẩn OpenAI (gồm Gemini bản tương thích): lỗi schema thì thử lại bằng json_object; JSON bị cắt thì báo đúng lý do. */
class OpenAiJsonLlmTest extends TestCase
{
    private function llm(): OpenAiJsonLlm
    {
        return new OpenAiJsonLlm('k', 'm', 'https://llm.test/v1', 900, 0.1, 5, 'schema');
    }

    public function test_schema_rejected_retries_once_with_json_object(): void
    {
        Http::fakeSequence('llm.test/*')
            ->push(['error' => ['message' => 'Invalid JSON payload: unknown field "pattern" in response_format.json_schema']], 400)
            ->push(['choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]]);
        $r = $this->llm()->generateJson('sys', 'user', ['type' => 'object'], ['name' => 'explain']);
        $this->assertSame(['a' => 1], $r['data']);
        $sent = Http::recorded()->map(fn($x) => $x[0]->data()['response_format']['type'])->all();
        $this->assertSame(['json_schema', 'json_object'], $sent);
    }

    public function test_gemini_generic_invalid_argument_switches_to_json_object_and_remembers(): void
    {
        Http::fakeSequence('llm.test/*')
            ->push([['error' => ['code' => 400, 'message' => 'Request contains an invalid argument.', 'status' => 'INVALID_ARGUMENT']]], 400)
            ->push(['choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']]])
            ->push(['choices' => [['message' => ['content' => '{"a":2}'], 'finish_reason' => 'stop']]]);
        $this->assertSame(['a' => 1], $this->llm()->generateJson('s', 'u', ['type' => 'object'], ['name' => 'explain'])['data']);
        // lần sau đi thẳng json_object, không tốn thêm một lượt 400
        $this->assertSame(['a' => 2], $this->llm()->generateJson('s', 'u', ['type' => 'object'], ['name' => 'explain'])['data']);
        $sent = Http::recorded()->map(fn($x) => $x[0]->data()['response_format']['type'])->all();
        $this->assertSame(['json_schema', 'json_object', 'json_object'], $sent);
    }

    public function test_error_message_is_the_provider_sentence(): void
    {
        Http::fake(['llm.test/*' => Http::response([['error' => ['code' => 429, 'message' => "You exceeded your current quota,\n please check"]]], 429)]);
        try {
            $this->llm()->generateJson('s', 'u', ['type' => 'object']);
            $this->fail('phải ném lỗi');
        } catch (\RuntimeException $e) {
            $this->assertSame('LLM HTTP 429: You exceeded your current quota, please check', $e->getMessage());
        }
    }

    public function test_other_400_is_not_retried(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => ['message' => 'model is not specified']], 400)]);
        try {
            $this->llm()->generateJson('sys', 'user', ['type' => 'object']);
            $this->fail('phải ném lỗi');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('model is not specified', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_truncated_json_says_max_tokens(): void
    {
        Http::fake(['llm.test/*' => Http::response(['choices' => [['message' => ['content' => '{"headline":"Hà Nội'], 'finish_reason' => 'length']]])]);
        $this->expectException(SchemaViolation::class);
        $this->expectExceptionMessage('max_tokens');
        $this->llm()->generateJson('sys', 'user', ['type' => 'object']);
    }

    public function test_empty_model_env_falls_back_instead_of_sending_empty_string(): void
    {
        foreach (['AI_MODEL_COPILOT' => '', 'RAG_LLM_MODEL' => 'gemini-x'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        try {
            $cfg = require config_path('ai.php');
            $this->assertSame('gemini-x', $cfg['models']['openai']['copilot']);
            $this->assertSame('claude-sonnet-5-5', $cfg['models']['anthropic']['copilot']);
        } finally {
            foreach (['AI_MODEL_COPILOT', 'RAG_LLM_MODEL'] as $k) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            }
        }
    }
}
