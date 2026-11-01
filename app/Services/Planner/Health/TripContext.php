<?php

namespace App\Services\Planner\Health;

/** Dữ liệu đã tính sẵn cho các ràng buộc: lịch từng ngày (DaySchedule), chuyến, ngữ cảnh mưa / mùa từ payload. */
final class TripContext
{
    /**
     * @param array<int,DaySchedule> $days
     * @param array<int,array> $unassigned
     */
    public function __construct(
        public readonly object $trip,
        public readonly array $days,
        public readonly array $unassigned,
        public readonly array $payload,
    ) {}

    public function budget(): ?float
    {
        return $this->trip->budget !== null ? (float) $this->trip->budget : null;
    }

    public function groupSize(): int
    {
        return max(1, (int) ($this->trip->groupSize ?? 1));
    }
}
