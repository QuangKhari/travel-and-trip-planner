<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A4: địa điểm OSM vào ở trạng thái draft (chỉ hiển thị khi đã duyệt, làm ở B3).
 * A6: ảnh món/lễ hội cần lưu trang gốc trên Commons (imageSourceUrl) như ảnh điểm đến.
 * A3: lễ hội có ngày không cố định cần dòng ghi chú (dateNote).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tbl_external_places', function (Blueprint $t) {
            $t->enum('reviewStatus', ['draft', 'reviewed', 'published'])->default('draft')->after('website');
            $t->index(['destinationId', 'reviewStatus']);
        });
        Schema::table('tbl_foods', function (Blueprint $t) {
            $t->string('imageSourceUrl', 255)->nullable()->after('imageCredit');
        });
        Schema::table('tbl_events', function (Blueprint $t) {
            $t->string('imageSourceUrl', 255)->nullable()->after('imageCredit');
            $t->string('dateNote', 255)->nullable()->after('durationDays');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_events', fn(Blueprint $t) => $t->dropColumn(['imageSourceUrl', 'dateNote']));
        Schema::table('tbl_foods', fn(Blueprint $t) => $t->dropColumn('imageSourceUrl'));
        Schema::table('tbl_external_places', function (Blueprint $t) {
            $t->dropIndex(['destinationId', 'reviewStatus']);
            $t->dropColumn('reviewStatus');
        });
    }
};
