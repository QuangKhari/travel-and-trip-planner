<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt D (L1): gợi ý nào ĐÃ HIỆN cho ai, ở vị trí nào, trong ngữ cảnh nào — mẫu âm cho mô hình cá nhân hóa (mức 5).
 * Mẫu dương lấy từ tbl_place_signals (added / applied / visited) cùng người, cùng chuyến, cùng nơi, sau lúc hiện.
 * Không lưu chữ người dùng gõ (chỉ độ dài truy vấn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_reco_impressions')) {
            return;
        }
        Schema::create('tbl_reco_impressions', function (Blueprint $t) {
            $t->id('impressionId');
            $t->char('listId', 16);                      // một lần hiện danh sách
            $t->integer('userId');
            $t->integer('tripId')->nullable();
            $t->string('surface', 16);                   // suggest | around | search | browse | copilot | ai_plan
            $t->string('policy', 16)->default('rules');  // rules | model | interleave
            $t->string('refType', 20);
            $t->unsignedBigInteger('refId');
            $t->unsignedSmallInteger('position');
            $t->float('score')->nullable();              // điểm của cách xếp lúc hiện (nếu có)
            $t->json('ctx')->nullable();                 // ngày, thứ, tháng, giờ, nhịp đi, cỡ nhóm, điểm đến, độ dài truy vấn
            $t->timestamp('createdAt')->useCurrent();
            $t->index(['userId', 'tripId', 'refType', 'refId']);
            $t->index(['listId']);
            $t->index(['createdAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_reco_impressions');
    }
};
