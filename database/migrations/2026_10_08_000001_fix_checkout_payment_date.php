<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_checkout') || !Schema::hasColumn('tbl_checkout', 'paymentDate')) {
            return;
        }

        DB::statement("
            ALTER TABLE tbl_checkout
            MODIFY paymentDate TIMESTAMP NULL DEFAULT NULL
        ");

        DB::table('tbl_checkout')
            ->where('paymentStatus', '!=', 'y')
            ->update(['paymentDate' => null]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_checkout') || !Schema::hasColumn('tbl_checkout', 'paymentDate')) {
            return;
        }

        DB::statement("
            ALTER TABLE tbl_checkout
            MODIFY paymentDate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ");
    }
};
