<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuần 1 (mục 0.1-C/D): cột còn thiếu của tbl_experiences. Migration 2026_10_26_000001_link_content_and_planner đã thêm
 * durationMinutes, indoor, difficulty (easy/medium/hard), priceLevel, monthFrom, monthTo nên KHÔNG thêm lại ở đây
 * (bản trước của gói này có durationMin và setting, trùng nghĩa với durationMinutes và indoor, đã bỏ).
 * Chạy lại được: mỗi cột kiểm tra hasColumn.
 */
return new class extends Migration
{
    private const COLUMNS = ['priceNote', 'priceMin', 'priceMax', 'suitableFor', 'imageSourceUrl', 'latitude', 'longitude'];

    public function up(): void
    {
        if (!Schema::hasTable('tbl_experiences')) {
            return;
        }
        Schema::table('tbl_experiences', function (Blueprint $t) {
            $add = fn (string $c) => !Schema::hasColumn('tbl_experiences', $c);
            $add('priceNote') && $t->string('priceNote', 160)->nullable();                // chữ để hiển thị, chỉ khi có nguồn
            $add('priceMin') && $t->decimal('priceMin', 14, 2)->nullable();               // số, chỉ nhập khi có nguồn
            $add('priceMax') && $t->decimal('priceMax', 14, 2)->nullable();
            $add('suitableFor') && $t->string('suitableFor', 160)->nullable();           // vd "Gia đình, cặp đôi"
            $add('imageSourceUrl') && $t->string('imageSourceUrl', 255)->nullable();
            $add('latitude') && $t->decimal('latitude', 9, 6)->nullable();                // chỉ có khi xác minh được vị trí riêng
            $add('longitude') && $t->decimal('longitude', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_experiences')) {
            return;
        }
        Schema::table('tbl_experiences', function (Blueprint $t) {
            foreach (self::COLUMNS as $c) {
                if (Schema::hasColumn('tbl_experiences', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
