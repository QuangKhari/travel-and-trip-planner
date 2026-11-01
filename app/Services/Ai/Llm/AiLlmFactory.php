<?php

namespace App\Services\Ai\Llm;

/**
 * Chọn LLM theo tác vụ (brief / explain / copilot) từ config/ai.php.
 * Kiểm thử và ai:eval thay LLM bằng cách bind StructuredLlm vào container:
 *   $this->app->instance(StructuredLlm::class, new FakeLlm([...]));
 * Trả null khi driver = none hoặc thiếu khóa: AiGateway sẽ dùng đường lùi.
 */
final class AiLlmFactory
{
    public function make(string $task): ?StructuredLlm
    {
        if (app()->bound(StructuredLlm::class)) {
            return app(StructuredLlm::class);
        }
        $driver = (string) config('ai.driver', 'none');
        $max = (int) config('ai.max_tokens.' . $task, 900);
        $temp = (float) config('ai.temperature', 0.1);
        $timeout = (int) config('ai.timeout.' . $task, 20);

        $llm = match ($driver) {
            'anthropic' => new AnthropicJsonLlm(config('rag.llm.anthropic.key'), (string) config('ai.models.anthropic.' . $task),
                (string) config('rag.llm.anthropic.base_url', 'https://api.anthropic.com'), (string) config('rag.llm.anthropic.version', '2023-06-01'), $max, $temp, $timeout),
            'openai' => new OpenAiJsonLlm(config('rag.llm.openai.key'), (string) config('ai.models.openai.' . $task),
                (string) config('rag.llm.openai.base_url', 'https://api.openai.com/v1'), $max, $temp, $timeout, (string) config('ai.openai_json_mode', 'schema'), config('ai.reasoning_effort') ?: null),
            default => null,
        };

        return $llm && $llm->available() ? $llm : null;
    }
}
