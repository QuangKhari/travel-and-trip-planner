<?php

namespace App\Services\Ai\Llm;

use App\Services\Rag\Llm\Llm;

/**
 * Mở rộng Llm của RAG (không sửa interface cũ): thêm đầu ra JSON theo schema.
 * La bàn Copilot (F3) cũng chỉ dùng generateJson: mỗi bước LLM trả MỘT "hành động JSON" (search / propose / reply) và
 * CopilotService chạy công cụ ở máy chủ. Không cần function calling riêng của từng hãng, chạy giống nhau trên Claude / Gemini / Ollama.
 */
interface StructuredLlm extends Llm
{
    /**
     * Một lượt, trả JSON khớp schema (vẫn PHẢI kiểm tra lại ở máy chủ: schema đúng chưa chắc nghĩa đúng).
     *
     * @param array<string,mixed> $schema JSON Schema (object)
     * @param array{timeout?:int,max_tokens?:int,name?:string} $opt
     * @return array{data:array,input_tokens:?int,output_tokens:?int,raw:string}
     * @throws \RuntimeException dịch vụ lỗi / hết thời gian chờ
     * @throws \App\Services\Ai\SchemaViolation JSON không đọc được
     */
    public function generateJson(string $system, string $user, array $schema, array $opt = []): array;

    /** Model đang dùng (ghi log, khóa cache). */
    public function model(): string;
}
