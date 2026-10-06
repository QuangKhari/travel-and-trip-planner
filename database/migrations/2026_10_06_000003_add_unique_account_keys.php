<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kiểm tra trùng TRƯỚC khi sửa gì, để lỗi dừng lại sạch sẽ
        foreach ([['tbl_users', 'username'], ['tbl_users', 'email'], ['tbl_admin', 'userName']] as [$table, $column]) {
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

        Schema::table('tbl_users', function (Blueprint $table) {
            $table->unique('username', 'uniq_users_username');
            $table->unique('email', 'uniq_users_email');
        });

        Schema::table('tbl_admin', function (Blueprint $table) {
            $table->unique('userName', 'uniq_admin_username');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_users', function (Blueprint $table) {
            $table->dropUnique('uniq_users_username');
            $table->dropUnique('uniq_users_email');
        });

        Schema::table('tbl_admin', function (Blueprint $table) {
            $table->dropUnique('uniq_admin_username');
        });
    }
};
