<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nhãn "Phải thử" cho món ăn và điểm đến (hiện trên gx.card). Có guard hasColumn như các migration khác. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tbl_foods', 'tbl_destinations'] as $t) {
            if (Schema::hasTable($t) && !Schema::hasColumn($t, 'isMustTry')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->boolean('isMustTry')->default(false);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['tbl_foods', 'tbl_destinations'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'isMustTry')) {
                Schema::table($t, fn(Blueprint $table) => $table->dropColumn('isMustTry'));
            }
        }
    }
};
