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
        Schema::create('tbl_chat', function (Blueprint $table) {
            $table->integer('chatId', true);
            $table->integer('userId')->index('fk_chat_user');
            $table->integer('adminId')->index('fk_chat_admin');
            $table->string('messages');
            $table->enum('readStatus', ['y', 'n'])->nullable()->default('n')->comment('y: yes
n: no');
            $table->timestamp('createdDate')->useCurrentOnUpdate()->useCurrent();
            $table->string('ipAdress', 50);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_chat');
    }
};
