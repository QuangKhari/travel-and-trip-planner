<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** bảng => cột thời gian từng khai báo ON UPDATE CURRENT_TIMESTAMP */
    private array $columns = [
        'tbl_booking'     => 'bookingDate',
        'tbl_checkout'    => 'paymentDate',
        'tbl_admin'       => 'createdDate',
        'tbl_reviews'     => 'timestamp',
        'tbl_history'     => 'timestamp',
        'tbl_images'      => 'uploadDate',
        'tbl_chat'        => 'createdDate',
        'tbl_temp_images' => 'uploadDate',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->columns as $table => $column) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }

            $row = DB::selectOne(
                'SELECT EXTRA AS extra FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            // Chỉ sửa khi cột thật sự còn ON UPDATE
            if ($row && stripos($row->extra, 'on update') !== false) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
        }
    }

    public function down(): void
    {
        // Không khôi phục ON UPDATE: đó chính là lỗi cần bỏ.
    }
};