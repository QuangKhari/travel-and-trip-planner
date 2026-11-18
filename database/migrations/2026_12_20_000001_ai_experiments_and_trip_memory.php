<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (1) A/B thử nghiệm AI: mỗi lượt ghi nhóm thử nghiệm "tên:nhóm" (vd copilot-model:B) để so sánh các nhóm trên cùng chỉ số.
 * (2) Trí nhớ theo chuyến: điều người dùng đã từ chối / đã nói về chuyến này, La bàn và Gợi ý dùng lại; người dùng xem và xóa được.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tbl_ai_runs') && !Schema::hasColumn('tbl_ai_runs', 'variant')) {
            Schema::table('tbl_ai_runs', function (Blueprint $t) {
                $t->string('variant', 40)->nullable();
                $t->index(['variant', 'createdAt']);
            });
        }
        if (!Schema::hasTable('tbl_trip_memory')) {
            Schema::create('tbl_trip_memory', function (Blueprint $t) {
                $t->bigIncrements('memoryId');
                $t->integer('tripId');
                $t->string('kind', 16);                 // rejected | prefer | avoid | note
                $t->string('refType', 20)->nullable();  // place | food | experience ... (kind = rejected)
                $t->integer('refId')->nullable();
                $t->string('label', 160);               // điều được nhớ, viết cho người dùng đọc
                $t->string('source', 16);               // copilot | suggest | user
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['tripId', 'kind', 'refType', 'refId'], 'uq_trip_memory_ref');
                $t->index(['tripId', 'memoryId']);
                $t->foreign('tripId', 'fk_trip_memory_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_memory');
        if (Schema::hasTable('tbl_ai_runs') && Schema::hasColumn('tbl_ai_runs', 'variant')) {
            Schema::table('tbl_ai_runs', function (Blueprint $t) {
                $t->dropIndex(['variant', 'createdAt']);
                $t->dropColumn('variant');
            });
        }
    }
};
