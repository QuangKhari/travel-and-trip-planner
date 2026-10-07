<?php

namespace App\Support;

/**
 * Chính sách hủy đơn.
 * Chỉ hủy được đơn đang chờ (n) hoặc đã xác nhận (y), và chỉ khi tour khởi hành
 * sau ít nhất MIN_DAYS ngày.
 */
class CancelPolicy
{
    public const MIN_DAYS = 4;

    public static function allows(?string $bookingStatus, ?string $startDate): bool
    {
        if (!in_array($bookingStatus, ['n', 'y'], true) || !$startDate) {
            return false;
        }

        $start    = \Carbon\Carbon::parse($startDate)->startOfDay();
        $earliest = now()->startOfDay()->addDays(self::MIN_DAYS);

        // Không dùng diffInDays: Carbon 3 trả số có dấu nên rất dễ sai chiều
        return $start->greaterThanOrEqualTo($earliest);
    }
}
