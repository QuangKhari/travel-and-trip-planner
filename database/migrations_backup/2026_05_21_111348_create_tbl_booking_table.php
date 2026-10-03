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
        Schema::create('tbl_booking', function (Blueprint $table) {
            $table->integer('bookingId', true);
            $table->integer('tourId')->index('fk_booking_tour');
            $table->integer('userId');
            $table->date('bookingDate');
            $table->integer('numAdults');
            $table->integer('numChildren');
            $table->double('totalPrice');
            $table->string('bookingStatus');
            $table->string('specialRequestes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_booking');
    }
};
