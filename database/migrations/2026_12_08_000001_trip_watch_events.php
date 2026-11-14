<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theo dõi chuyến đi: ảnh chụp lần kiểm tra gần nhất (tbl_trip_watch) và nhật ký sự kiện của chuyến (tbl_trip_events):
 * cảnh báo mới / đã hết, bản tin theo mốc, đã xếp lại lịch. Người dùng đã xem tới đâu: tbl_trip_event_seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_trip_watch')) {
            Schema::create('tbl_trip_watch', function (Blueprint $t) {
                $t->integer('tripId')->primary();
                $t->json('snapshot')->nullable();          // {cardId: hash}
                $t->dateTime('checkedAt')->nullable();
                $t->dateTime('mailedAt')->nullable();
                $t->foreign('tripId', 'fk_trip_watch_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
        if (!Schema::hasTable('tbl_trip_events')) {
            Schema::create('tbl_trip_events', function (Blueprint $t) {
                $t->bigIncrements('eventId');
                $t->integer('tripId');
                $t->string('kind', 20);                    // alert | resolved | digest | replan
                $t->string('tone', 8)->default('info');    // bad | warn | info | ok
                $t->string('title', 200);
                $t->text('text')->nullable();
                $t->unsignedTinyInteger('day')->nullable();
                $t->string('ref', 120)->nullable();        // mã thẻ cảnh báo / khóa bản tin (chống gửi trùng)
                $t->dateTime('createdAt');
                $t->index(['tripId', 'eventId'], 'ix_trip_events_trip');
                $t->unique(['tripId', 'kind', 'ref'], 'uq_trip_events_ref');
                $t->foreign('tripId', 'fk_trip_events_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
        if (!Schema::hasTable('tbl_trip_event_seen')) {
            Schema::create('tbl_trip_event_seen', function (Blueprint $t) {
                $t->integer('tripId');
                $t->integer('userId');
                $t->unsignedBigInteger('lastEventId')->default(0);
                $t->primary(['tripId', 'userId']);
                $t->foreign('tripId', 'fk_trip_event_seen_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_event_seen');
        Schema::dropIfExists('tbl_trip_events');
        Schema::dropIfExists('tbl_trip_watch');
    }
};
