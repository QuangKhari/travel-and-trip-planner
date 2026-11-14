<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Xóa chuyến thì xóa luôn người đi cùng, khoản chi và phần chia (như mục lịch, đặt chỗ, thành viên).
 * Trước đó dọn các dòng mồ côi (chuyến đã xóa trước khi có khóa ngoại).
 */
return new class extends Migration
{
    private function hasFk(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)->exists();
    }

    public function up(): void
    {
        if (!Schema::hasTable('tbl_trip_expenses') || DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }
        DB::table('tbl_trip_guests')->whereNotIn('tripId', DB::table('tbl_trips')->select('tripId'))->delete();
        DB::table('tbl_trip_expenses')->whereNotIn('tripId', DB::table('tbl_trips')->select('tripId'))->delete();
        DB::table('tbl_trip_expense_shares')->whereNotIn('expenseId', DB::table('tbl_trip_expenses')->select('expenseId'))->delete();

        if (!$this->hasFk('tbl_trip_guests', 'fk_trip_guests_trip')) {
            Schema::table('tbl_trip_guests', fn(Blueprint $t) => $t->foreign('tripId', 'fk_trip_guests_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete());
        }
        if (!$this->hasFk('tbl_trip_expenses', 'fk_trip_expenses_trip')) {
            Schema::table('tbl_trip_expenses', fn(Blueprint $t) => $t->foreign('tripId', 'fk_trip_expenses_trip')->references('tripId')->on('tbl_trips')->cascadeOnDelete());
        }
        if (!$this->hasFk('tbl_trip_expense_shares', 'fk_expense_shares_expense')) {
            Schema::table('tbl_trip_expense_shares', fn(Blueprint $t) => $t->foreign('expenseId', 'fk_expense_shares_expense')->references('expenseId')->on('tbl_trip_expenses')->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        foreach ([['tbl_trip_expense_shares', 'fk_expense_shares_expense'], ['tbl_trip_expenses', 'fk_trip_expenses_trip'], ['tbl_trip_guests', 'fk_trip_guests_trip']] as [$t, $fk]) {
            if (Schema::hasTable($t) && $this->hasFk($t, $fk)) {
                Schema::table($t, fn(Blueprint $b) => $b->dropForeign($fk));
            }
        }
    }
};
