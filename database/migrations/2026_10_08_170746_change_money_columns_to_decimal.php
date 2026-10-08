<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_tours', function (Blueprint $table) {
            $table->decimal('priceAdult', 12, 0)->change();
            $table->decimal('priceChild', 12, 0)->change();
        });

        Schema::table('tbl_booking', function (Blueprint $table) {
            $table->decimal('totalPrice', 12, 0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_tours', function (Blueprint $table) {
            $table->double('priceAdult')->change();
            $table->double('priceChild')->change();
        });

        Schema::table('tbl_booking', function (Blueprint $table) {
            $table->double('totalPrice')->change();
        });
    }
};
