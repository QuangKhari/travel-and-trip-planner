<?php

namespace App\Support;

use App\Jobs\ProcessDomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox sự kiện nghiệp vụ giữa các phân hệ (Commerce / Tour Ops → Planner, Content → Planner, Planner → AI).
 * record() ghi vào tbl_domain_events trong transaction hiện tại (rollback thì sự kiện cũng mất — không có sự kiện "ma"),
 * rồi đẩy job xử lý SAU KHI commit. Phân hệ nhận không đọc / ghi bảng của nhau, chỉ phản ứng với sự kiện.
 * Ai nghe sự kiện nào: SUBSCRIBERS (mỗi lớp có handle(string $name, array $payload), bỏ qua tên không thuộc mình).
 */
class DomainEvents
{
    public const BOOKING_CONFIRMED = 'BookingConfirmed';
    public const BOOKING_CANCELLED = 'BookingCancelled';
    public const DEPARTURE_CHANGED = 'DepartureChanged';
    public const PLACE_UNPUBLISHED = 'PlaceUnpublished';
    public const PLACE_HOURS_CHANGED = 'PlaceHoursChanged';
    /** Lịch một chuyến vừa đổi (lưu, khôi phục phiên bản, nhập đặt chỗ ngoài...): mỗi lần TripRevisions ghi một phiên bản. */
    public const TRIP_UPDATED = 'TripUpdated';

    /** @var array<string,list<class-string>> */
    public const SUBSCRIBERS = [
        self::BOOKING_CONFIRMED => [\App\Services\Integration\PlannerTourSync::class],
        self::BOOKING_CANCELLED => [\App\Services\Integration\PlannerTourSync::class],
        self::DEPARTURE_CHANGED => [\App\Services\Integration\PlannerTourSync::class],
        self::PLACE_UNPUBLISHED => [\App\Services\Integration\PlannerTourSync::class],
        self::PLACE_HOURS_CHANGED => [\App\Services\Integration\PlannerTourSync::class],
        self::TRIP_UPDATED => [\App\Services\Ai\TripChangeListener::class],
    ];

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
