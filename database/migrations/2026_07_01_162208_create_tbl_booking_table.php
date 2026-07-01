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
            $table->string('fullName');
            $table->string('email', 50);
            $table->string('phoneNumber', 50);
            $table->string('address');
            $table->timestamp('bookingDate')->useCurrentOnUpdate()->useCurrent();
            $table->integer('numChildren');
            $table->integer('numAdults');
            $table->double('totalPrice');
            $table->enum('bookingStatus', ['n', 'y', 'f', 'c'])->default('n');
            $table->string('bookingCode', 50);
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
