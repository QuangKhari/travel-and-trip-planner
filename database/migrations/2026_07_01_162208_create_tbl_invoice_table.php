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
        Schema::create('tbl_invoice', function (Blueprint $table) {
            $table->integer('invoiceId', true);
            $table->integer('bookingId')->index('fk_invoice_booking');
            $table->double('amount');
            $table->date('dateIssued');
            $table->string('details');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_invoice');
    }
};
