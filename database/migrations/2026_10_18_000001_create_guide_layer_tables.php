<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A1 - Lớp Khám phá (cẩm nang): mùa đẹp, lễ hội, món ăn, lịch trình, bài cẩm nang,
 * chuyến đi của người dùng, đánh dấu, vùng đệm nạp dữ liệu thô.
 *
 * Quy ước (tài liệu mục 6):
 *  - khóa chính/ngoại dùng integer CÓ DẤU để khớp tbl_users.userId, tbl_destinations.destinationId;
 *  - mọi bảng nội dung có sourceUrl, license, imageCredit, reviewStatus (draft|reviewed|published);
 *  - chỉ nội dung published mới hiển thị ra ngoài.
 * tbl_chunks và tbl_chat_logs (RAG, giai đoạn G) KHÔNG nằm trong migration này.
 */
return new class extends Migration {
    /** Các cột chung của bảng nội dung. */
    private function content(Blueprint $t): void
    {
        $t->string('sourceUrl', 255)->nullable();
        $t->string('license', 80)->nullable();
        $t->string('imageCredit', 255)->nullable();
        $t->enum('reviewStatus', ['draft', 'reviewed', 'published'])->default('draft');
        $t->timestamp('createdAt')->useCurrent();
        $t->timestamp('updatedAt')->nullable();
        $t->index('reviewStatus');
    }

    public function up(): void
    {
        Schema::create('tbl_destination_months', function (Blueprint $t) {
            $t->integer('destinationId');
            $t->unsignedTinyInteger('month');                       // 1..12
            $t->enum('level', ['best', 'ok', 'avoid'])->default('ok');
            $t->string('note', 160)->nullable();                    // dòng lý do hiển thị ở hero
            $t->enum('source', ['rule', 'manual'])->default('manual');
            $t->primary(['destinationId', 'month']);
            $t->index(['month', 'level']);
            $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
        });

        Schema::create('tbl_events', function (Blueprint $t) {
            $t->integer('eventId', true);
            $t->string('slug', 100)->unique();
            $t->string('name', 160);
            $t->integer('destinationId')->nullable();
            $t->boolean('isLunar')->default(false);                 // lưu quy tắc âm lịch, không lưu ngày dương
            $t->unsignedTinyInteger('lunarMonth')->nullable();
            $t->unsignedTinyInteger('lunarDay')->nullable();
            $t->unsignedTinyInteger('solarMonth')->nullable();
            $t->unsignedTinyInteger('solarDay')->nullable();        // thêm so với tài liệu: lễ hội dương lịch cần cả ngày
            $t->unsignedTinyInteger('durationDays')->default(1);
            $t->text('description')->nullable();
            $t->string('coverImage', 255)->nullable();
            $this->content($t);
            $t->index('destinationId');
            $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->nullOnDelete();
        });

        Schema::create('tbl_foods', function (Blueprint $t) {
            $t->integer('foodId', true);
            $t->string('slug', 100)->unique();
            $t->string('name', 160);
            $t->integer('destinationId')->nullable();
            $t->string('category', 40)->nullable();                 // nhóm món: bún phở, bánh, hải sản...
            $t->text('description')->nullable();
            $t->string('coverImage', 255)->nullable();
            $this->content($t);
            $t->index('destinationId');
            $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->nullOnDelete();
        });

        Schema::create('tbl_itineraries', function (Blueprint $t) {
            $t->integer('itineraryId', true);
            $t->string('slug', 100)->unique();
            $t->string('title', 160);
            $t->unsignedTinyInteger('days');
            $t->enum('region', ['b', 't', 'n'])->nullable();
            $t->text('summary')->nullable();
            $t->string('coverImage', 255)->nullable();
            $this->content($t);
            $t->index(['region', 'days']);
        });

        Schema::create('tbl_itinerary_items', function (Blueprint $t) {
            $t->integer('itemId', true);
            $t->integer('itineraryId');
            $t->unsignedTinyInteger('dayNo');
            $t->unsignedTinyInteger('orderNo')->default(0);
            $t->string('refType', 20)->nullable();                  // destination | place | food | event
            $t->integer('refId')->nullable();
            $t->string('label', 160);                               // tên hiển thị nếu không có refId
            $t->text('note')->nullable();
            $t->index(['itineraryId', 'dayNo', 'orderNo']);
            $t->foreign('itineraryId')->references('itineraryId')->on('tbl_itineraries')->cascadeOnDelete();
        });

        Schema::create('tbl_guide_categories', function (Blueprint $t) {
            $t->integer('categoryId', true);
            $t->string('slug', 80)->unique();
            $t->string('name', 100);
            $t->unsignedTinyInteger('displayOrder')->default(0);
        });

        Schema::create('tbl_guides', function (Blueprint $t) {
            $t->integer('guideId', true);
            $t->string('slug', 120)->unique();
            $t->integer('categoryId')->nullable();
            $t->string('title', 200);
            $t->longText('body');
            $t->string('coverImage', 255)->nullable();
            $t->date('updatedOn')->nullable();                      // hiện "Cập nhật lần cuối"
            $this->content($t);
            $t->index('categoryId');
            $t->foreign('categoryId')->references('categoryId')->on('tbl_guide_categories')->nullOnDelete();
        });

        Schema::create('tbl_trips', function (Blueprint $t) {
            $t->integer('tripId', true);
            $t->integer('userId');
            $t->string('title', 160);
            $t->date('startDate')->nullable();
            $t->unsignedTinyInteger('days')->nullable();
            $t->decimal('budget', 14, 2)->nullable();
            $t->unsignedTinyInteger('groupSize')->default(1);
            $t->enum('status', ['draft', 'planned', 'done'])->default('draft');
            $t->timestamp('createdAt')->useCurrent();
            $t->timestamp('updatedAt')->nullable();
            $t->index(['userId', 'status']);
            $t->foreign('userId')->references('userId')->on('tbl_users')->cascadeOnDelete();
        });

        Schema::create('tbl_trip_items', function (Blueprint $t) {
            $t->integer('itemId', true);
            $t->integer('tripId');
            $t->enum('refType', ['destination', 'place', 'event', 'food', 'tour']);
            $t->integer('refId');                                   // trỏ tới nhiều bảng nên không đặt khóa ngoại
            $t->unsignedTinyInteger('dayNo')->nullable();
            $t->unsignedTinyInteger('orderNo')->default(0);
            $t->index(['tripId', 'dayNo', 'orderNo']);
            $t->unique(['tripId', 'refType', 'refId']);
            $t->foreign('tripId')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
        });

        Schema::create('tbl_user_marks', function (Blueprint $t) {
            $t->integer('userId');
            $t->enum('type', ['saved', 'visited', 'tried', 'attended']); // lưu, đã đến, đã thử, đã dự
            $t->enum('refType', ['destination', 'place', 'food', 'event']);
            $t->integer('refId');
            $t->timestamp('markedAt')->useCurrent();
            $t->primary(['userId', 'type', 'refType', 'refId']);
            $t->index(['refType', 'refId']);
            $t->foreign('userId')->references('userId')->on('tbl_users')->cascadeOnDelete();
        });

        Schema::create('tbl_import_raw', function (Blueprint $t) {
            $t->bigIncrements('rawId');
            $t->string('source', 20);                               // wikidata | wikipedia | osm | openmeteo | commons
            $t->string('sourceId', 120);
            $t->json('payload');
            $t->timestamp('fetchedAt')->useCurrent();
            $t->char('hash', 64);                                   // sha256 của payload: bỏ qua nếu không đổi
            $t->unique(['source', 'sourceId']);
        });
    }

    public function down(): void
    {
        foreach (
            [
                'tbl_import_raw',
                'tbl_user_marks',
                'tbl_trip_items',
                'tbl_trips',
                'tbl_guides',
                'tbl_guide_categories',
                'tbl_itinerary_items',
                'tbl_itineraries',
                'tbl_foods',
                'tbl_events',
                'tbl_destination_months'
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
