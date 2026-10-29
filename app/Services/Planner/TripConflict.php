<?php

namespace App\Services\Planner;

/** Chuyến đi đã được người khác lưu sau lần tải của bạn: không ghi đè, báo để tải lại. */
final class TripConflict extends \RuntimeException
{
    public function __construct(public readonly int $currentRev, public readonly ?string $by = null)
    {
        parent::__construct('Chuyến đi vừa được ' . ($by ?: 'người khác') . ' cập nhật. Hãy tải lại trang để xem bản mới trước khi sửa tiếp.');
    }
}
