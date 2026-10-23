<?php

namespace App\Services\Rag\Llm;

use RuntimeException;

/** Phương án chạy KHÔNG LLM (tài liệu mục 13): AskService trả lời bằng đoạn trích nguyên văn. */
final class NullLlm implements Llm
{
    public function name(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function generate(string $system, string $user): array
    {
        throw new RuntimeException('Chưa cấu hình LLM (RAG_LLM_DRIVER=none).');
    }
}
