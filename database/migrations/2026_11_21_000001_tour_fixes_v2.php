<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sửa lỗi phần tour theo báo cáo rà soát v2 (01/11/2026):
 *   tbl_tours.createdAt      biết tour nháp bỏ dở bao lâu để dọn (L-E-08, L-E-20)
 *   tbl_tours.durationDays   số ngày thật (ngày đầu và cuối đều tính) thay vì cắt chuỗi "4 ngày 3 đêm" khi lọc (L-D-11)
 *   tbl_booking.cancelReason customer | admin | expired: phân biệt khách hủy / admin hủy / hết hạn giữ chỗ (L-B-24)
 *   tbl_reviews.isHidden     ẩn đánh giá thay vì xóa cứng (L-F-10)
 * Có kiểm tra cột trước khi thêm nên chạy lại an toàn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_tours')) {
            Schema::table('tbl_tours', function (Blueprint $t) {
                if (!Schema::hasColumn('tbl_tours', 'durationDays')) {
                    $t->unsignedSmallInteger('durationDays')->nullable()->after('endDate');
                }
                if (!Schema::hasColumn('tbl_tours', 'createdAt')) {
                    $t->timestamp('createdAt')->nullable()->useCurrent();
                }
            });
            DB::table('tbl_tours')->whereNull('durationDays')->update(['durationDays' => DB::raw('DATEDIFF(endDate, startDate) + 1')]);
        }
        if (Schema::hasTable('tbl_booking') && !Schema::hasColumn('tbl_booking', 'cancelReason')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                $t->string('cancelReason', 12)->nullable()->after('bookingStatus');
            });
        }
        if (Schema::hasTable('tbl_reviews') && !Schema::hasColumn('tbl_reviews', 'isHidden')) {
            Schema::table('tbl_reviews', function (Blueprint $t) {
                $t->boolean('isHidden')->default(false);
                $t->index(['tourId', 'isHidden']);
            });
        }
    }

    public function down(): void
    {
        foreach (['tbl_tours' => ['durationDays', 'createdAt'], 'tbl_booking' => ['cancelReason'], 'tbl_reviews' => ['isHidden']] as $table => $cols) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                if ($table === 'tbl_reviews' && Schema::hasColumn('tbl_reviews', 'isHidden')) {
                    $t->dropIndex(['tourId', 'isHidden']);
                }
                foreach ($cols as $c) {
                    if (Schema::hasColumn($table, $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
