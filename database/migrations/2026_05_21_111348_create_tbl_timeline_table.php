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
        Schema::create('tbl_timeline', function (Blueprint $table) {
            $table->integer('timeLineId', true);
            $table->integer('tourId')->index('fk_tour');
            $table->string('tl_title');
            $table->text('tl_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_timeline');
    }
};
