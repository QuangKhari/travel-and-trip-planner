<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giờ mở cửa (P1). Mô hình tối giản cho đồ án: mỗi dòng là một khoảng mở trong một thứ của tuần.
 *  - refType/refId : place (tbl_external_places) | experience (tbl_experiences)
 *  - dayOfWeek     : 1 = Thứ Hai ... 7 = Chủ nhật (ISO-8601)
 *  - openMin/closeMin: phút kể từ 00:00; một ngày có thể có nhiều khoảng (nghỉ trưa)
 *  - isClosed      : cả ngày đóng cửa (khi đó openMin/closeMin null)
 * Thứ không có dòng nào = chưa biết giờ, Planner không cảnh báo.
 * Chưa xử lý ngày lễ, theo mùa, đóng tạm thời (ngoài phạm vi đồ án).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_opening_hours')) {
            return;
        }
        Schema::create('tbl_opening_hours', function (Blueprint $t) {
            $t->id('hoursId');
            $t->string('refType', 20);
            $t->unsignedBigInteger('refId');
            $t->unsignedTinyInteger('dayOfWeek');
            $t->unsignedSmallInteger('openMin')->nullable();
            $t->unsignedSmallInteger('closeMin')->nullable();
            $t->boolean('isClosed')->default(false);
            $t->string('source', 20)->default('manual');   // manual | osm
            $t->timestamp('updatedAt')->nullable();
            $t->index(['refType', 'refId']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_opening_hours');
    }
};
