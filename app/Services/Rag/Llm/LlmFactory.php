<?php

namespace App\Services\Rag\Llm;

use App\Services\Ai\Llm\AiLlmFactory;
use App\Services\Ai\Llm\FallbackLlm;
use InvalidArgumentException;

final class LlmFactory
{
    public const DRIVERS = ['none', 'anthropic', 'openai'];

    public function make(?string $driver = null): Llm
    {
        $driver = $driver ?: (string) config('rag.llm.driver', 'none');
        $max = (int) config('rag.llm.max_tokens', 600);
        $temp = (float) config('rag.llm.temperature', 0.2);
        $timeout = (int) config('rag.llm.timeout', 45);

        $llm = match ($driver) {
            'none' => new NullLlm(),
            'anthropic' => new AnthropicLlm(config('rag.llm.anthropic.key'), config('rag.llm.anthropic.model'),
                config('rag.llm.anthropic.base_url'), config('rag.llm.anthropic.version'), $max, $temp, $timeout),
            'openai' => new OpenAiCompatLlm(config('rag.llm.openai.key'), config('rag.llm.openai.model'),
                config('rag.llm.openai.base_url'), $max, $temp, $timeout),
            default => throw new InvalidArgumentException("LLM không hợp lệ: {$driver} (chọn: " . implode(', ', self::DRIVERS) . ')'),
        };
        if ($llm instanceof NullLlm) {
            return $llm;   // driver = none: cố ý không dùng LLM (trả lời bằng trích đoạn), không gắn dự phòng
        }
        // Chuỗi dự phòng của La bàn (AI_FALLBACK_*, AI_FALLBACK2_*) dùng chung cho Hỏi đáp và F5
        $backups = app(AiLlmFactory::class)->ragBackups();
        if (!$backups) {
            return $llm;
        }

        return $llm->available() ? new FallbackLlm($llm, $backups, (int) config('ai.fallback.cooldown', 600))
            : (count($backups) > 1 ? new FallbackLlm($backups[0], array_slice($backups, 1)) : $backups[0]);
    }
}
