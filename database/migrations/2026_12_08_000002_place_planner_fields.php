<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Địa điểm (tbl_external_places) thêm những gì bộ xếp lịch cần mà trước đây phải đoán theo loại nơi:
 * thời gian tham quan, giá vé (khoảng), trong nhà / ngoài trời. Để trống = vẫn đoán như cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_external_places', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_external_places', 'durationMinutes')) {
                $t->unsignedSmallInteger('durationMinutes')->nullable()->after('rank');
            }
            if (!Schema::hasColumn('tbl_external_places', 'priceMin')) {
                $t->decimal('priceMin', 14, 2)->nullable()->after('durationMinutes');
                $t->decimal('priceMax', 14, 2)->nullable()->after('priceMin');
            }
            if (!Schema::hasColumn('tbl_external_places', 'indoor')) {
                $t->boolean('indoor')->nullable()->after('priceMax');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_external_places', function (Blueprint $t) {
            foreach (['durationMinutes', 'priceMin', 'priceMax', 'indoor'] as $c) {
                if (Schema::hasColumn('tbl_external_places', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
