<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gói tuần 5: liên kết dữ liệu + nền cho Trip Planner.
 *
 *  1. tbl_food_destination: một món có thể có ở nhiều điểm đến (cột tbl_foods.destinationId giữ vai "nơi chính").
 *     Chép sẵn các cặp hiện có vào bảng mới.
 *  2. tbl_experiences: thêm các cột Planner/AI cần để lọc (thời lượng phút, trong nhà, độ khó, mức giá, mùa).
 *  3. tbl_trip_items: cho phép refType = experience; thêm ghi chú và chi phí dự kiến của từng mục.
 *  4. tbl_trips: shareToken để chia sẻ chuyến đi bằng liên kết chỉ đọc.
 *
 * Chỉ chạy trên MySQL (cùng môi trường với các migration trước của dự án).
 */
return new class extends Migration {
    private const ENUM_NEW = "ENUM('destination','place','event','food','tour','experience') NOT NULL";
    private const ENUM_OLD = "ENUM('destination','place','event','food','tour') NOT NULL";

    public function up(): void
    {
        if (!Schema::hasTable('tbl_food_destination')) {
            Schema::create('tbl_food_destination', function (Blueprint $t) {
                $t->integer('foodId');
                $t->integer('destinationId');
                $t->primary(['foodId', 'destinationId']);
                $t->index('destinationId');
                $t->foreign('foodId')->references('foodId')->on('tbl_foods')->cascadeOnDelete();
                $t->foreign('destinationId')->references('destinationId')->on('tbl_destinations')->cascadeOnDelete();
            });
            DB::statement('INSERT IGNORE INTO tbl_food_destination (foodId, destinationId) SELECT foodId, destinationId FROM tbl_foods WHERE destinationId IS NOT NULL');
        }

        $has = fn(string $c) => Schema::hasColumn('tbl_experiences', $c);
        Schema::table('tbl_experiences', function (Blueprint $t) use ($has) {
            if (!$has('durationMinutes')) {
                $t->unsignedSmallInteger('durationMinutes')->nullable()->after('duration');   // phút; 0..65535
            }
            if (!$has('indoor')) {
                $t->boolean('indoor')->nullable()->after('durationMinutes');                  // null = chưa biết
            }
            if (!$has('difficulty')) {
                $t->enum('difficulty', ['easy', 'medium', 'hard'])->nullable()->after('indoor');
            }
            if (!$has('priceLevel')) {
                $t->unsignedTinyInteger('priceLevel')->nullable()->after('difficulty');       // 1 rẻ .. 4 đắt
            }
            if (!$has('monthFrom')) {
                $t->unsignedTinyInteger('monthFrom')->nullable()->after('priceLevel');        // mùa phù hợp: từ tháng
            }
            if (!$has('monthTo')) {
                $t->unsignedTinyInteger('monthTo')->nullable()->after('monthFrom');           // đến tháng (có thể nhỏ hơn monthFrom: qua năm)
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tbl_trip_items MODIFY refType ' . self::ENUM_NEW);
        }
        Schema::table('tbl_trip_items', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_trip_items', 'note')) {
                $t->string('note', 255)->nullable();
            }
            if (!Schema::hasColumn('tbl_trip_items', 'cost')) {
                $t->decimal('cost', 14, 2)->nullable();
            }
        });

        Schema::table('tbl_trips', function (Blueprint $t) {
            if (!Schema::hasColumn('tbl_trips', 'shareToken')) {
                $t->char('shareToken', 32)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_trips', function (Blueprint $t) {
            if (Schema::hasColumn('tbl_trips', 'shareToken')) {
                $t->dropUnique(['shareToken']);
                $t->dropColumn('shareToken');
            }
        });

        Schema::table('tbl_trip_items', function (Blueprint $t) {
            foreach (['note', 'cost'] as $c) {
                if (Schema::hasColumn('tbl_trip_items', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        if (DB::getDriverName() === 'mysql') {
            DB::table('tbl_trip_items')->where('refType', 'experience')->delete();
            DB::statement('ALTER TABLE tbl_trip_items MODIFY refType ' . self::ENUM_OLD);
        }

        Schema::table('tbl_experiences', function (Blueprint $t) {
            foreach (['monthTo', 'monthFrom', 'priceLevel', 'difficulty', 'indoor', 'durationMinutes'] as $c) {
                if (Schema::hasColumn('tbl_experiences', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        Schema::dropIfExists('tbl_food_destination');
    }
};
