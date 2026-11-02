<?php

namespace App\Services\Ai\Llm;

use App\Services\Rag\Llm\Llm;
use App\Services\Rag\Llm\OpenAiCompatLlm;

/**
 * Chọn LLM theo tác vụ (brief / explain / copilot) từ config/ai.php, kèm chuỗi dự phòng (ai.fallback, ai.fallback.second).
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
        $only = (int) config('ai.fallback.only', 0);
        if ($only > 0) {
            // ai:eval --use-fallback (=1) / --use-fallback2 (=2): chạy THẲNG bằng một model dự phòng, không lẫn model chính
            $l = $this->backups($task)[$only - 1] ?? null;

            return $l && $l->available() ? $l : null;
        }
        $primary = $this->build($driver, (string) config('ai.models.' . $driver . '.' . $task), $task);
        $backups = config('ai.fallback.disabled', false) ? [] : array_values(array_filter($this->backups($task), fn($l) => $l->available()));
        if ($backups) {
            return $primary && $primary->available() ? new FallbackLlm($primary, $backups, (int) config('ai.fallback.cooldown', 600)) : (count($backups) > 1 ? new FallbackLlm($backups[0], array_slice($backups, 1)) : $backups[0]);
        }

        return $primary && $primary->available() ? $primary : null;
    }

    /**
     * Chuỗi dự phòng cho Hỏi đáp / F5 (RAG): cùng các model dự phòng, nhưng gọi kiểu văn bản (generate).
     * @return list<Llm>
     */
    public function ragBackups(): array
    {
        if (config('ai.fallback.disabled', false) || !config('ai.fallback.rag', true)) {
            return [];
        }
        $out = [];
        foreach (self::entries() as $fb) {
            if (($fb['driver'] ?: 'openai') !== 'openai') {
                continue;   // Hỏi đáp chỉ cần chuẩn OpenAI cho dự phòng (Groq, Cerebras, Gemini...)
            }
            $l = new OpenAiCompatLlm($fb['key'] ?: config('rag.llm.openai.key'), (string) $fb['model'],
                (string) ($fb['base_url'] ?: config('rag.llm.openai.base_url', 'https://api.openai.com/v1')),
                (int) config('rag.llm.max_tokens', 600), (float) config('rag.llm.temperature', 0.2), (int) config('rag.llm.timeout', 45));
            if ($l->available()) {
                $out[] = $l;
            }
        }

        return $out;
    }

    /** @return list<array{model:string,driver:?string,key:?string,base_url:?string}> dự phòng 1, dự phòng 2 (chỉ mục có model) */
    public static function entries(): array
    {
        $out = [];
        foreach ([(array) config('ai.fallback', []), (array) config('ai.fallback.second', [])] as $fb) {
            if (!empty($fb['model'])) {
                $out[] = ['model' => (string) $fb['model'], 'driver' => $fb['driver'] ?? null, 'key' => $fb['key'] ?? null, 'base_url' => $fb['base_url'] ?? null];
            }
        }

        return $out;
    }

    /** @return list<StructuredLlm> */
    private function backups(string $task): array
    {
        $driver = (string) config('ai.driver', 'none');
        $out = [];
        foreach (self::entries() as $fb) {
            // reasoning_effort (AI_REASONING_EFFORT, dành cho Gemini) chỉ gửi khi dự phòng cùng nhà cung cấp: Groq / Cerebras... có thể báo 400
            $l = $this->build((string) ($fb['driver'] ?: $driver), $fb['model'], $task, $fb['key'], $fb['base_url'], empty($fb['base_url']));
            if ($l) {
                $out[] = $l;
            }
        }

        return $out;
    }

    private function build(string $driver, string $model, string $task, ?string $key = null, ?string $base = null, bool $reasoning = true): ?StructuredLlm
    {
        $max = (int) config('ai.max_tokens.' . $task, 900);
        $temp = (float) config('ai.temperature', 0.1);
        $timeout = (int) config('ai.timeout.' . $task, 20);

        return match ($driver) {
            'anthropic' => new AnthropicJsonLlm($key ?: config('rag.llm.anthropic.key'), $model,
                (string) ($base ?: config('rag.llm.anthropic.base_url', 'https://api.anthropic.com')), (string) config('rag.llm.anthropic.version', '2023-06-01'), $max, $temp, $timeout),
            'openai' => new OpenAiJsonLlm($key ?: config('rag.llm.openai.key'), $model,
                (string) ($base ?: config('rag.llm.openai.base_url', 'https://api.openai.com/v1')), $max, $temp, $timeout, (string) config('ai.openai_json_mode', 'schema'), $reasoning ? (config('ai.reasoning_effort') ?: null) : null),
            default => null,
        };
    }
}
