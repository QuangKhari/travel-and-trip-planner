<?php

namespace App\Services\Rag\Llm;

interface Llm
{
    /** Tên hiển thị trong báo cáo, vd anthropic:claude-haiku-5-5. */
    public function name(): string;

    /** false với driver "none": AskService sẽ trả lời bằng đoạn trích thay vì gọi mô hình. */
    public function available(): bool;

    /**
     * @return array{text:string,input_tokens:?int,output_tokens:?int}
     * @throws \RuntimeException khi dịch vụ lỗi hoặc hết thời gian chờ
     */
    public function generate(string $system, string $user): array;
}
