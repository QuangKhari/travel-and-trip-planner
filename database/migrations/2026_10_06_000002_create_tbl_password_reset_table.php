<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_password_reset', function (Blueprint $table) {
            $table->integer('resetId', true);
            $table->integer('userId')->index();
            $table->char('tokenHash', 64)->unique();   // SHA-256 của token; token gốc không lưu
            $table->dateTime('expiresAt');
            $table->dateTime('createdAt')->useCurrent();

            $table->foreign('userId')->references('userId')->on('tbl_users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_password_reset');
    }
};
