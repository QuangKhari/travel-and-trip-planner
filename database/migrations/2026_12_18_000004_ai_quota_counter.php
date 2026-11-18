<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bộ đếm lượt AI theo người dùng / ngày trong CSDL (UNIQUE userId + day): đặt trước lượt bằng khóa dòng (nhiều request đồng thời
 * không cùng vượt hạn mức), trừ khi model đã trả lời, trả lại khi gọi lỗi. Không còn phụ thuộc việc ghi nhật ký tbl_ai_runs.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_ai_quota')) {
            Schema::create('tbl_ai_quota', function (Blueprint $t) {
                $t->integer('userId');
                $t->date('day');
                $t->unsignedInteger('used')->default(0);
                $t->unsignedInteger('reserved')->default(0);
                $t->timestamp('reservedAt')->nullable();
                $t->primary(['userId', 'day'], 'pk_ai_quota');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_ai_quota');
    }
};
