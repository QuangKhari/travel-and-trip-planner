<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - tbl_booking_adjustments.gateway*: hoàn phần dư khi GIẢM số khách qua API cổng (VNPay / MoMo), cùng cơ chế an toàn với tbl_refunds
 *   (lưu mã lệnh + processing trước khi gọi cổng, không gửi lại khi chưa rõ kết quả).
 * - tbl_booking.noShowAt / noShowBy / noShowNote: khách KHÔNG ĐẾN ngày khởi hành (B5). Đơn vẫn tính đã thu, không hoàn theo chính sách.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_booking_adjustments') && !Schema::hasColumn('tbl_booking_adjustments', 'gatewayStatus')) {
            Schema::table('tbl_booking_adjustments', function (Blueprint $t) {
                $t->string('gateway', 12)->nullable();
                $t->string('gatewayRefundRef', 60)->nullable()->index('idx_adj_gateway_ref');
                $t->string('gatewayStatus', 12)->nullable();     // processing | succeeded | failed
                $t->unsignedTinyInteger('gatewayAttempts')->default(0);
                $t->string('gatewayCode', 12)->nullable();
                $t->string('gatewayMessage', 255)->nullable();
                $t->string('gatewayRefundNo', 40)->nullable();
                $t->timestamp('gatewayAt')->nullable();
            });
        }
        if (Schema::hasTable('tbl_booking') && !Schema::hasColumn('tbl_booking', 'noShowAt')) {
            Schema::table('tbl_booking', function (Blueprint $t) {
                $t->timestamp('noShowAt')->nullable();
                $t->integer('noShowBy')->nullable();
                $t->string('noShowNote', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_booking_adjustments', 'gatewayStatus')) {
            Schema::table('tbl_booking_adjustments', function (Blueprint $t) {
                $t->dropIndex('idx_adj_gateway_ref');
                $t->dropColumn(['gateway', 'gatewayRefundRef', 'gatewayStatus', 'gatewayAttempts', 'gatewayCode', 'gatewayMessage', 'gatewayRefundNo', 'gatewayAt']);
            });
        }
        if (Schema::hasColumn('tbl_booking', 'noShowAt')) {
            Schema::table('tbl_booking', fn(Blueprint $t) => $t->dropColumn(['noShowAt', 'noShowBy', 'noShowNote']));
        }
    }
};
