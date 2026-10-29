<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuần 13: chia sẻ chuyến đi theo tài khoản (mời bạn đồng hành, quyền xem/sửa) thay vì chỉ link.
 * - tbl_trip_members: ai được mời vào chuyến và với quyền gì (chủ chuyến vẫn là tbl_trips.userId).
 * - tbl_trips.rev: số phiên bản, tăng mỗi lần lưu; dùng để phát hiện hai người sửa cùng lúc (khóa lạc quan).
 * - tbl_trips.lastEditorId: người lưu gần nhất, để báo "X vừa cập nhật chuyến đi".
 * Idempotent: chạy lại không lỗi.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_trip_members')) {
            Schema::create('tbl_trip_members', function (Blueprint $t) {
                $t->bigIncrements('memberId');
                $t->integer('tripId');
                $t->integer('userId');
                $t->enum('role', ['view', 'edit'])->default('view');
                $t->integer('invitedBy')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['tripId', 'userId']);
                $t->index('userId');
                $t->foreign('tripId')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
                $t->foreign('userId')->references('userId')->on('tbl_users')->cascadeOnDelete();
            });
        }
        foreach (['rev' => fn(Blueprint $t) => $t->unsignedInteger('rev')->default(0),
            'lastEditorId' => fn(Blueprint $t) => $t->integer('lastEditorId')->nullable()] as $col => $add) {
            if (!Schema::hasColumn('tbl_trips', $col)) {
                Schema::table('tbl_trips', $add);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_members');
        foreach (['rev', 'lastEditorId'] as $col) {
            if (Schema::hasColumn('tbl_trips', $col)) {
                Schema::table('tbl_trips', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
