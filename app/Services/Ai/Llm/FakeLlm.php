<?php

namespace App\Services\Ai\Llm;

use RuntimeException;

/**
 * LLM giả cho kiểm thử và đánh giá không tốn tiền (tài liệu v2.2, phụ lục A).
 * Trả JSON cố định theo tác vụ ($opt['name']); có thể giả lỗi hoặc giả hết giờ.
 * Ghi lại lời nhắc cuối cùng để test kiểm tra nội dung gửi đi (vd không có email người dùng).
 */
final class FakeLlm implements StructuredLlm
{
    public ?string $lastSystem = null;
    public ?string $lastUser = null;
    public int $calls = 0;

    /**
     * @param array<string,array|callable> $byTask  ['brief' => [...json...] | fn(string $user): array]
     * @param ?string $fail null | 'error' | 'timeout' | 'schema'
     */
    public function __construct(private array $byTask = [], private ?string $fail = null) {}

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake';
    }

    public function available(): bool
    {
        return true;
    }

    public function generate(string $system, string $user): array
    {
        return ['text' => '', 'input_tokens' => 0, 'output_tokens' => 0];
    }

    public function generateJson(string $system, string $user, array $schema, array $opt = []): array
    {
        $this->calls++;
        $this->lastSystem = $system;
        $this->lastUser = $user;
        if ($this->fail === 'timeout') {
            throw new RuntimeException('timeout: fake');
        }
        if ($this->fail === 'error') {
            throw new RuntimeException('fake down');
        }
        if ($this->fail === 'schema') {
            throw new \App\Services\Ai\SchemaViolation('fake schema');
        }
        $d = $this->byTask[$opt['name'] ?? ''] ?? [];
        if (is_callable($d)) {
            $d = $d($user, $system);
        }

        return ['data' => $d, 'input_tokens' => 100, 'output_tokens' => 50, 'raw' => json_encode($d, JSON_UNESCAPED_UNICODE)];
    }
}
