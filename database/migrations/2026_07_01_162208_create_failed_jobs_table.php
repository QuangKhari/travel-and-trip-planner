<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng failed_jobs đã được tạo trong migration create_jobs_table.
    }

    public function down(): void
    {
        // Không xóa bảng failed_jobs do migration khác quản lý.
    }
};