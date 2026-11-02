<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hoàn tiền theo bậc (§7.5, A6) và luật mã giảm giá đầy đủ (§6.2 tbl_promotion, D-04).
 *   tbl_refunds: mỗi đơn đã thanh toán bị hủy có đúng một yêu cầu hoàn tiền. status pending -> refunded (admin xử lý tay,
 *                không gọi API hoàn tiền của cổng thanh toán; đây là giới hạn ghi trong báo cáo).
 *                tbl_checkout.paymentStatus đi kèm: y -> refund_pending -> refunded.
 *   tbl_promotion.maxDiscount  số tiền giảm tối đa (VNĐ), NULL = không giới hạn
 *   tbl_promotion.minOrder     giá trị đơn tối thiểu (VNĐ) để dùng mã, 0 = không yêu cầu
 *   tbl_promotion.perUserLimit số lần một tài khoản được dùng mã, NULL = theo cấu hình chung COUPON_MAX_USES_PER_USER
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_refunds')) {
            Schema::create('tbl_refunds', function (Blueprint $t) {
                $t->increments('refundId');
                $t->integer('bookingId');
                $t->decimal('paidAmount', 14, 0);
                $t->unsignedTinyInteger('percent');
                $t->decimal('amount', 14, 0);
                $t->unsignedSmallInteger('daysBefore')->nullable();   // số ngày trước khởi hành lúc hủy
                $t->string('requestedBy', 12);                        // customer | admin
                $t->integer('requestedById')->nullable();
                $t->string('reason', 255)->nullable();
                $t->enum('status', ['pending', 'refunded'])->default('pending');
                $t->string('method', 40)->nullable();                 // chuyển khoản, tiền mặt...
                $t->string('reference', 80)->nullable();              // mã giao dịch hoàn
                $t->string('note', 255)->nullable();
                $t->integer('processedBy')->nullable();
                $t->timestamp('processedAt')->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->unique('bookingId', 'uniq_refund_booking');
                $t->index(['status', 'createdAt'], 'idx_refund_status');
                $t->foreign('bookingId', 'fk_refund_booking')->references('bookingId')->on('tbl_booking')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('tbl_promotion')) {
            Schema::table('tbl_promotion', function (Blueprint $t) {
                if (!Schema::hasColumn('tbl_promotion', 'maxDiscount')) {
                    $t->decimal('maxDiscount', 14, 0)->nullable()->after('discount');
                }
                if (!Schema::hasColumn('tbl_promotion', 'minOrder')) {
                    $t->decimal('minOrder', 14, 0)->default(0)->after('maxDiscount');
                }
                if (!Schema::hasColumn('tbl_promotion', 'perUserLimit')) {
                    $t->unsignedSmallInteger('perUserLimit')->nullable()->after('minOrder');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_refunds');
        if (Schema::hasTable('tbl_promotion')) {
            Schema::table('tbl_promotion', function (Blueprint $t) {
                foreach (['maxDiscount', 'minOrder', 'perUserLimit'] as $c) {
                    if (Schema::hasColumn('tbl_promotion', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
