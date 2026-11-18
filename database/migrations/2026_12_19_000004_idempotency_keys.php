<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khóa chống gửi lặp (Idempotency-Key) cho thao tác ghi do người dùng bấm: đặt tour, lưu chuyến từ bản nháp AI, nhận / từ chối báo giá,
 * ghi kết quả đề xuất AI. Gửi lại cùng khóa + cùng nội dung -> trả đúng phản hồi cũ, không làm lại. Giữ 24 giờ (lệnh dọn trong schedule).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_idempotency_keys')) {
            Schema::create('tbl_idempotency_keys', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('scope', 120);            // tên route | người dùng
                $t->string('idemKey', 64);
                $t->char('requestHash', 40);
                $t->string('status', 10);            // processing | done
                $t->unsignedSmallInteger('responseCode')->nullable();
                $t->string('contentType', 80)->nullable();
                $t->string('location', 500)->nullable();
                $t->mediumText('responseBody')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['scope', 'idemKey'], 'uq_idem_scope_key');
                $t->index('createdAt');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_idempotency_keys');
    }
};
