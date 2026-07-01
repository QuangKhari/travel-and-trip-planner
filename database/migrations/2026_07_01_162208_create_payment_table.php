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
        Schema::create('payment', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('booking_id')->index('payments_booking_id_foreign');
            $table->integer('amount');
            $table->enum('status', ['pending', 'paid', 'expired'])->default('pending');
            $table->string('qr_token');
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment');
    }
};
