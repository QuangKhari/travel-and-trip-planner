<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký thao tác quản trị (đặc tả §6.3 tbl_audit_log, D-08).
 * Ghi ai (admin nào, vai trò), làm gì (action), trên đối tượng nào (entity + entityId), dữ liệu trước / sau (JSON), IP, thời điểm.
 * Chỉ thêm, không sửa / xóa dòng nào từ giao diện.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_audit_log')) {
            return;
        }
        Schema::create('tbl_audit_log', function (Blueprint $t) {
            $t->bigIncrements('auditId');
            $t->string('actorType', 12)->default('admin');      // admin | system
            $t->integer('actorId')->nullable();
            $t->string('actorName', 60)->nullable();            // giữ tên lúc làm, admin bị xóa vẫn đọc được
            $t->string('actorRole', 12)->nullable();
            $t->string('action', 60);                           // vd booking.confirm, tour.update, user.block
            $t->string('entity', 40);                           // booking | tour | departure | user | review | promotion | admin | refund | ticket
            $t->string('entityId', 40)->nullable();
            $t->string('summary', 255)->nullable();             // câu mô tả ngắn để đọc nhanh
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('userAgent', 255)->nullable();
            $t->timestamp('createdAt')->useCurrent();
            $t->index(['entity', 'entityId'], 'idx_audit_entity');
            $t->index(['actorId', 'createdAt'], 'idx_audit_actor');
            $t->index('createdAt', 'idx_audit_time');
            $t->index('action', 'idx_audit_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_audit_log');
    }
};
