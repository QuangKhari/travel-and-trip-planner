<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-40 khóa mềm khi cộng tác: một chuyến có thành viên chỉ một người sửa tại một thời điểm.
 * Khóa hết hạn sau 5 phút nếu người giữ không còn hoạt động (trình duyệt gia hạn mỗi 60 giây).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_trip_locks')) {
            Schema::create('tbl_trip_locks', function (Blueprint $t) {
                $t->integer('tripId')->primary();
                $t->integer('userId');
                $t->timestamp('acquiredAt')->useCurrent();
                $t->timestamp('expiresAt');
                $t->foreign('tripId', 'fk_lock_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_locks');
    }
};
