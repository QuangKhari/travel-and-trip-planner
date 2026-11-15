<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đi nhóm: quyết định chung TRƯỚC khi có lịch (học Troupe) và theo dõi ai đi.
 *  - tbl_trip_polls / _options / _votes: bỏ phiếu chọn ngày đi, điểm đến, chỗ ở (mỗi người một phiếu mỗi câu hỏi, đổi được tới khi chốt).
 *  - tbl_trip_members.rsvp: going | maybe | no. Số người của chuyến và phần chia chi phí lấy từ người "đi".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_trip_members') && !Schema::hasColumn('tbl_trip_members', 'rsvp')) {
            Schema::table('tbl_trip_members', function (Blueprint $t) {
                $t->string('rsvp', 8)->default('maybe');
                $t->timestamp('rsvpAt')->nullable();
            });
        }
        if (!Schema::hasTable('tbl_trip_polls')) {
            Schema::create('tbl_trip_polls', function (Blueprint $t) {
                $t->bigIncrements('pollId');
                $t->integer('tripId')->index();
                $t->string('kind', 12);                 // date | destination | stay | other
                $t->string('question', 160);
                $t->integer('createdBy');
                $t->string('status', 8)->default('open');   // open | closed
                $t->unsignedBigInteger('chosenOptionId')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->timestamp('closedAt')->nullable();
            });
            Schema::create('tbl_trip_poll_options', function (Blueprint $t) {
                $t->bigIncrements('optionId');
                $t->unsignedBigInteger('pollId')->index();
                $t->string('label', 160);
                $t->string('value', 255)->nullable();   // ngày Y-m-d | mã điểm đến | link chỗ ở
                $t->integer('createdBy');
                $t->timestamp('createdAt')->useCurrent();
            });
            Schema::create('tbl_trip_poll_votes', function (Blueprint $t) {
                $t->unsignedBigInteger('pollId');
                $t->unsignedBigInteger('optionId');
                $t->integer('userId');
                $t->timestamp('createdAt')->useCurrent();
                $t->primary(['pollId', 'userId']);
                $t->index('optionId');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_poll_votes');
        Schema::dropIfExists('tbl_trip_poll_options');
        Schema::dropIfExists('tbl_trip_polls');
        if (Schema::hasColumn('tbl_trip_members', 'rsvp')) {
            Schema::table('tbl_trip_members', fn(Blueprint $t) => $t->dropColumn(['rsvp', 'rsvpAt']));
        }
    }
};
