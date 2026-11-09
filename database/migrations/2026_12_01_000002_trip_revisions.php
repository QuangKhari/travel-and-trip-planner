<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch sử phiên bản của chuyến đi: mỗi lần lưu (tbl_trips.rev + 1) chụp lại thông tin chuyến + toàn bộ mục.
 * Dùng để xem ai đổi gì, và khôi phục bản cũ (khôi phục tạo phiên bản MỚI, không viết lại lịch sử).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_trip_revisions')) {
            Schema::create('tbl_trip_revisions', function (Blueprint $t) {
                $t->bigIncrements('revisionId');
                $t->integer('tripId');
                $t->unsignedInteger('revisionNo');           // = tbl_trips.rev sau lần lưu
                $t->unsignedInteger('baseRevision')->nullable(); // rev client gửi lên (null = client cũ không gửi)
                $t->integer('actorId')->nullable();
                $t->string('changeType', 20)->default('save'); // save | restore | external (thêm/bỏ chuyến bay, khách sạn)
                $t->unsignedSmallInteger('itemCount')->default(0);
                $t->longText('payload');                       // JSON {trip:{...}, items:[...]}
                $t->timestamp('createdAt')->useCurrent();
                $t->unique(['tripId', 'revisionNo'], 'uq_trip_revision');
                $t->foreign('tripId', 'fk_trip_revision_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_trip_revisions');
    }
};
