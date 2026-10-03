<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tbl_images: thêm siêu dữ liệu để kiểm soát dung lượng và thứ tự ảnh (v8.6 §6.2/§6.4).
 * Có guard hasTable/hasColumn: chạy được trên DB rỗng lẫn DB đang có dữ liệu.
 * KHÔNG lưu BLOB; imageURL chỉ là đường dẫn tương đối.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_images')) {
            return;
        }

        Schema::table('tbl_images', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_images', 'width')) {
                $table->unsignedSmallInteger('width')->nullable()->after('imageURL');
            }
            if (!Schema::hasColumn('tbl_images', 'height')) {
                $table->unsignedSmallInteger('height')->nullable()->after('width');
            }
            if (!Schema::hasColumn('tbl_images', 'sizeBytes')) {
                $table->unsignedInteger('sizeBytes')->nullable()->after('height');
            }
            if (!Schema::hasColumn('tbl_images', 'sortOrder')) {
                $table->unsignedSmallInteger('sortOrder')->default(0)->after('sizeBytes');
            }
            if (!Schema::hasColumn('tbl_images', 'isCover')) {
                $table->boolean('isCover')->default(false)->after('sortOrder');
            }
        });

        // Bỏ ON UPDATE CURRENT_TIMESTAMP ở uploadDate (lỗi F-01): ngày upload không được đổi khi sửa dòng.
        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('tbl_images', 'uploadDate')) {
            DB::statement('ALTER TABLE tbl_images MODIFY uploadDate TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_images')) {
            return;
        }

        Schema::table('tbl_images', function (Blueprint $table) {
            foreach (['isCover', 'sortOrder', 'sizeBytes', 'height', 'width'] as $col) {
                if (Schema::hasColumn('tbl_images', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};