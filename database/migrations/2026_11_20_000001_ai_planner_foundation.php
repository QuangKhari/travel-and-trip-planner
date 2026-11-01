<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bàn (AI-0): nhật ký từng lượt AI, cache tóm tắt theo phiên bản chuyến, và cột ghi chú chuyến đi.
 *   tbl_ai_runs        một dòng / một lượt xử lý (kể cả khi lùi về luật): đo độ trễ, token, kết quả guard, tỷ lệ áp dụng
 *   tbl_trip_ai_cache  kết quả F2 theo (tripId, rev, model, promptVersion): sửa lịch (rev đổi) là hết hiệu lực
 *   tbl_trips.notes    ghi chú từ câu mô tả (extras kiểu "note"), F2 đọc lại
 * Khóa ngoại dùng integer CÓ DẤU giống tbl_chat_logs để khớp tbl_users.userId và tbl_trips.tripId.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_ai_runs')) {
            Schema::create('tbl_ai_runs', function (Blueprint $t) {
                $t->bigIncrements('runId');
                $t->integer('userId')->nullable();
                $t->integer('tripId')->nullable();
                $t->string('feature', 12);                  // brief | explain | copilot
                $t->string('model', 60)->nullable();
                $t->string('engine', 10);                   // llm | rules | template | cache
                $t->string('promptVersion', 20);
                $t->unsignedSmallInteger('inputChars')->default(0);
                $t->unsignedInteger('inputTokens')->nullable();
                $t->unsignedInteger('outputTokens')->nullable();
                $t->unsignedInteger('latencyMs')->default(0);
                $t->unsignedTinyInteger('toolSteps')->default(0);
                $t->unsignedTinyInteger('repairRounds')->default(0);
                $t->string('outcome', 16);                  // ok | refused | fallback | guard_replaced | invalid | timeout | error
                $t->json('guardFlags')->nullable();
                $t->unsignedSmallInteger('newHardIssues')->nullable();
                $t->boolean('applied')->nullable();
                $t->string('inputText', 500)->nullable();  // chỉ khi AI_LOG_TEXT=true
                $t->string('error', 160)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['userId', 'createdAt']);
                $t->index(['feature', 'createdAt']);
                $t->foreign('userId')->references('userId')->on('tbl_users')->nullOnDelete();
                $t->foreign('tripId')->references('tripId')->on('tbl_trips')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('tbl_trip_ai_cache')) {
            Schema::create('tbl_trip_ai_cache', function (Blueprint $t) {
                $t->integer('cacheId', true);
                $t->integer('tripId');
                $t->unsignedInteger('rev')->default(0);
                $t->string('feature', 12)->default('explain');
                $t->string('model', 60);
                $t->string('promptVersion', 20);
                $t->json('payload');
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['tripId', 'rev', 'feature', 'model', 'promptVersion'], 'uq_trip_ai_cache');
                $t->foreign('tripId')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('tbl_trips') && !Schema::hasColumn('tbl_trips', 'notes')) {
            Schema::table('tbl_trips', function (Blueprint $t) {
                $t->text('notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_ai_cache');
        Schema::dropIfExists('tbl_ai_runs');
        if (Schema::hasTable('tbl_trips') && Schema::hasColumn('tbl_trips', 'notes')) {
            Schema::table('tbl_trips', function (Blueprint $t) {
                $t->dropColumn('notes');
            });
        }
    }
};
