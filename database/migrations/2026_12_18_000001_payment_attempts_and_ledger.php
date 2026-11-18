<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thanh toán — hoàn thiện các hạn chế đã biết (đợt 18/11/2026):
 *
 * tbl_payment_attempts: MỖI LẦN THỬ thanh toán của một đơn là một dòng, ghi TRƯỚC khi gọi cổng
 *   (UNIQUE provider + txnRef). Webhook / hỏi trạng thái / hoàn tiền đều theo đúng lần thử đã trả tiền
 *   (mã giao dịch, ngày tạo giao dịch VNPay, số tiền của chính lần đó) thay vì "mã gần nhất" trong tbl_checkout.
 *   Lần thử trả tiền sau khi đơn đã được ghi nhận bằng lần thử khác = khoản thu trùng (status = excess):
 *   tự tạo yêu cầu hoàn (refundStatus = pending) chờ nhân viên duyệt hoàn qua cổng hoặc ghi nhận hoàn tay.
 *
 * tbl_ledger_entries: sổ cái HAI BÚT TOÁN cho mọi dòng tiền của đơn. Mỗi nghiệp vụ (journalKey) gồm các dòng
 *   có tổng amount = 0 (Nợ dương, Có âm). Khóa UNIQUE(journalKey, account) nên ghi lặp không nhân đôi.
 *   Tài khoản:  cash:{vnpay|momo|qr|office|manual}   tiền đang nằm ở kênh thu
 *               customer:deposit                       tiền của khách Travela đang giữ cho đơn (số dư Có)
 *               customer:excess                        khoản thu trùng phải trả lại khách
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tbl_payment_attempts')) {
            Schema::create('tbl_payment_attempts', function (Blueprint $t) {
                $t->increments('attemptId');
                $t->integer('bookingId')->index('idx_pa_booking');
                $t->string('provider', 12);
                $t->string('txnRef', 60);
                $t->unsignedBigInteger('amount');
                $t->char('createDate', 14)->nullable();          // yyyyMMddHHmmss (GMT+7) — VNPay cần cho querydr / refund
                $t->string('status', 12)->default('created');      // created | failed | paid | excess
                $t->boolean('settled')->default(false);            // lần thử được ghi nhận là khoản thanh toán của đơn
                $t->string('providerTxnNo', 40)->nullable();
                $t->timestamp('paidAt')->nullable();
                // hoàn khoản thu trùng (status = excess)
                $t->string('refundStatus', 12)->nullable();        // pending | processing | refunded | failed
                $t->string('refundRef', 60)->nullable()->index('idx_pa_refund_ref');
                $t->string('refundNo', 40)->nullable();
                $t->unsignedTinyInteger('refundAttempts')->default(0);
                $t->string('refundCode', 12)->nullable();
                $t->string('refundMessage', 255)->nullable();
                $t->string('refundMethod', 40)->nullable();
                $t->string('refundReference', 80)->nullable();
                $t->integer('refundBy')->nullable();
                $t->timestamp('refundAt')->nullable();
                $t->timestamp('createdAt')->nullable();
                $t->timestamp('updatedAt')->nullable();
                $t->unique(['provider', 'txnRef'], 'uq_pa_provider_txn');
            });
        }
        if (!Schema::hasTable('tbl_ledger_entries')) {
            Schema::create('tbl_ledger_entries', function (Blueprint $t) {
                $t->bigIncrements('entryId');
                $t->string('journalKey', 80);
                $t->integer('bookingId')->nullable()->index('idx_le_booking');
                $t->string('kind', 24);                            // capture | refund | collect | adjust_refund | excess | excess_refund
                $t->string('account', 40);
                $t->bigInteger('amount');                          // Nợ dương, Có âm; tổng mỗi journalKey = 0
                $t->string('memo', 160)->nullable();
                $t->timestamp('createdAt')->nullable();
                $t->unique(['journalKey', 'account'], 'uq_le_journal_account');
                $t->index(['account', 'bookingId'], 'idx_le_account_booking');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_ledger_entries');
        Schema::dropIfExists('tbl_payment_attempts');
    }
};
