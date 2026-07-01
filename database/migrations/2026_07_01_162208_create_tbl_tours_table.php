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
            $table->string('time');
            $table->text('description');
            $table->integer('quantity');
            $table->double('priceAdult');
            $table->double('priceChild');
            $table->string('destination');
            $table->enum('domain', ['b', 't', 'n'])->comment('\'b\': Mien Bac
\'t\': Mien Trung
\'n\': Mien Nam');
            $table->boolean('availability');
            $table->date('startDate');
            $table->date('endDate');
            $table->string('reviews')->nullable();
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
