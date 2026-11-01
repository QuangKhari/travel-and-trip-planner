<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nguồn của chi phí một mục trong chuyến (trang Ngân sách phân biệt rõ ba loại):
 *  - planned : người dùng tự nhập giá dự kiến (mặc định khi có cost mà costType trống)
 *  - estimate: giá điền từ bảng giá tham khảo config('planner.costs'), chưa phải giá thật
 *  - actual  : số tiền đã chi thật (nhập sau hoặc trong chuyến)
 * Chạy lại an toàn: chỉ thêm cột còn thiếu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_trip_items') && !Schema::hasColumn('tbl_trip_items', 'costType')) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->string('costType', 10)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_trip_items') && Schema::hasColumn('tbl_trip_items', 'costType')) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn('costType'));
        }
    }
};
