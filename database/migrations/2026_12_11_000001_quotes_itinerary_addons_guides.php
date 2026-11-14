<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt 3 (phần tour):
 *  - tbl_tour_quotes: chuyến tự lên -> yêu cầu báo giá tour riêng cho nhóm -> nhân viên báo giá -> khách nhận / từ chối
 *  - tbl_tour_stops: lịch trình tour CÓ CẤU TRÚC (ngày, giờ, điểm, điểm tập trung) thay cho đoạn chữ của tbl_timeline
 *  - tbl_tour_addons, tbl_booking_addons: hoạt động thêm tùy chọn, giá tính ở máy chủ
 *  - tbl_departure_checkins, tbl_guide_links: trang hướng dẫn viên (điểm danh khách, báo sự cố tại chỗ)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_tour_quotes')) {
            Schema::create('tbl_tour_quotes', function (Blueprint $t) {
                $t->increments('quoteId');
                $t->integer('tripId');
                $t->integer('userId');
                $t->unsignedSmallInteger('groupSize');
                $t->decimal('budget', 14, 0)->nullable();
                $t->string('note', 600)->nullable();
                $t->longText('snapshot');                    // lịch lúc gửi yêu cầu (tên chuyến, ngày, mục từng ngày)
                $t->enum('status', ['open', 'quoted', 'accepted', 'declined', 'cancelled'])->default('open');
                $t->decimal('pricePerPerson', 14, 0)->nullable();
                $t->decimal('totalPrice', 14, 0)->nullable();
                $t->string('includes', 600)->nullable();
                $t->string('excludes', 600)->nullable();
                $t->string('message', 1000)->nullable();
                $t->date('validUntil')->nullable();
                $t->integer('quotedBy')->nullable();
                $t->timestamp('quotedAt')->nullable();
                $t->timestamp('decidedAt')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['status', 'createdAt']);
                $t->index('userId');
            });
        }
        if (!Schema::hasTable('tbl_tour_stops')) {
            Schema::create('tbl_tour_stops', function (Blueprint $t) {
                $t->increments('stopId');
                $t->integer('tourId');
                $t->unsignedTinyInteger('dayNo');
                $t->unsignedSmallInteger('startMin')->nullable();
                $t->unsignedSmallInteger('endMin')->nullable();
                $t->enum('kind', ['meet', 'visit', 'meal', 'transfer', 'hotel', 'free', 'drop'])->default('visit');
                $t->string('title', 160);
                $t->string('note', 400)->nullable();
                $t->string('refType', 20)->nullable();
                $t->unsignedBigInteger('refId')->nullable();
                $t->decimal('latitude', 9, 6)->nullable();
                $t->decimal('longitude', 9, 6)->nullable();
                $t->unsignedSmallInteger('orderNo')->default(0);
                $t->index(['tourId', 'dayNo', 'orderNo']);
            });
        }
        if (!Schema::hasTable('tbl_tour_addons')) {
            Schema::create('tbl_tour_addons', function (Blueprint $t) {
                $t->increments('addonId');
                $t->integer('tourId');
                $t->string('name', 160);
                $t->string('description', 400)->nullable();
                $t->decimal('price', 14, 0);
                $t->enum('unit', ['person', 'group'])->default('person');
                $t->boolean('isActive')->default(true);
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['tourId', 'isActive']);
            });
        }
        if (!Schema::hasTable('tbl_booking_addons')) {
            Schema::create('tbl_booking_addons', function (Blueprint $t) {
                $t->integer('bookingId');
                $t->integer('addonId');
                $t->string('name', 160);
                $t->unsignedSmallInteger('qty');
                $t->decimal('unitPrice', 14, 0);
                $t->decimal('amount', 14, 0);
                $t->primary(['bookingId', 'addonId']);
            });
        }
        if (!Schema::hasTable('tbl_guide_links')) {
            Schema::create('tbl_guide_links', function (Blueprint $t) {
                $t->increments('linkId');
                $t->unsignedInteger('availabilityId');
                $t->char('token', 40)->unique();
                $t->string('guideName', 120)->nullable();
                $t->date('expiresOn');
                $t->integer('createdBy')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index('availabilityId');
            });
        }
        if (!Schema::hasTable('tbl_departure_checkins')) {
            Schema::create('tbl_departure_checkins', function (Blueprint $t) {
                $t->unsignedInteger('availabilityId');
                $t->integer('bookingId');
                $t->unsignedTinyInteger('dayNo')->default(1);
                $t->unsignedSmallInteger('present');          // số khách có mặt của đơn
                $t->string('by', 120)->nullable();
                $t->timestamp('checkedAt')->useCurrent();
                $t->primary(['availabilityId', 'bookingId', 'dayNo']);
            });
        }
    }

    public function down(): void
    {
        foreach (['tbl_departure_checkins', 'tbl_guide_links', 'tbl_booking_addons', 'tbl_tour_addons', 'tbl_tour_stops', 'tbl_tour_quotes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
