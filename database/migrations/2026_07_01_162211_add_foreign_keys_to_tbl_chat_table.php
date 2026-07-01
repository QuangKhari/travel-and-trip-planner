<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tbl_chat', function (Blueprint $table) {
            $table->foreign(['adminId'], 'fk_chat_admin')->references(['adminId'])->on('tbl_admin')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['userId'], 'fk_chat_user')->references(['userId'])->on('tbl_users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_chat', function (Blueprint $table) {
            $table->dropForeign('fk_chat_admin');
            $table->dropForeign('fk_chat_user');
        });
    }
};
