<?php

namespace App\Support;

use App\Jobs\ProcessDomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox sự kiện nghiệp vụ giữa các phân hệ (Tour → Trip Planner, Nội dung → Trip Planner).
 * record() ghi vào tbl_domain_events trong transaction hiện tại (rollback thì sự kiện cũng mất — không có sự kiện "ma"),
 * rồi đẩy job xử lý SAU KHI commit. Phân hệ nhận không đọc / ghi bảng của nhau, chỉ phản ứng với sự kiện.
 */
class DomainEvents
{
    public const BOOKING_CONFIRMED = 'BookingConfirmed';
    public const BOOKING_CANCELLED = 'BookingCancelled';
    public const DEPARTURE_CHANGED = 'DepartureChanged';
    public const PLACE_UNPUBLISHED = 'PlaceUnpublished';
    public const PLACE_HOURS_CHANGED = 'PlaceHoursChanged';

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('tbl_domain_events');
    }

    public static function record(string $name, string $aggregate, int|string $aggregateId, array $payload = []): ?int
    {
        if (!self::ready()) {
            return null;
        }
        try {
            $id = (int) DB::table('tbl_domain_events')->insertGetId(['name' => $name, 'aggregate' => $aggregate, 'aggregateId' => (string) $aggregateId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'createdAt' => now()], 'eventId');
        } catch (\Throwable $e) {
            report($e);   // sự kiện là phụ: không làm hỏng nghiệp vụ chính

            return null;
        }
        DB::afterCommit(fn() => ProcessDomainEvent::dispatch($id));

        return $id;
    }
}
