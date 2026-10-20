<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A5: số liệu khí hậu trung bình 12 tháng của mỗi điểm đến, để luật và báo cáo giải thích được. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tbl_destination_climate', function (Blueprint $t) {
            $t->integer('destinationId');
            $t->unsignedTinyInteger('month');
            $t->decimal('tMax', 4, 1)->nullable();       // nhiệt độ cao nhất trung bình, °C
            $t->decimal('tMin', 4, 1)->nullable();       // nhiệt độ thấp nhất trung bình, °C
            $t->decimal('rainMm', 6, 1)->nullable();     // tổng mưa trung bình của tháng, mm
            $t->decimal('rainyDays', 4, 1)->nullable();  // số ngày mưa trung bình
            $t->unsignedTinyInteger('years');            // số năm đã lấy trung bình
            $t->unsignedSmallInteger('periodFrom');
            $t->unsignedSmallInteger('periodTo');
            $t->timestamp('fetchedAt')->useCurrent();
            $t->primary(['destinationId', 'month']);
            $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_destination_climate');
    }
};
