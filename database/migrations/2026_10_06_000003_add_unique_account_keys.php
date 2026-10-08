<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([['tbl_users', 'username'], ['tbl_users', 'email'], ['tbl_admin', 'userName']] as [$table, $column]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                throw new RuntimeException(
                    "Không tìm thấy {$table}.{$column}, không thể thêm UNIQUE."
                );
            }

            $duplicates = DB::table($table)
                ->select($column, DB::raw('COUNT(*) AS n'))
                ->groupBy($column)
                ->having('n', '>', 1)
                ->pluck('n', $column);

            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException(
                    "Không thể thêm UNIQUE: {$table}.{$column} đang có giá trị trùng: "
                        . $duplicates->keys()->implode(', ') . '. Hãy dọn dữ liệu trùng rồi chạy lại.'
                );
            }
        }

        if (!Schema::hasIndex('tbl_users', 'uniq_users_username')) {
            Schema::table('tbl_users', function (Blueprint $table) {
                $table->unique('username', 'uniq_users_username');
            });
        }

        if (!Schema::hasIndex('tbl_users', 'uniq_users_email')) {
            Schema::table('tbl_users', function (Blueprint $table) {
                $table->unique('email', 'uniq_users_email');
            });
        }

        if (!Schema::hasIndex('tbl_admin', 'uniq_admin_username')) {
            Schema::table('tbl_admin', function (Blueprint $table) {
                $table->unique('userName', 'uniq_admin_username');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_users') && Schema::hasIndex('tbl_users', 'uniq_users_username')) {
            Schema::table('tbl_users', function (Blueprint $table) {
                $table->dropUnique('uniq_users_username');
            });
        }

        if (Schema::hasTable('tbl_users') && Schema::hasIndex('tbl_users', 'uniq_users_email')) {
            Schema::table('tbl_users', function (Blueprint $table) {
                $table->dropUnique('uniq_users_email');
            });
        }

        if (Schema::hasTable('tbl_admin') && Schema::hasIndex('tbl_admin', 'uniq_admin_username')) {
            Schema::table('tbl_admin', function (Blueprint $table) {
                $table->dropUnique('uniq_admin_username');
            });
        }
    }
};
