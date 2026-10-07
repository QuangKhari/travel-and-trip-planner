<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * khóa ngoại tbl_booking.userId, UNIQUE bookingCode, UNIQUE mã khuyến mãi,
 * UNIQUE (userId, tourId) cho đánh giá.
 * Kiểm tra dữ liệu TRƯỚC, nếu có dòng vi phạm thì dừng và nói rõ dòng nào để bạn dọn rồi chạy lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        $problems = [];

        // 1. Trùng lặp
        $duplicates = [
            ['tbl_booking',   ['bookingCode']],
            ['tbl_promotion', ['code']],
            ['tbl_reviews',   ['userId', 'tourId']],
        ];

        foreach ($duplicates as [$table, $columns]) {
            $rows = DB::table($table)
                ->select($columns)
                ->selectRaw('COUNT(*) AS n')
                ->groupBy($columns)
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($rows as $row) {
                $values = collect($columns)->map(fn($c) => "{$c}={$row->$c}")->implode(', ');
                $problems[] = "{$table}: trùng ({$values}) x{$row->n}";
            }
        }

        // 2. Đơn đặt tour trỏ tới user không tồn tại
        $orphans = DB::table('tbl_booking as b')
            ->leftJoin('tbl_users as u', 'u.userId', '=', 'b.userId')
            ->whereNull('u.userId')
            ->pluck('b.bookingId');

        if ($orphans->isNotEmpty()) {
            $problems[] = 'tbl_booking: userId không tồn tại ở bookingId ' . $orphans->implode(', ');
        }

        if ($problems) {
            throw new RuntimeException(
                "Không thể thêm ràng buộc, hãy dọn dữ liệu rồi chạy lại:\n - " . implode("\n - ", $problems)
            );
        }

        // Mỗi ràng buộc chỉ thêm nếu chưa có, nên chạy lại sau khi bị dừng giữa chừng vẫn an toàn
        $hasFk = collect(Schema::getForeignKeys('tbl_booking'))
            ->contains(fn($fk) => $fk['name'] === 'fk_booking_user');

        if (!Schema::hasIndex('tbl_booking', 'uniq_booking_code')) {
            Schema::table('tbl_booking', function (Blueprint $table) {
                $table->unique('bookingCode', 'uniq_booking_code');
            });
        }

        if (!$hasFk) {
            Schema::table('tbl_booking', function (Blueprint $table) {
                $table->foreign('userId', 'fk_booking_user')
                    ->references('userId')->on('tbl_users')
                    ->restrictOnUpdate()->restrictOnDelete();
            });
        }

        if (!Schema::hasIndex('tbl_promotion', 'uniq_promotion_code')) {
            Schema::table('tbl_promotion', function (Blueprint $table) {
                $table->unique('code', 'uniq_promotion_code');
            });
        }

        if (!Schema::hasIndex('tbl_reviews', 'uniq_review_user_tour')) {
            Schema::table('tbl_reviews', function (Blueprint $table) {
                $table->unique(['userId', 'tourId'], 'uniq_review_user_tour');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tbl_reviews', function (Blueprint $table) {
            $table->dropUnique('uniq_review_user_tour');
        });

        Schema::table('tbl_promotion', function (Blueprint $table) {
            $table->dropUnique('uniq_promotion_code');
        });

        Schema::table('tbl_booking', function (Blueprint $table) {
            $table->dropForeign('fk_booking_user');
            $table->dropUnique('uniq_booking_code');
        });
    }
};
