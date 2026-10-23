<?php

namespace App\Services\Rag\Embedding;

interface Embedder
{
    /** Tên mô hình lưu ở cột tbl_chunks.model: vector của hai mô hình khác nhau KHÔNG so sánh được với nhau. */
    public function name(): string;

    /** Khóa cấu hình (local | openai | voyage), dùng để tra ngưỡng cổng trong config/rag.php. */
    public function key(): string;

    /**
     * @param list<string> $texts
     * @param 'document'|'query' $kind  một số mô hình nhúng tách "tài liệu" và "câu hỏi"
     * @return list<list<float>> vector đã chuẩn hóa, đúng thứ tự $texts
     */
    public function embed(array $texts, string $kind = 'document'): array;
}
