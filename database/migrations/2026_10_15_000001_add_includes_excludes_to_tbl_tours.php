<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TUỲ CHỌN: thêm 2 cột để trang chi tiết tour hiện mục "Bao gồm / Không bao gồm".
 * Mỗi dòng = một mục. Chưa có dữ liệu thì mục này tự ẩn (trang không bị trống/sai).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tbl_tours', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_tours', 'includes')) {
                $table->text('includes')->nullable();
            }
            if (!Schema::hasColumn('tbl_tours', 'excludes')) {
                $table->text('excludes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_tours', function (Blueprint $table) {
            $table->dropColumn(['includes', 'excludes']);
        });
    }
};
