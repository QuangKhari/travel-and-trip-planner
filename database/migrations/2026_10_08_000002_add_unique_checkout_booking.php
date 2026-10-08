<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_checkout') || !Schema::hasColumn('tbl_checkout', 'bookingId')) {
            return;
        }

        $duplicates = DB::table('tbl_checkout')
            ->select('bookingId', DB::raw('COUNT(*) AS total'))
            ->groupBy('bookingId')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Không thể thêm UNIQUE tbl_checkout.bookingId vì đang có checkout trùng booking.'
            );
        }

        Schema::table('tbl_checkout', function (Blueprint $table) {
            $table->unique('bookingId', 'uniq_checkout_booking');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_checkout', function (Blueprint $table) {
            $table->dropUnique('uniq_checkout_booking');
        });
    }
};
