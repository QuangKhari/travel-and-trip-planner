<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->widen('tbl_users', 'password');
        $this->widen('tbl_admin', 'passWord');
    }

    // Không thu hẹp lại: cột ngắn sẽ cắt mất hash bcrypt (60 ký tự)
    public function down(): void {}

    private function widen(string $table, string $column): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        $row = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        // Chỉ sửa khi cột còn ngắn hơn 255
        if ($row && (int) $row->len < 255) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(255) NOT NULL");
        }
    }
};
