<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giờ bắt đầu do người dùng đặt cho một mục trong ngày (timeline kiểu Wanderlog "Add start time").
 *  - startMin: số phút tính từ 00:00 (0..1439), NULL = để hệ thống tự xếp giờ theo thứ tự, thời lượng và quãng đường.
 * Cột durationMin (thời lượng ở lại, phút) đã có từ migration trip_planner_columns; ở đây chỉ bổ sung nếu còn thiếu.
 * Chạy lại an toàn: chỉ thêm cột còn thiếu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = [
            'durationMin' => fn(Blueprint $t) => $t->unsignedSmallInteger('durationMin')->nullable(),
            'startMin' => fn(Blueprint $t) => $t->unsignedSmallInteger('startMin')->nullable(),
        ];
        foreach ($cols as $col => $add) {
            if (Schema::hasTable('tbl_trip_items') && !Schema::hasColumn('tbl_trip_items', $col)) {
                Schema::table('tbl_trip_items', $add);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_trip_items') && Schema::hasColumn('tbl_trip_items', 'startMin')) {
            Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn('startMin'));
        }
    }
};
