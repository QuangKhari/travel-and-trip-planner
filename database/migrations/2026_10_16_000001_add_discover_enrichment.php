<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dữ liệu nạp từ nguồn ngoài cho điểm đến:
 *  - tbl_destinations: thêm tiêu đề bài Wikipedia, đoạn giới thiệu, ghi công ảnh
 *  - tbl_destination_weather: cache thời tiết Open-Meteo (mỗi điểm đến 1 dòng)
 *  - tbl_external_places: địa điểm (POI) đồng bộ từ OpenStreetMap/Geoapify
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tbl_destinations', function (Blueprint $table) {
            $table->string('wikiTitle', 160)->nullable()->after('coverImage');
            $table->text('wikiExtract')->nullable()->after('wikiTitle');
            $table->string('wikiUrl', 255)->nullable()->after('wikiExtract');
            $table->string('imageCredit', 255)->nullable()->after('wikiUrl');
            $table->string('imageSourceUrl', 255)->nullable()->after('imageCredit');
            $table->timestamp('wikiSyncedAt')->nullable()->after('imageSourceUrl');
        });

        Schema::create('tbl_destination_weather', function (Blueprint $table) {
            $table->integer('destinationId')->primary();
            $table->json('payload');
            $table->timestamp('fetchedAt');
            $table->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
        });

        Schema::create('tbl_external_places', function (Blueprint $table) {
            $table->bigIncrements('placeId');
            $table->integer('destinationId');
            $table->string('source', 20);              // overpass | geoapify
            $table->string('externalId', 60);          // vd "node/123", hoặc place_id của Geoapify
            $table->string('name', 160);
            $table->string('category', 20);            // see | culture | eat
            $table->string('kind', 60)->nullable();    // beach, museum, restaurant...
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->unsignedSmallInteger('rank')->default(0); // càng cao càng nổi bật
            $table->string('website', 255)->nullable();
            $table->timestamp('syncedAt');
            $table->unique(['source', 'externalId']);
            $table->index(['destinationId', 'category', 'rank']);
            $table->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_external_places');
        Schema::dropIfExists('tbl_destination_weather');
        Schema::table('tbl_destinations', function (Blueprint $table) {
            $table->dropColumn(['wikiTitle', 'wikiExtract', 'wikiUrl', 'imageCredit', 'imageSourceUrl', 'wikiSyncedAt']);
        });
    }
};
