<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API du lịch (T-36 vé máy bay, T-37 trạng thái chuyến bay, T-38 khách sạn).
 *   tbl_flight_offer_cache   kết quả tìm vé theo (chặng, ngày, số khách), hết hạn theo TTL hoặc hạn của offer, tùy cái nào sớm hơn
 *   tbl_flight_status        trạng thái chuyến bay đã lấy (dùng lại khi cả hai nhà cung cấp lỗi, có nhãn thời gian)
 *   tbl_api_quota            đếm số lượt gọi theo nhà cung cấp / tháng để dừng trước khi hết hạn mức gói miễn phí
 *   tbl_trip_external        chuyến bay / khách sạn người dùng đã thêm vào chuyến đi (chỉ để tham khảo, không phải đặt chỗ)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_flight_offer_cache')) {
            Schema::create('tbl_flight_offer_cache', function (Blueprint $t) {
                $t->string('cacheKey', 120)->primary();
                $t->string('provider', 20);
                $t->boolean('simulated')->default(false);
                $t->mediumText('payload');
                $t->timestamp('expiresAt')->index();
                $t->timestamp('createdAt')->useCurrent();
            });
        }
        if (!Schema::hasTable('tbl_flight_status')) {
            Schema::create('tbl_flight_status', function (Blueprint $t) {
                $t->string('flightNo', 12);
                $t->date('flightDate');
                $t->string('status', 20);
                $t->integer('delayMinutes')->nullable();
                $t->string('provider', 20);
                $t->timestamp('fetchedAt');
                $t->primary(['flightNo', 'flightDate']);
            });
        }
        if (!Schema::hasTable('tbl_api_quota')) {
            Schema::create('tbl_api_quota', function (Blueprint $t) {
                $t->string('provider', 20);
                $t->char('period', 7);   // YYYY-MM
                $t->unsignedInteger('used')->default(0);
                $t->primary(['provider', 'period']);
            });
        }
        if (!Schema::hasTable('tbl_trip_external')) {
            Schema::create('tbl_trip_external', function (Blueprint $t) {
                $t->increments('externalId');
                $t->integer('tripId')->index();
                $t->string('kind', 10);          // flight | hotel
                $t->string('provider', 20);
                $t->boolean('simulated')->default(false);
                $t->string('title', 160);
                $t->text('payload');
                $t->dateTime('startAt')->nullable();
                $t->decimal('price', 14, 0)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->foreign('tripId', 'fk_external_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['tbl_trip_external', 'tbl_api_quota', 'tbl_flight_status', 'tbl_flight_offer_cache'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
