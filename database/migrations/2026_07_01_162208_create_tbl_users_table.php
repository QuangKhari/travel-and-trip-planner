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
        Schema::create('tbl_users', function (Blueprint $table) {
            $table->integer('userId', true);
            $table->string('fullName', 50);
            $table->string('username', 50);
            $table->string('password', 50);
            $table->string('email');
            $table->string('avatar')->nullable();
            $table->string('phoneNumber', 15)->nullable();
            $table->string('address')->nullable();
            $table->string('ipAdress', 50)->nullable();
            $table->enum('isActive', ['y', 'n'])->default('n')->comment('y: yes
n: no');
            $table->enum('status', ['d', 'b'])->nullable()->comment('d: deleted
b: baned');
            $table->timestamp('createdDate')->useCurrent();
            $table->timestamp('updatedDate')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_users');
    }
};
