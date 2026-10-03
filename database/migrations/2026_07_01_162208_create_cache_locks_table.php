<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Bảng cache_locks đã được tạo trong migration create_cache_table.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Không xóa bảng cache_locks do migration khác quản lý.
    }
};