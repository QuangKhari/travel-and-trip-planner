<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tbl_refunds: theo dõi lệnh hoàn qua API cổng (gateway, mã hoàn của lần thử gần nhất, trạng thái processing|succeeded|failed, mã/ thông báo của cổng, số lần thử).
 * tbl_departure_groups.shortfallFlaggedAt: đoàn ghép có tổng khách thấp hơn mức tối thiểu của đoàn (lệnh departures:check-shortfall).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_refunds')) {
            Schema::table('tbl_refunds', function (Blueprint $t) {
                foreach ([
                    'gateway' => fn () => $t->string('gateway', 12)->nullable(),
                    'gatewayRefundRef' => fn () => $t->string('gatewayRefundRef', 60)->nullable(),
                    'gatewayRefundNo' => fn () => $t->string('gatewayRefundNo', 40)->nullable(),
                    'gatewayStatus' => fn () => $t->string('gatewayStatus', 12)->nullable(),
                    'gatewayCode' => fn () => $t->string('gatewayCode', 12)->nullable(),
                    'gatewayMessage' => fn () => $t->string('gatewayMessage', 255)->nullable(),
                    'gatewayAttempts' => fn () => $t->unsignedSmallInteger('gatewayAttempts')->default(0),
                    'gatewayAt' => fn () => $t->timestamp('gatewayAt')->nullable(),
                ] as $col => $add) {
                    if (!Schema::hasColumn('tbl_refunds', $col)) {
                        $add();
                    }
                }
            });
            if (!Schema::hasIndex('tbl_refunds', 'idx_refund_gateway')) {
                Schema::table('tbl_refunds', fn (Blueprint $t) => $t->index(['status', 'gatewayStatus'], 'idx_refund_gateway'));
            }
        }
        if (Schema::hasTable('tbl_departure_groups') && !Schema::hasColumn('tbl_departure_groups', 'shortfallFlaggedAt')) {
            Schema::table('tbl_departure_groups', fn (Blueprint $t) => $t->timestamp('shortfallFlaggedAt')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_departure_groups', 'shortfallFlaggedAt')) {
            Schema::table('tbl_departure_groups', fn (Blueprint $t) => $t->dropColumn('shortfallFlaggedAt'));
        }
        if (Schema::hasTable('tbl_refunds')) {
            if (Schema::hasIndex('tbl_refunds', 'idx_refund_gateway')) {
                Schema::table('tbl_refunds', fn (Blueprint $t) => $t->dropIndex('idx_refund_gateway'));
            }
            foreach (['gateway', 'gatewayRefundRef', 'gatewayRefundNo', 'gatewayStatus', 'gatewayCode', 'gatewayMessage', 'gatewayAttempts', 'gatewayAt'] as $c) {
                if (Schema::hasColumn('tbl_refunds', $c)) {
                    Schema::table('tbl_refunds', fn (Blueprint $t) => $t->dropColumn($c));
                }
            }
        }
    }
};
