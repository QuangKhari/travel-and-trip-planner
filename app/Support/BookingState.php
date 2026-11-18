<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Máy trạng thái tường minh của đơn đặt tour (tbl_booking.bookingStatus). MỌI lần đổi trạng thái đi qua transition():
 *   1. kiểm bước chuyển có trong bảng ALLOWED (sai = lỗi lập trình, ném LogicException để transaction hoàn tác);
 *   2. cập nhật CÓ ĐIỀU KIỆN (WHERE bookingStatus IN $from) — hai tiến trình cùng đổi một đơn thì chỉ một bên thành công;
 *   3. ghi một dòng tbl_booking_status_history trong cùng transaction.
 *
 *   n (chờ thanh toán / giữ chỗ) -> y (đã xác nhận) | c (đã hủy)
 *   y -> f (đã hoàn tất) | c
 *   c -> y   tiền về sau khi đơn đã hủy / hết hạn mà còn chỗ (late_payment); hoàn tác "khách không đến" chưa thu (no_show_undo)
 *   f -> y   hoàn tác "khách không đến" đã thu (no_show_undo)
 */
final class BookingState
{
    public const PENDING = 'n';
    public const CONFIRMED = 'y';
    public const CANCELLED = 'c';
    public const FINISHED = 'f';

    public const ALLOWED = [
        'n' => ['y', 'c'],
        'y' => ['f', 'c'],
        'c' => ['y'],
        'f' => ['y'],
    ];

    public const LABEL = ['n' => 'Chờ thanh toán', 'y' => 'Đã xác nhận', 'c' => 'Đã hủy', 'f' => 'Đã hoàn tất'];

    private static ?bool $history = null;

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /**
     * Đổi trạng thái một đơn. $from: các trạng thái hiện tại được chấp nhận. $extra: cột khác cập nhật cùng lúc (vd holdExpiresAt, cancelReason).
     * Trạng thái hiện tại đã đúng $to (vd đơn đã hoàn tất được đánh dấu khách không đến, vẫn "f") thì chỉ cập nhật $extra, không ghi lịch sử.
     * @param list<string>|string $from
     * @return bool true nếu đơn đã ở trạng thái $to sau lệnh này (do chính lệnh này đổi, hoặc đã sẵn)
     */
    public static function transition(int $bookingId, array|string $from, string $to, array $extra = [], ?string $reason = null, ?string $actor = null): bool
    {
        $from = array_values((array) $from);
        foreach ($from as $f) {
            if ($f !== $to && !self::can($f, $to)) {
                throw new LogicException("Đơn #$bookingId: không được chuyển trạng thái $f -> $to.");
            }
        }
        $cur = DB::table('tbl_booking')->where('bookingId', $bookingId)->value('bookingStatus');
        if ($cur === null || !in_array($cur, $from, true)) {
            return $cur === $to;
        }
        if ($cur === $to) {
            if ($extra) {
                DB::table('tbl_booking')->where('bookingId', $bookingId)->where('bookingStatus', $to)->update($extra);
            }

            return true;
        }
        $n = DB::table('tbl_booking')->where('bookingId', $bookingId)->where('bookingStatus', $cur)->update(['bookingStatus' => $to] + $extra);
        if ($n !== 1) {
            return false;   // tiến trình khác vừa đổi trước: bên gọi đọc lại và quyết định
        }
        if (self::$history ??= Schema::hasTable('tbl_booking_status_history')) {
            DB::table('tbl_booking_status_history')->insert(['bookingId' => $bookingId, 'fromStatus' => $cur, 'toStatus' => $to,
                'reason' => $reason !== null ? mb_substr($reason, 0, 40) : null, 'actor' => $actor !== null ? mb_substr($actor, 0, 40) : null, 'createdAt' => now()]);
        }

        return true;
    }

    /** Như transition() nhưng bắt buộc thành công (gọi trong transaction đã khóa dòng đơn và đã kiểm trạng thái). */
    public static function force(int $bookingId, array|string $from, string $to, array $extra = [], ?string $reason = null, ?string $actor = null): void
    {
        if (!self::transition($bookingId, $from, $to, $extra, $reason, $actor)) {
            throw new LogicException("Đơn #$bookingId: trạng thái đã đổi bởi thao tác khác, không chuyển được sang $to.");
        }
    }

    /** @return list<object{fromStatus,toStatus,reason,actor,createdAt}> */
    public static function history(int $bookingId): array
    {
        return Schema::hasTable('tbl_booking_status_history')
            ? DB::table('tbl_booking_status_history')->where('bookingId', $bookingId)->orderBy('historyId')->get()->all() : [];
    }
}
