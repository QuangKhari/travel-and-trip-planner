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
        Schema::create('tbl_tours', function (Blueprint $table) {
            $table->integer('tourId', true);
            $table->string('title');
            $table->text('description');
            $table->string('images');
            $table->integer('quantity');
            $table->double('priceAdult');
            $table->double('priceChild');
            $table->string('duration');
            $table->string('destination');
            $table->boolean('availability');
            $table->string('itinerary');
            $table->string('reviews')->nullable();
            $table->date('startDate');
            $table->date('endDate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_tours');
    }
};
