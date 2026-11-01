<?php

namespace App\Services\Planner\Health;

/** Ngân sách: tổng chi phí đã có giá so với ngân sách, ngày tốn hơn hẳn trung bình các ngày. (Phần giá ước tính do trình duyệt cộng thêm.) */
final class BudgetConstraint implements Constraint
{
    public function group(): string
    {
        return 'budget';
    }

    public function check(TripContext $ctx): array
    {
        $out = [];
        $total = array_sum(array_map(fn($d) => $d->cost, $ctx->days)) + array_sum(array_map(fn($x) => (float) ($x['cost'] ?? 0), $ctx->unassigned));
        $budget = $ctx->budget();
        if ($budget !== null && $budget > 0) {
            $pct = (int) round($total / $budget * 100);
            $out[] = $total > $budget
                ? ['level' => 'warn', 'code' => 'budget.over', 'action' => 'budget', 'text' => 'Chi phí đã nhập ' . self::m($total) . ' vượt ngân sách ' . self::m($budget) . '.']
                : ['level' => 'ok', 'code' => 'budget.ok', 'text' => 'Chi phí đã nhập dùng ' . $pct . '% ngân sách.'];
        }
        $costs = array_filter(array_map(fn($d) => $d->cost, $ctx->days), fn($c) => $c > 0);
        if (count($costs) >= 2) {
            $avg = array_sum($costs) / count($costs);
            $over = (float) config('planner.day_cost_over_avg', 0.4);
            foreach ($ctx->days as $d) {
                if ($avg > 0 && $d->cost > $avg * (1 + $over)) {
                    $out[] = ['level' => 'info', 'code' => 'budget.day_high', 'day' => $d->day,
                        'text' => 'Ngày ' . $d->day . ' tốn ' . self::m($d->cost) . ', cao hơn trung bình các ngày ' . (int) round(($d->cost / $avg - 1) * 100) . '%.'];
                }
            }
        }

        return $out;
    }

    public static function m(float $v): string
    {
        return number_format($v, 0, ',', '.') . 'đ';
    }
}
