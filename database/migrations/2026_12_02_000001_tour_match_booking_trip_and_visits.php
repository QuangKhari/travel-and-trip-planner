<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - tbl_booking.tripId / matchScore: đơn đặt từ banner "có tour giống lịch trình này" (A-05) ghi lại chuyến gốc và điểm Match lúc đặt,
 *   để đo tỷ lệ chuyển đổi Planner → đặt tour trong chương thực nghiệm.
 * - tbl_trip_items.visitedAt: cờ "đã ghé" khi đang đi (null = chưa).
 * - tbl_trip_items.legMode: phương tiện cho chặng đi từ mục này (T-05; null = phương tiện mặc định).
 * - tbl_trip_external.confirmationCode / attach*: xác nhận đặt chỗ NGOÀI Travela (khách sạn, vé...) + tệp đính kèm riêng tư (A-12).
 * - tbl_ai_feedback: chip "Kết quả có đúng ý bạn không?" (A-25), gắn với lượt AI gần nhất trong tbl_ai_runs nếu có.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_booking')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                if (!Schema::hasColumn('tbl_booking', 'tripId')) {
                    $t->integer('tripId')->nullable()->index('idx_booking_trip');
                }
                if (!Schema::hasColumn('tbl_booking', 'matchScore')) {
                    $t->decimal('matchScore', 4, 3)->nullable();
                }
            });
        }
        if (Schema::hasTable('tbl_trip_items') && !Schema::hasColumn('tbl_trip_items', 'visitedAt')) {
            Schema::table('tbl_trip_items', function (Blueprint $t) {
                $t->timestamp('visitedAt')->nullable();
            });
        }
        if (Schema::hasTable('tbl_trip_external')) {
            Schema::table('tbl_trip_external', function (Blueprint $t) {
                if (!Schema::hasColumn('tbl_trip_external', 'confirmationCode')) {
                    $t->string('confirmationCode', 40)->nullable();
                    $t->string('attachPath', 255)->nullable();     // đĩa riêng tư 'proofs', không có URL công khai
                    $t->string('attachName', 120)->nullable();
                    $t->string('attachMime', 60)->nullable();
                }
            });
        }
        if (!Schema::hasTable('tbl_ai_feedback')) {
            Schema::create('tbl_ai_feedback', function (Blueprint $t) {
                $t->bigIncrements('feedbackId');
                $t->integer('userId')->nullable();
                $t->integer('tripId')->nullable();
                $t->unsignedBigInteger('runId')->nullable();
                $t->string('feature', 16);                 // brief | explain | copilot | tour_match
                $t->boolean('helpful');
                $t->string('reason', 30)->nullable();      // wrong_place | wrong_time | not_my_taste | too_generic | other
                $t->string('note', 200)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['feature', 'createdAt']);
                $t->index('runId');
            });
        }
        if (Schema::hasTable('tbl_trip_items') && !Schema::hasColumn('tbl_trip_items', 'legMode')) {
            Schema::table('tbl_trip_items', function (Blueprint $t) {
                $t->string('legMode', 12)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_booking')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                foreach (['tripId', 'matchScore'] as $c) {
                    if (Schema::hasColumn('tbl_booking', $c)) {
                        if ($c === 'tripId') {
                            $t->dropIndex('idx_booking_trip');
                        }
                        $t->dropColumn($c);
                    }
                }
            });
        }
        Schema::dropIfExists('tbl_ai_feedback');
        if (Schema::hasColumn('tbl_trip_external', 'confirmationCode')) {
            Schema::table('tbl_trip_external', fn(Blueprint $t) => $t->dropColumn(['confirmationCode', 'attachPath', 'attachName', 'attachMime']));
        }
        foreach (['visitedAt', 'legMode'] as $c) {
            if (Schema::hasColumn('tbl_trip_items', $c)) {
                Schema::table('tbl_trip_items', fn(Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
