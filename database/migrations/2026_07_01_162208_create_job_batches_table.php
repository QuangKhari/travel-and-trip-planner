<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng job_batches đã được tạo trong migration create_jobs_table.
    }

    public function down(): void
    {
        // Không xóa bảng job_batches do migration khác quản lý.
    }
};