<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Số việc đang chờ, hiện thành chấm vàng ở menu trái và khung "Việc cần xử lý" của dashboard.
 * Cache 45 giây: menu hiện ở MỌI trang admin nên không truy vấn lại mỗi lần. Lỗi DB/bảng chưa có -> trả 0, không làm sập trang.
 */
final class AdminBadges
{
    private const KEY = 'admin.badges.v1';

    /** @return array{booking:int, unpaid:int, contact:int, reviewed:int, draft:int, pending:int} */
    public static function counts(): array
    {
        return Cache::remember(self::KEY, 45, function () {
            $c = ['booking' => 0, 'unpaid' => 0, 'contact' => 0, 'reviewed' => 0, 'draft' => 0, 'pending' => 0];
            try {
                $c['booking'] = (int) DB::table('tbl_booking')->where('bookingStatus', 'n')->count();
                $c['unpaid'] = (int) DB::table('tbl_booking')
                    ->join('tbl_checkout', 'tbl_checkout.bookingId', '=', 'tbl_booking.bookingId')
                    ->where('tbl_booking.bookingStatus', 'y')
                    ->where('tbl_checkout.paymentStatus', 'n')
                    ->count();
                if (Schema::hasTable('tbl_contact_messages')) {
                    $c['contact'] = (int) DB::table('tbl_contact_messages')->where('status', 'new')->count();
                }
                foreach (['tbl_foods', 'tbl_experiences', 'tbl_events', 'tbl_guides', 'tbl_itineraries'] as $t) {
                    if (!Schema::hasTable($t) || !Schema::hasColumn($t, 'reviewStatus')) {
                        continue;
                    }
                    $c['reviewed'] += (int) DB::table($t)->where('reviewStatus', 'reviewed')->count();
                    $c['draft'] += (int) DB::table($t)->where('reviewStatus', 'draft')->count();
                }
            } catch (\Throwable $e) {
                report($e);
            }
            // Bước "Đã kiểm tra" không còn trong giao diện: mọi bài chưa xuất bản đều tính là chờ xử lý.
            $c['pending'] = $c['draft'] + $c['reviewed'];

            return $c;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
