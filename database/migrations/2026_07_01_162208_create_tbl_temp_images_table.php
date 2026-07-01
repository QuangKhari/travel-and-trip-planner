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
        Schema::create('tbl_temp_images', function (Blueprint $table) {
            $table->integer('imageId', true);
            $table->integer('tourId');
            $table->string('imageTempURL');
            $table->string('description')->nullable();
            $table->timestamp('uploadDate')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_temp_images');
    }
};
