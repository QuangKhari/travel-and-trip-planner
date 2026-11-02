<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thanh toán VNPay (§6.2 tbl_checkout, §6.3 tbl_payment_logs).
 *   tbl_checkout: provider (vnpay | office), providerTxnRef (vnp_TxnRef của lần thanh toán gần nhất), providerTxnNo (mã GD VNPay),
 *                 bankCode, responseCode, paidAt, attempts (số lần tạo link thanh toán), providerCreateDate (dùng cho querydr).
 *   tbl_payment_logs: MỌI payload create / return / ipn / querydr được ghi lại để đối soát và làm bằng chứng.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_checkout')) {
            Schema::table('tbl_checkout', function (Blueprint $t) {
                foreach ([
                    'provider' => fn() => $t->string('provider', 12)->nullable()->after('paymentMethod'),
                    'providerTxnRef' => fn() => $t->string('providerTxnRef', 60)->nullable()->after('transactionId'),
                    'providerTxnNo' => fn() => $t->string('providerTxnNo', 40)->nullable(),
                    'bankCode' => fn() => $t->string('bankCode', 20)->nullable(),
                    'responseCode' => fn() => $t->string('responseCode', 4)->nullable(),
                    'paidAt' => fn() => $t->timestamp('paidAt')->nullable(),
                    'attempts' => fn() => $t->unsignedSmallInteger('attempts')->default(0),
                    'providerCreateDate' => fn() => $t->string('providerCreateDate', 14)->nullable(),
                ] as $col => $add) {
                    if (!Schema::hasColumn('tbl_checkout', $col)) {
                        $add();
                    }
                }
            });
            if (!Schema::hasIndex('tbl_checkout', 'idx_checkout_txnref')) {
                Schema::table('tbl_checkout', function (Blueprint $t) {
                    $t->index('providerTxnRef', 'idx_checkout_txnref');
                });
            }
        }

        if (!Schema::hasTable('tbl_payment_logs')) {
            Schema::create('tbl_payment_logs', function (Blueprint $t) {
                $t->bigIncrements('logId');
                $t->integer('bookingId')->nullable();
                $t->string('provider', 12)->default('vnpay');
                $t->string('kind', 12);                       // create | return | ipn | querydr | refund
                $t->string('txnRef', 60)->nullable();
                $t->string('result', 40)->nullable();         // mã trả về / kết luận ngắn (vd 00, 97, amount_mismatch)
                $t->boolean('validSignature')->nullable();
                $t->json('payload')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('createdAt')->useCurrent();
                $t->index(['bookingId', 'createdAt'], 'idx_paylog_booking');
                $t->index('txnRef', 'idx_paylog_txn');
                $t->index(['kind', 'createdAt'], 'idx_paylog_kind');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_payment_logs');
        if (Schema::hasTable('tbl_checkout')) {
            Schema::table('tbl_checkout', function (Blueprint $t) {
                if (Schema::hasIndex('tbl_checkout', 'idx_checkout_txnref')) {
                    $t->dropIndex('idx_checkout_txnref');
                }
                foreach (['provider', 'providerTxnRef', 'providerTxnNo', 'bankCode', 'responseCode', 'paidAt', 'attempts', 'providerCreateDate'] as $c) {
                    if (Schema::hasColumn('tbl_checkout', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
