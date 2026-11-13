<?php

namespace App\Services;

use App\Support\AdminBadges;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B5: khách KHÔNG ĐẾN ngày khởi hành (no-show).
 *  - Chỉ đánh dấu được từ ngày khởi hành trở đi, cho đơn đã xác nhận (y) hoặc đã hoàn tất (f).
 *  - Đơn đã thanh toán: chuyển "Hoàn tất" + cờ no-show, KHÔNG hoàn tiền (chính sách: vắng mặt không hoàn).
 *    Đơn chưa thanh toán (trả tại văn phòng): chuyển "Đã hủy" với lý do no_show, không phát sinh hoàn.
 *  - Chỗ KHÔNG trả lại (đoàn đã đi). Khách bị đánh dấu không viết được đánh giá cho chuyến đó.
 *  - Hoàn tác được trong 72 giờ (nhân viên bấm nhầm), mọi thao tác ghi nhật ký.
 */
class NoShowService
{
    public const UNDO_HOURS = 72;

    public static function ready(): bool
    {
        return Schema::hasColumn('tbl_booking', 'noShowAt');
    }

    /** @return array{ok:bool,message:string} */
    public function mark(int $bookingId, int $adminId, ?string $note): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'message' => 'Cần chạy php artisan migrate.'];
        }
        $res = DB::transaction(function () use ($bookingId, $adminId, $note) {
            $b = DB::table('tbl_booking as b')->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')->where('b.bookingId', $bookingId)
                ->lockForUpdate()->first(['b.bookingId', 'b.bookingCode', 'b.bookingStatus', 'b.departureDate', 'b.noShowAt', 'c.paymentStatus']);
            if (!$b) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($b->noShowAt) {
                return ['ok' => false, 'message' => 'Đơn đã được đánh dấu khách không đến.'];
            }
            if (!in_array($b->bookingStatus, ['y', 'f'], true)) {
                return ['ok' => false, 'message' => 'Chỉ đánh dấu được đơn đã xác nhận hoặc đã hoàn tất.'];
            }
            if (!$b->departureDate || $b->departureDate > now()->toDateString()) {
                return ['ok' => false, 'message' => 'Chưa tới ngày khởi hành nên chưa đánh dấu khách không đến được.'];
            }
            $paid = $b->paymentStatus === 'y';
            $upd = ['noShowAt' => now(), 'noShowBy' => $adminId, 'noShowNote' => $note ? mb_substr(trim($note), 0, 255) : null,
                'bookingStatus' => $paid ? 'f' : 'c'];
            if (!$paid && Schema::hasColumn('tbl_booking', 'cancelReason')) {
                $upd['cancelReason'] = 'no_show';
            }
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update($upd);
            AuditLog::record('booking.no_show', 'booking', $bookingId, 'Khách không đến: đơn ' . $b->bookingCode . ($paid ? ' (đã thu, không hoàn)' : ' (chưa thu, hủy đơn)'),
                ['bookingStatus' => $b->bookingStatus], ['bookingStatus' => $upd['bookingStatus'], 'noShow' => true, 'note' => $upd['noShowNote']]);

            return ['ok' => true, 'message' => 'Đã đánh dấu khách không đến' . ($paid ? '; đơn giữ nguyên số tiền đã thu.' : '; đơn chưa thanh toán được hủy.')];
        });
        if ($res['ok']) {
            AdminBadges::forget();
        }

        return $res;
    }

    /** Hoàn tác (trong UNDO_HOURS giờ): trả đơn về "Đã xác nhận". */
    public function undo(int $bookingId, int $adminId): array
    {
        return DB::transaction(function () use ($bookingId, $adminId) {
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            if (!$b || !$b->noShowAt) {
                return ['ok' => false, 'message' => 'Đơn không có đánh dấu khách không đến.'];
            }
            if (now()->diffInHours($b->noShowAt, true) > self::UNDO_HOURS) {
                return ['ok' => false, 'message' => 'Đã quá ' . self::UNDO_HOURS . ' giờ, không hoàn tác được nữa.'];
            }
            $upd = ['noShowAt' => null, 'noShowBy' => null, 'noShowNote' => null, 'bookingStatus' => 'y'];
            if (($b->cancelReason ?? null) === 'no_show') {
                $upd['cancelReason'] = null;
            }
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update($upd);
            AuditLog::record('booking.no_show_undo', 'booking', $bookingId, 'Hoàn tác đánh dấu khách không đến đơn ' . $b->bookingCode, ['noShow' => true], ['bookingStatus' => 'y']);

            return ['ok' => true, 'message' => 'Đã hoàn tác: đơn trở về "Đã xác nhận".'];
        });
    }
}
