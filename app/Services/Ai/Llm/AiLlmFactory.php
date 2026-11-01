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
        $primary = $this->build($driver, (string) config('ai.models.' . $driver . '.' . $task), $task);
        $fb = (array) config('ai.fallback', []);
        if (!empty($fb['model']) && $this->fallbackAllowed()) {
            $backup = $this->build((string) ($fb['driver'] ?: $driver), (string) $fb['model'], $task, $fb['key'] ?? null, $fb['base_url'] ?? null);
            if ($backup && $backup->available()) {
                return $primary && $primary->available() ? new FallbackLlm($primary, $backup, (int) ($fb['cooldown'] ?? 600)) : $backup;
            }
        }

        return $primary && $primary->available() ? $primary : null;
    }

    /** ai:eval tắt dự phòng (trừ khi --allow-fallback): số liệu thực nghiệm phải từ đúng MỘT model. */
    private function fallbackAllowed(): bool
    {
        return !config('ai.fallback.disabled', false);
    }

    private function build(string $driver, string $model, string $task, ?string $key = null, ?string $base = null): ?StructuredLlm
    {
        $max = (int) config('ai.max_tokens.' . $task, 900);
        $temp = (float) config('ai.temperature', 0.1);
        $timeout = (int) config('ai.timeout.' . $task, 20);

        return match ($driver) {
            'anthropic' => new AnthropicJsonLlm($key ?: config('rag.llm.anthropic.key'), $model,
                (string) ($base ?: config('rag.llm.anthropic.base_url', 'https://api.anthropic.com')), (string) config('rag.llm.anthropic.version', '2023-06-01'), $max, $temp, $timeout),
            'openai' => new OpenAiJsonLlm($key ?: config('rag.llm.openai.key'), $model,
                (string) ($base ?: config('rag.llm.openai.base_url', 'https://api.openai.com/v1')), $max, $temp, $timeout, (string) config('ai.openai_json_mode', 'schema'), config('ai.reasoning_effort') ?: null),
            default => null,
        };
    }
}
