<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái của một mục khi tối ưu tuyến / tự cân bằng ngày:
 *  - FIXED   : có giờ cố định (startMin khác NULL), không đổi giờ;
 *  - LOCKED  : người dùng khóa vị trí (isLocked = 1), không bị đổi thứ tự hay chuyển ngày;
 *  - FLEXIBLE: còn lại, bộ tối ưu được phép sắp lại.
 * Chạy lại an toàn: chỉ thêm cột còn thiếu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_trip_items') && !Schema::hasColumn('tbl_trip_items', 'isLocked')) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->boolean('isLocked')->default(false));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_trip_items') && Schema::hasColumn('tbl_trip_items', 'isLocked')) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn('isLocked'));
        }
    }
};
