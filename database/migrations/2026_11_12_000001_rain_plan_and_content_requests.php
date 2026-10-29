<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuần 12:
 *  - tbl_trips.rainPlan: phương án dự phòng khi mưa, JSON {"<ngày>": [{"type":"experience|place","refId":N}, ...]}.
 *  - tbl_content_requests: những thứ người dùng tìm/nhập mà kho nội dung chưa có (địa điểm, điểm đến), đếm số lần,
 *    để biên tập viên biết nên viết gì tiếp. Chạy lại an toàn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_trips') && !Schema::hasColumn('tbl_trips', 'rainPlan')) {
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->text('rainPlan')->nullable());
        }

        if (!Schema::hasTable('tbl_content_requests')) {
            Schema::create('tbl_content_requests', function (Blueprint $t) {
                $t->bigIncrements('requestId');
                $t->string('folded', 100)->unique();                 // cụm đã bỏ dấu, chữ thường: "phu yen", "bai xep"
                $t->string('sample', 120);                           // cách viết đầu tiên của người dùng
                $t->string('kind', 20)->default('place');            // place | destination
                $t->unsignedInteger('hits')->default(0);
                $t->integer('nearestDestinationId')->nullable();     // điểm đến gần nhất đã có dữ liệu (nếu tính được)
                $t->timestamp('firstAt')->useCurrent();
                $t->timestamp('lastAt')->nullable();
                $t->boolean('resolved')->default(false);             // biên tập viên đã xử lý
                $t->index(['resolved', 'hits']);
                $t->foreign('nearestDestinationId')->references('destinationId')->on('tbl_destinations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_content_requests');
        if (Schema::hasTable('tbl_trips') && Schema::hasColumn('tbl_trips', 'rainPlan')) {
            Schema::table('tbl_trips', fn(Blueprint $t) => $t->dropColumn('rainPlan'));
        }
    }
};
