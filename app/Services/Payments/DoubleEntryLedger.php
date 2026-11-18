<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ cái hai bút toán (tbl_ledger_entries) cho mọi dòng tiền của đơn.
 *
 * Mỗi nghiệp vụ là một "journal" có khóa duy nhất (journalKey) và các dòng có tổng = 0 (Nợ +, Có −):
 *   thu tiền đơn         Nợ cash:{kênh}        / Có customer:deposit
 *   hoàn tiền khi hủy    Nợ customer:deposit   / Có cash:{kênh}
 *   thu thêm (tăng khách)Nợ cash:{kênh}        / Có customer:deposit
 *   hoàn phần dư         Nợ customer:deposit   / Có cash:{kênh}
 *   thu trùng            Nợ cash:{kênh}        / Có customer:excess
 *   hoàn khoản thu trùng Nợ customer:excess    / Có cash:{kênh}
 *
 * Ghi lặp cùng journalKey không nhân đôi (UNIQUE journalKey + account). Số dư Có của customer:deposit theo đơn
 * phải bằng PaymentLedger::held − tiền hoàn hủy đã hoàn: PaymentReconciliationService đối chiếu hai nguồn.
 * Sổ cái là bằng chứng phụ: lỗi ghi sổ không được làm hỏng nghiệp vụ tiền chính (được báo, đối soát phát hiện).
 */
class DoubleEntryLedger
{
    public const DEPOSIT = 'customer:deposit';
    public const EXCESS = 'customer:excess';

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('tbl_ledger_entries');
    }

    /** Tài khoản tiền của một kênh thu. */
    public static function cash(?string $channel): string
    {
        $c = strtolower((string) $channel);
        $c = match (true) {
            in_array($c, ['vnpay', 'momo', 'qr'], true) => $c,
            str_contains($c, 'mặt') || $c === 'office' || $c === 'cash' => 'office',
            default => 'manual',
        };

        return 'cash:' . $c;
    }

    /**
     * Ghi một nghiệp vụ. $lines: [[account, amount], ...] tổng phải bằng 0.
     * @return bool true = đã ghi mới; false = journal đã có (ghi lặp) hoặc sổ chưa sẵn sàng
     */
    public function post(string $journalKey, ?int $bookingId, string $kind, array $lines, ?string $memo = null): bool
    {
        if (!self::ready()) {
            return false;
        }
        $sum = array_sum(array_map(fn($l) => (int) $l[1], $lines));
        if ($sum !== 0 || count($lines) < 2) {
            throw new \LogicException('Bút toán không cân (tổng ' . $sum . ') cho ' . $journalKey);
        }
        if (DB::table('tbl_ledger_entries')->where('journalKey', $journalKey)->exists()) {
            return false;
        }
        $now = now();
        $rows = array_map(fn($l) => ['journalKey' => mb_substr($journalKey, 0, 80), 'bookingId' => $bookingId, 'kind' => $kind, 'account' => $l[0],
            'amount' => (int) $l[1], 'memo' => $memo ? mb_substr($memo, 0, 160) : null, 'createdAt' => $now], $lines);
        try {
            DB::table('tbl_ledger_entries')->insert($rows);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;   // tiến trình khác vừa ghi cùng nghiệp vụ
        }

        return true;
    }

    /** Ghi an toàn: lỗi sổ cái không làm hỏng nghiệp vụ chính. */
    public function safePost(string $journalKey, ?int $bookingId, string $kind, array $lines, ?string $memo = null): void
    {
        try {
            $this->post($journalKey, $bookingId, $kind, $lines, $memo);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function capture(int $bookingId, string $channel, int $amount, string $ref): void
    {
        if ($amount > 0) {
            $this->safePost('capture:' . $bookingId . ':' . $ref, $bookingId, 'capture', [[self::cash($channel), $amount], [self::DEPOSIT, -$amount]], 'Thu tiền đơn qua ' . $channel);
        }
    }

    public function refund(int $bookingId, int $refundId, string $channel, int $amount): void
    {
        if ($amount > 0) {
            $this->safePost('refund:' . $refundId, $bookingId, 'refund', [[self::DEPOSIT, $amount], [self::cash($channel), -$amount]], 'Hoàn tiền khi hủy đơn');
        }
    }

    public function collect(int $bookingId, int $adjustId, string $channel, int $amount): void
    {
        if ($amount > 0) {
            $this->safePost('collect:' . $adjustId, $bookingId, 'collect', [[self::cash($channel), $amount], [self::DEPOSIT, -$amount]], 'Thu thêm khi tăng số khách');
        }
    }

    public function adjustRefund(int $bookingId, int $adjustId, string $channel, int $amount): void
    {
        if ($amount > 0) {
            $this->safePost('adjust_refund:' . $adjustId, $bookingId, 'adjust_refund', [[self::DEPOSIT, $amount], [self::cash($channel), -$amount]], 'Hoàn phần dư khi giảm số khách');
        }
    }

    public function excess(int $bookingId, int $attemptId, string $channel, int $amount): void
    {
        if ($amount > 0) {
            $this->safePost('excess:' . $attemptId, $bookingId, 'excess', [[self::cash($channel), $amount], [self::EXCESS, -$amount]], 'Khoản thu trùng');
        }
    }

    public function excessRefund(int $bookingId, int $attemptId, string $channel, int $amount): void
    {
        if ($amount > 0) {
            $this->safePost('excess_refund:' . $attemptId, $bookingId, 'excess_refund', [[self::EXCESS, $amount], [self::cash($channel), -$amount]], 'Hoàn khoản thu trùng');
        }
    }

    /** Số dư (Nợ − Có) của một tài khoản, theo đơn nếu có. Tiền khách đang giữ = −balance(customer:deposit). */
    public function balance(string $account, ?int $bookingId = null): int
    {
        if (!self::ready()) {
            return 0;
        }

        return (int) DB::table('tbl_ledger_entries')->where('account', $account)->when($bookingId !== null, fn($q) => $q->where('bookingId', $bookingId))->sum('amount');
    }

    /** Các journal không cân (không bao giờ xảy ra nếu chỉ ghi qua post()). */
    public function unbalanced(int $limit = 100): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('tbl_ledger_entries')->groupBy('journalKey')->havingRaw('SUM(amount) <> 0')->limit($limit)->pluck('journalKey')->all();
    }

    /**
     * Ghi bù sổ cái cho dữ liệu có từ trước (lệnh payments:ledger-backfill). Chỉ ghi nghiệp vụ chưa có.
     * @return int số journal đã ghi
     */
    public function backfill(): int
    {
        if (!self::ready()) {
            return 0;
        }
        $n = 0;
        // thu tiền: đơn đã thu (gồm cả đơn sau đó hoàn) — số tiền gốc = amount trừ các khoản thu thêm đã cộng vào
        $collected = Schema::hasTable('tbl_booking_adjustments')
            ? DB::table('tbl_booking_adjustments')->where('moneyStatus', 'collected')->groupBy('bookingId')->pluck(DB::raw('SUM(settleAmount)'), 'bookingId')->all() : [];
        foreach (DB::table('tbl_checkout')->whereIn('paymentStatus', PaymentLedger::PAID_STATUSES)->get(['bookingId', 'amount', 'provider', 'paymentMethod', 'providerTxnRef']) as $c) {
            $base = (int) round((float) $c->amount) - (int) ($collected[$c->bookingId] ?? 0);
            $n += (int) $this->tryPost(
                'capture:' . $c->bookingId . ':' . ($c->providerTxnRef ?: 'legacy'),
                (int) $c->bookingId,
                'capture',
                $base,
                self::cash($c->provider ?: $c->paymentMethod),
                self::DEPOSIT,
                'Ghi bù: thu tiền đơn',
            );
        }
        if (Schema::hasTable('tbl_booking_adjustments')) {
            foreach (DB::table('tbl_booking_adjustments as a')->join('tbl_checkout as c', 'c.bookingId', '=', 'a.bookingId')->whereIn('a.moneyStatus', ['collected', 'refunded'])
                ->get(['a.adjustId', 'a.bookingId', 'a.moneyStatus', 'a.settleAmount', 'a.method', 'c.provider']) as $a) {
                $collect = $a->moneyStatus === 'collected';
                $ch = $collect ? self::cash($a->method) : self::cash(str_starts_with((string) $a->method, 'Hoàn qua ') ? $a->provider : $a->method);
                $n += (int) ($collect
                    ? $this->tryPost('collect:' . $a->adjustId, (int) $a->bookingId, 'collect', (int) $a->settleAmount, $ch, self::DEPOSIT, 'Ghi bù: thu thêm')
                    : $this->tryPost('adjust_refund:' . $a->adjustId, (int) $a->bookingId, 'adjust_refund', (int) $a->settleAmount, self::DEPOSIT, $ch, 'Ghi bù: hoàn phần dư'));
            }
        }
        if (Schema::hasTable('tbl_refunds')) {
            foreach (DB::table('tbl_refunds as r')->join('tbl_checkout as c', 'c.bookingId', '=', 'r.bookingId')->where('r.status', 'refunded')->where('r.amount', '>', 0)
                ->get(['r.refundId', 'r.bookingId', 'r.amount', 'r.method', 'r.gateway', 'c.provider']) as $r) {
                $ch = $r->gateway ?: (str_starts_with((string) $r->method, 'Hoàn qua ') ? $r->provider : $r->method);
                $n += (int) $this->tryPost('refund:' . $r->refundId, (int) $r->bookingId, 'refund', (int) $r->amount, self::DEPOSIT, self::cash($ch), 'Ghi bù: hoàn khi hủy');
            }
        }

        return $n;
    }

    private function tryPost(string $key, int $bookingId, string $kind, int $amount, string $debit, string $credit, string $memo): bool
    {
        return $amount > 0 && $this->post($key, $bookingId, $kind, [[$debit, $amount], [$credit, -$amount]], $memo);
    }
}
