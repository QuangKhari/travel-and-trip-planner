<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Mô hình trả về thứ không đọc được thành JSON theo schema.
 * Mang theo số token của lượt gọi hỏng (model đã trả lời nên vẫn tốn tiền) để AiGateway cộng vào chi phí.
 */
final class SchemaViolation extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?int $inputTokens = null, public readonly ?int $outputTokens = null)
    {
        parent::__construct($message);
    }
}
