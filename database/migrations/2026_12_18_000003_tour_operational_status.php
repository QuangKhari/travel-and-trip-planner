<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái vận hành của tour: draft (còn lỗi chặn trong lịch trình theo giờ) | ready (đủ điều kiện, chưa đăng) | published (đang bán).
 * Đăng tour / hiện lại tour bị chặn khi lịch trình theo giờ còn lỗi chặn (TourStops::classify). Tour cũ chưa có lịch theo giờ vẫn bán được.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tbl_tours') && !Schema::hasColumn('tbl_tours', 'operationalStatus')) {
            Schema::table('tbl_tours', function (Blueprint $t) {
                $t->string('operationalStatus', 12)->default('draft')->index('idx_tour_opstatus');
            });
            DB::table('tbl_tours')->where('availability', 1)->update(['operationalStatus' => 'published']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_tours', 'operationalStatus')) {
            Schema::table('tbl_tours', function (Blueprint $t) {
                $t->dropIndex('idx_tour_opstatus');
                $t->dropColumn('operationalStatus');
            });
        }
    }
};
