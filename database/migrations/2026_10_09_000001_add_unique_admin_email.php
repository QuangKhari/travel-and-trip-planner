<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_admin') || !Schema::hasColumn('tbl_admin', 'email')) {
            return;
        }

        if (Schema::hasIndex('tbl_admin', 'uniq_admin_email')) {
            return;
        }

        if (Schema::hasIndex('tbl_admin', ['email'], 'unique')) {
            return;
        }

        $duplicates = DB::table('tbl_admin')
            ->select('email', DB::raw('COUNT(*) AS n'))
            ->whereNotNull('email')
            ->groupBy('email')
            ->having('n', '>', 1)
            ->pluck('n', 'email');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Không thể thêm UNIQUE cho tbl_admin.email vì đang có email trùng: '
                    . $duplicates->keys()->implode(', ')
                    . '. Hãy dọn dữ liệu trùng rồi chạy lại migration.'
            );
        }

        Schema::table('tbl_admin', function (Blueprint $table) {
            $table->unique('email', 'uniq_admin_email');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_admin')) {
            return;
        }

        if (Schema::hasIndex('tbl_admin', ['email'], 'unique')) {
            Schema::table('tbl_admin', function (Blueprint $table) {
                $table->dropUnique('uniq_admin_email');
            });
        }
    }
};
