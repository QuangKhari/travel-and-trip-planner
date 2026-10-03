<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng password_reset_tokens đã được tạo trong migration create_users_table.
    }

    public function down(): void
    {
        // Không xóa bảng password_reset_tokens do migration khác quản lý.
    }
};