<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sửa luồng thiếu khách / ghép đoàn / hoàn tiền:
 *   tbl_tour_availability.shortfallFlaggedAt   thời điểm hệ thống gắn cờ "sắp thiếu khách" (lệnh departures:check-shortfall)
 *   tbl_departure_rescue_plans.declinePolicy   reject_all (mặc định, như cũ) | refund_decliners (ai từ chối thì hủy + hoàn 100%, còn lại vẫn áp dụng)
 *   tbl_departure_groups.fingerprintMode       strict (tiêu đề + mô tả) | titles (chỉ tiêu đề từng ngày, admin phải xác nhận)
 *   tbl_refunds.rescuePlanId                   hồ sơ hoàn tiền thuộc phương án nào, để đóng phương án khi hoàn xong
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_tour_availability') && !Schema::hasColumn('tbl_tour_availability', 'shortfallFlaggedAt')) {
            Schema::table('tbl_tour_availability', function (Blueprint $t) {
                $t->timestamp('shortfallFlaggedAt')->nullable();
                $t->index('shortfallFlaggedAt', 'idx_avail_shortfall');
            });
        }
        if (Schema::hasTable('tbl_departure_rescue_plans') && !Schema::hasColumn('tbl_departure_rescue_plans', 'declinePolicy')) {
            Schema::table('tbl_departure_rescue_plans', function (Blueprint $t) {
                $t->string('declinePolicy', 20)->default('reject_all');
            });
        }
        if (Schema::hasTable('tbl_departure_groups') && !Schema::hasColumn('tbl_departure_groups', 'fingerprintMode')) {
            Schema::table('tbl_departure_groups', function (Blueprint $t) {
                $t->string('fingerprintMode', 10)->default('strict');
            });
        }
        if (Schema::hasTable('tbl_refunds') && !Schema::hasColumn('tbl_refunds', 'rescuePlanId')) {
            Schema::table('tbl_refunds', function (Blueprint $t) {
                $t->unsignedInteger('rescuePlanId')->nullable();
                $t->index('rescuePlanId', 'idx_refund_plan');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_refunds', 'rescuePlanId')) {
            Schema::table('tbl_refunds', function (Blueprint $t) {
                $t->dropIndex('idx_refund_plan');
                $t->dropColumn('rescuePlanId');
            });
        }
        if (Schema::hasColumn('tbl_departure_groups', 'fingerprintMode')) {
            Schema::table('tbl_departure_groups', fn (Blueprint $t) => $t->dropColumn('fingerprintMode'));
        }
        if (Schema::hasColumn('tbl_departure_rescue_plans', 'declinePolicy')) {
            Schema::table('tbl_departure_rescue_plans', fn (Blueprint $t) => $t->dropColumn('declinePolicy'));
        }
        if (Schema::hasColumn('tbl_tour_availability', 'shortfallFlaggedAt')) {
            Schema::table('tbl_tour_availability', function (Blueprint $t) {
                $t->dropIndex('idx_avail_shortfall');
                $t->dropColumn('shortfallFlaggedAt');
            });
        }
    }
};
