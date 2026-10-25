<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** tbl_experiences cần cùng các cột duyệt/nổi bật như 5 bảng nội dung khác (xem 2026_10_25_000001_admin_content_tools). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_experiences')) {
            return;
        }
        Schema::table('tbl_experiences', function (Blueprint $b) {
            if (!Schema::hasColumn('tbl_experiences', 'reviewedBy')) {
                $b->integer('reviewedBy')->nullable();
            }
            if (!Schema::hasColumn('tbl_experiences', 'reviewedAt')) {
                $b->timestamp('reviewedAt')->nullable();
            }
            if (!Schema::hasColumn('tbl_experiences', 'reviewNote')) {
                $b->string('reviewNote', 255)->nullable();
            }
            if (!Schema::hasColumn('tbl_experiences', 'isFeatured')) {
                $b->boolean('isFeatured')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_experiences')) {
            return;
        }
        Schema::table('tbl_experiences', function (Blueprint $b) {
            foreach (['reviewedBy', 'reviewedAt', 'reviewNote', 'isFeatured'] as $c) {
                if (Schema::hasColumn('tbl_experiences', $c)) {
                    $b->dropColumn($c);
                }
            }
        });
    }
};
