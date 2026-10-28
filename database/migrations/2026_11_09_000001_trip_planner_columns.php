<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 *  - tbl_trips: month (tháng đi), interests / dislikes (JSON), notes (cảnh báo/lưu ý của Planner), source (manual | itinerary | planner).
 *  - tbl_trip_items: reasons (JSON: vì sao Planner chọn mục này), durationMin (thời lượng dự kiến, phút).
 */
return new class extends Migration
{
    public function up(): void
    {
        $trips = [
            'month' => fn(Blueprint $t) => $t->unsignedTinyInteger('month')->nullable(),
            'interests' => fn(Blueprint $t) => $t->text('interests')->nullable(),
            'dislikes' => fn(Blueprint $t) => $t->text('dislikes')->nullable(),
            'notes' => fn(Blueprint $t) => $t->text('notes')->nullable(),
            'source' => fn(Blueprint $t) => $t->string('source', 12)->default('manual'),
        ];
        foreach ($trips as $col => $add) {
            if (!Schema::hasColumn('tbl_trips', $col)) {
                Schema::table('tbl_trips', $add);
            }
        }
        $items = [
            'reasons' => fn(Blueprint $t) => $t->text('reasons')->nullable(),
            'durationMin' => fn(Blueprint $t) => $t->unsignedSmallInteger('durationMin')->nullable(),
        ];
        foreach ($items as $col => $add) {
            if (!Schema::hasColumn('tbl_trip_items', $col)) {
                Schema::table('tbl_trip_items', $add);
            }
        }
    }

    public function down(): void
    {
        foreach (['reasons', 'durationMin'] as $col) {
            if (Schema::hasColumn('tbl_trip_items', $col)) {
                Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }
        foreach (['source', 'notes', 'dislikes', 'interests', 'month'] as $col) {
            if (Schema::hasColumn('tbl_trips', $col)) {
                Schema::table('tbl_trips', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
