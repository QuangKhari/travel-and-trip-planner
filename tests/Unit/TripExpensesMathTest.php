<?php

namespace Tests\Unit;

use App\Services\Planner\TripExpenses;
use PHPUnit\Framework\TestCase;

/** Phép chia đều và tính "ai trả ai" của chi tiêu nhóm: không mất đồng nào, ít lần chuyển. */
class TripExpensesMathTest extends TestCase
{
    public function test_split_equal_keeps_every_dong_and_puts_remainder_first(): void
    {
        $s = TripExpenses::splitEqual(100000, ['u:1', 'u:2', 'g:3']);
        $this->assertSame(['u:1' => 33334, 'u:2' => 33333, 'g:3' => 33333], $s);
        $this->assertSame(100000, array_sum($s));
        $this->assertSame([], TripExpenses::splitEqual(5000, []));
        $this->assertSame(['u:1' => 1, 'u:2' => 0], TripExpenses::splitEqual(1, ['u:1', 'u:2']));
    }

    public function test_settle_balances_out_with_few_transfers(): void
    {
        // A trả 300k cho 3 người: A +200k, B -100k, C -100k
        $t = TripExpenses::settle(['u:a' => 200000, 'u:b' => -100000, 'u:c' => -100000]);
        $this->assertCount(2, $t);
        foreach ($t as $x) {
            $this->assertSame('u:a', $x['to']);
            $this->assertSame(100000, $x['amount']);
        }

        // ai cũng đã cân: không cần chuyển
        $this->assertSame([], TripExpenses::settle(['u:a' => 0, 'u:b' => 0]));

        // nhiều người: tổng chuyển đi của mỗi người đúng bằng số nợ, số lần ≤ n-1
        $bal = ['a' => 70, 'b' => 50, 'c' => -40, 'd' => -45, 'e' => -35];
        $t = TripExpenses::settle($bal);
        $this->assertLessThanOrEqual(count($bal) - 1, count($t));
        $net = array_fill_keys(array_keys($bal), 0);
        foreach ($t as $x) {
            $this->assertGreaterThan(0, $x['amount']);
            $net[$x['from']] += $x['amount'];
            $net[$x['to']] -= $x['amount'];
        }
        foreach ($bal as $k => $b) {
            $this->assertSame(0, $net[$k] + $b, "người $k phải về 0 sau khi chuyển");
        }
    }
}
