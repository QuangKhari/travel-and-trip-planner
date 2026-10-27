<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuần 3: (1) tbl_itinerary_items thêm khung giờ `slot` và `durationMin`; (2) bảng lịch sử Coverage.
 * Chạy lại an toàn (có kiểm tra tồn tại).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['slot' => fn(Blueprint $t) => $t->string('slot', 12)->nullable()->after('orderNo'),
                  'durationMin' => fn(Blueprint $t) => $t->unsignedSmallInteger('durationMin')->nullable()->after('slot')] as $col => $add) {
            if (!Schema::hasColumn('tbl_itinerary_items', $col)) {
                Schema::table('tbl_itinerary_items', $add);
            }
        }

        if (!Schema::hasTable('tbl_coverage_history')) {
            Schema::create('tbl_coverage_history', function (Blueprint $t) {
                $t->bigIncrements('historyId');
                $t->integer('destinationId');
                $t->date('day');                                   // mỗi điểm đến một dòng mỗi ngày (chạy lại trong ngày thì ghi đè)
                $t->unsignedTinyInteger('percent');
                $t->decimal('earned', 5, 1);
                $t->unsignedSmallInteger('applicable');
                $t->boolean('plannerReady')->default(false);
                $t->text('counts')->nullable();                    // JSON số lượng từng loại tại thời điểm chụp
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['destinationId', 'day']);
                $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_coverage_history');
        foreach (['durationMin', 'slot'] as $col) {
            if (Schema::hasColumn('tbl_itinerary_items', $col)) {
                Schema::table('tbl_itinerary_items', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
