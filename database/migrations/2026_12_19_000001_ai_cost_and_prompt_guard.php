<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hỏi đáp / F5 ghi thêm model + token (để tính chi phí cùng bảng với La bàn) và cờ chống chèn lệnh (PromptShield).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_chat_logs')) {
            return;
        }
        Schema::table('tbl_chat_logs', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_chat_logs', 'model')) {
                $t->string('model', 80)->nullable();
            }
            if (!Schema::hasColumn('tbl_chat_logs', 'inputTokens')) {
                $t->unsignedInteger('inputTokens')->nullable();
            }
            if (!Schema::hasColumn('tbl_chat_logs', 'outputTokens')) {
                $t->unsignedInteger('outputTokens')->nullable();
            }
            if (!Schema::hasColumn('tbl_chat_logs', 'guardFlags')) {
                $t->json('guardFlags')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_chat_logs')) {
            return;
        }
        Schema::table('tbl_chat_logs', function (Blueprint $t) {
            foreach (['model', 'inputTokens', 'outputTokens', 'guardFlags'] as $c) {
                if (Schema::hasColumn('tbl_chat_logs', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
