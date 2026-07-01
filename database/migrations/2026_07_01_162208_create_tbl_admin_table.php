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
        Schema::create('tbl_admin', function (Blueprint $table) {
            $table->integer('adminId', true);
            $table->string('userName', 50);
            $table->string('passWord');
            $table->string('email', 50);
            $table->timestamp('createdDate')->useCurrentOnUpdate()->useCurrent();
            $table->string('fullName', 50);
            $table->string('address');
            $table->enum('role', ['admin', 'manager', 'staff'])->default('staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_admin');
    }
};
