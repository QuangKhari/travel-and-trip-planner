<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuần 2 (mục 3, 4, 5, 7 của kế hoạch v6). Chạy lại được: mỗi cột kiểm tra hasColumn.
 *  - tbl_events.isMustTry: "Lễ hội phải dự" do biên tập đánh dấu, không suy diễn từ việc lễ hội tồn tại.
 *  - tbl_external_places.isMustTry và description: "Địa điểm phải ghé" và lời giới thiệu ngắn do biên tập viết.
 *    (Không thêm imageCredit cho địa điểm vì bảng chưa có cột ảnh.)
 *  - tbl_foods.placeId: trỏ tới địa điểm ăn uống đã có tọa độ xác minh; monthFrom/monthTo: mùa ngon; whereToTry: chữ "nơi nên thử".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_events', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_events', 'isMustTry')) {
                $t->boolean('isMustTry')->default(false);
            }
        });

        Schema::table('tbl_external_places', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_external_places', 'isMustTry')) {
                $t->boolean('isMustTry')->default(false);
            }
            if (!Schema::hasColumn('tbl_external_places', 'description')) {
                $t->text('description')->nullable();
            }
        });

        Schema::table('tbl_foods', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_foods', 'monthFrom')) {
                $t->unsignedTinyInteger('monthFrom')->nullable();
            }
            if (!Schema::hasColumn('tbl_foods', 'monthTo')) {
                $t->unsignedTinyInteger('monthTo')->nullable();
            }
            if (!Schema::hasColumn('tbl_foods', 'whereToTry')) {
                $t->string('whereToTry', 255)->nullable();
            }
        });
        if (!Schema::hasColumn('tbl_foods', 'placeId')) {
            Schema::table('tbl_foods', function (Blueprint $t) {
                $t->unsignedBigInteger('placeId')->nullable();
                $t->index('placeId', 'idx_foods_place');
                $t->foreign('placeId')->references('placeId')->on('tbl_external_places')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_foods', 'placeId')) {
            Schema::table('tbl_foods', fn (Blueprint $t) => $t->dropForeign(['placeId']));
            Schema::table('tbl_foods', fn (Blueprint $t) => $t->dropIndex('idx_foods_place'));
        }
        Schema::table('tbl_foods', function (Blueprint $t) {
            foreach (['placeId', 'monthFrom', 'monthTo', 'whereToTry'] as $c) {
                if (Schema::hasColumn('tbl_foods', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        Schema::table('tbl_external_places', function (Blueprint $t) {
            foreach (['isMustTry', 'description'] as $c) {
                if (Schema::hasColumn('tbl_external_places', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        Schema::table('tbl_events', function (Blueprint $t) {
            if (Schema::hasColumn('tbl_events', 'isMustTry')) {
                $t->dropColumn('isMustTry');
            }
        });
    }
};
