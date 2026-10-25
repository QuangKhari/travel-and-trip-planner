<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tuần 1 (mục 2 và 0.1-C/D/K): cột còn thiếu của tbl_experiences để Planner, bản đồ và ngân sách dùng được. */
return new class extends Migration
{
    private const COLUMNS = ['monthFrom', 'monthTo', 'priceNote', 'priceMin', 'priceMax', 'durationMin', 'difficulty', 'setting', 'suitableFor', 'imageSourceUrl', 'latitude', 'longitude'];

    public function up(): void
    {
        if (!Schema::hasTable('tbl_experiences')) {
            return;
        }
        Schema::table('tbl_experiences', function (Blueprint $t) {
            $add = fn (string $c) => !Schema::hasColumn('tbl_experiences', $c);
            $add('monthFrom') && $t->unsignedTinyInteger('monthFrom')->nullable();
            $add('monthTo') && $t->unsignedTinyInteger('monthTo')->nullable();
            $add('priceNote') && $t->string('priceNote', 160)->nullable();           // chữ để hiển thị
            $add('priceMin') && $t->decimal('priceMin', 14, 2)->nullable();          // số, chỉ nhập khi có nguồn
            $add('priceMax') && $t->decimal('priceMax', 14, 2)->nullable();
            $add('durationMin') && $t->unsignedSmallInteger('durationMin')->nullable(); // phút; cột chữ `duration` giữ để hiển thị
            $add('difficulty') && $t->enum('difficulty', ['easy', 'moderate', 'hard'])->nullable();
            $add('setting') && $t->enum('setting', ['indoor', 'outdoor', 'both'])->nullable();
            $add('suitableFor') && $t->string('suitableFor', 160)->nullable();
            $add('imageSourceUrl') && $t->string('imageSourceUrl', 255)->nullable();
            $add('latitude') && $t->decimal('latitude', 9, 6)->nullable();           // chỉ có khi xác minh được vị trí riêng
            $add('longitude') && $t->decimal('longitude', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_experiences')) {
            return;
        }
        Schema::table('tbl_experiences', function (Blueprint $t) {
            foreach (self::COLUMNS as $c) {
                if (Schema::hasColumn('tbl_experiences', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
