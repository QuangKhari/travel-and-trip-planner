<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ghi lại mỗi lần dùng mã giảm giá để giới hạn lượt/người và nhả lại khi đơn bị hủy.
 * hạn giữ chỗ cho đơn chờ xác nhận.
 * Đơn n cũ có holdExpiresAt = NULL nên KHÔNG bị tự hủy (không động vào dữ liệu thử cũ).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_promotion_usage')) {
            Schema::create('tbl_promotion_usage', function (Blueprint $table) {
                $table->integer('usageId', true);
                $table->integer('promotionId');
                $table->integer('userId');
                $table->integer('bookingId')->unique('uniq_usage_booking');   // một đơn chỉ dùng một mã
                $table->unsignedBigInteger('discountAmount')->default(0);
                $table->timestamp('createdAt')->useCurrent();

                $table->index(['promotionId', 'userId'], 'idx_usage_promo_user');
                $table->foreign('promotionId', 'fk_usage_promotion')->references('promotionId')->on('tbl_promotion')->cascadeOnDelete();
                $table->foreign('userId', 'fk_usage_user')->references('userId')->on('tbl_users')->restrictOnDelete();
                $table->foreign('bookingId', 'fk_usage_booking')->references('bookingId')->on('tbl_booking')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('tbl_booking', 'holdExpiresAt')) {
            Schema::table('tbl_booking', function (Blueprint $table) {
                $table->timestamp('holdExpiresAt')->nullable()->after('bookingStatus');
                $table->index('holdExpiresAt', 'idx_booking_hold');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_booking', 'holdExpiresAt')) {
            Schema::table('tbl_booking', function (Blueprint $table) {
                $table->dropIndex('idx_booking_hold');
                $table->dropColumn('holdExpiresAt');
            });
        }

        Schema::dropIfExists('tbl_promotion_usage');
    }
};
