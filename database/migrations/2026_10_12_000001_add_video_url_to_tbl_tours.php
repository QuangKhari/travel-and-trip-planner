<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm cột video giới thiệu cho tour (YouTube / Vimeo / file .mp4).
 * Có guard hasColumn: chạy được nhiều lần, DB trống hay đang có dữ liệu đều được.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_tours') || Schema::hasColumn('tbl_tours', 'video_url')) {
            return;
        }

        Schema::table('tbl_tours', function (Blueprint $table) {
            $table->string('video_url', 255)->nullable()->after('reviews');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_tours') && Schema::hasColumn('tbl_tours', 'video_url')) {
            Schema::table('tbl_tours', function (Blueprint $table) {
                $table->dropColumn('video_url');
            });
        }
    }
};
