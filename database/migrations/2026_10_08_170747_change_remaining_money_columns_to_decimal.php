<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_checkout', function (Blueprint $table) {
            $table->decimal('amount', 12, 0)->change();
        });

        Schema::table('tbl_invoice', function (Blueprint $table) {
            $table->decimal('amount', 12, 0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_checkout', function (Blueprint $table) {
            $table->double('amount')->change();
        });

        Schema::table('tbl_invoice', function (Blueprint $table) {
            $table->double('amount')->change();
        });
    }
};
