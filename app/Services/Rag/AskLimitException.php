<?php

namespace App\Services\Rag;

use RuntimeException;

/** Hết lượt hỏi trong ngày (config rag.limits.daily_per_user). */
class AskLimitException extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("Bạn đã dùng hết {$limit} lượt hỏi hôm nay. Lượt hỏi được làm mới vào 00:00 mỗi ngày.");
    }
}
