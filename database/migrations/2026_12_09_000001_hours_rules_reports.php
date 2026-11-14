<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giờ mở cửa nâng cao + người dùng báo sai:
 *  - tbl_hours_rules: đóng cửa tạm thời / giờ theo mùa cho MỘT nơi hoặc CẢ điểm đến, trong một khoảng ngày (có thể lặp hằng năm)
 *  - tbl_place_reports: người dùng báo "giờ sai / giá sai / đã đóng cửa hẳn / sai vị trí", admin duyệt rồi cập nhật
 * Ngày lễ dùng dòng dayOfWeek = 8 ("PH" trong cú pháp OpenStreetMap) của tbl_opening_hours, không cần bảng mới.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_hours_rules')) {
            Schema::create('tbl_hours_rules', function (Blueprint $t) {
                $t->increments('ruleId');
                $t->string('refType', 20)->nullable();      // place | experience ; null = cả điểm đến
                $t->unsignedBigInteger('refId')->nullable();
                $t->integer('destinationId')->nullable();
                $t->enum('kind', ['closed', 'hours']);
                $t->date('dateFrom');
                $t->date('dateTo');
                $t->boolean('yearly')->default(false);       // lặp mỗi năm theo ngày-tháng (giờ mùa hè / mùa đông)
                $t->unsignedSmallInteger('openMin')->nullable();
                $t->unsignedSmallInteger('closeMin')->nullable();
                $t->string('note', 200)->nullable();
                $t->string('source', 30)->default('manual');
                $t->integer('createdBy')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['refType', 'refId']);
                $t->index(['destinationId', 'dateFrom']);
            });
        }
        if (!Schema::hasTable('tbl_place_reports')) {
            Schema::create('tbl_place_reports', function (Blueprint $t) {
                $t->increments('reportId');
                $t->string('refType', 20);
                $t->unsignedBigInteger('refId');
                $t->integer('userId')->nullable();
                $t->enum('kind', ['hours', 'price', 'closed', 'location', 'other']);
                $t->string('detail', 500)->nullable();
                $t->string('suggested', 120)->nullable();     // vd. "Mo-Su 08:00-17:00" hoặc "50000"
                $t->integer('tripId')->nullable();
                $t->enum('status', ['open', 'accepted', 'rejected'])->default('open');
                $t->integer('reviewedBy')->nullable();
                $t->timestamp('reviewedAt')->nullable();
                $t->string('reviewNote', 255)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['status', 'createdAt']);
                $t->index(['refType', 'refId']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_place_reports');
        Schema::dropIfExists('tbl_hours_rules');
    }
};
