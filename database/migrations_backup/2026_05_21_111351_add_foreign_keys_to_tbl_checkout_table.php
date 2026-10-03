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
        Schema::table('tbl_checkout', function (Blueprint $table) {
            $table->foreign(['bookingId'], 'fk_checkout_booking')->references(['bookingId'])->on('tbl_booking')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_checkout', function (Blueprint $table) {
            $table->dropForeign('fk_checkout_booking');
        });
    }
};
