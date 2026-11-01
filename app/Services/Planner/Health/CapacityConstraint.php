<?php

namespace App\Services\Planner\Health;

/** Sức chứa của ngày: độ dày (hoạt động + di chuyển + bữa ăn + đệm so với thời gian dùng được), số mục, mục chưa xếp. */
final class CapacityConstraint implements Constraint
{
    public function group(): string
    {
        return 'capacity';
    }

    public function check(TripContext $ctx): array
    {
        $out = [];
        foreach ($ctx->days as $d) {
            if (!$d->count()) {
                continue;
            }
            $pct = (int) round($d->load() * 100);
            if ($d->level() === 'bad') {
                $out[] = ['level' => 'warn', 'code' => 'capacity.overload', 'day' => $d->day, 'action' => 'balance',
                    'text' => 'Ngày ' . $d->day . ' quá dày: ' . $d->count() . ' hoạt động, dùng ' . self::h($d->occupiedMin()) . ' trên ' . self::h($d->availableMin) . ' (' . $pct . '%).'];
            } elseif ($d->level() === 'warn') {
                $out[] = ['level' => 'warn', 'code' => 'capacity.tight', 'day' => $d->day, 'action' => 'balance',
                    'text' => 'Ngày ' . $d->day . ' khá kín (' . $pct . '%).'];
            }
            if ($d->count() > \App\Services\Planner\PlannerRules::fromConfig()->itemsWarn()) {
                $out[] = ['level' => 'warn', 'code' => 'capacity.many', 'day' => $d->day, 'text' => 'Ngày ' . $d->day . ' có ' . $d->count() . ' mục, hơi nhiều.'];
            }
        }
        // thời lượng chưa nhập -> dùng mặc định theo loại: là ƯỚC TÍNH, nói rõ (INFO)
        $est = 0;
        foreach ($ctx->days as $d) {
            foreach ($d->rows as $r) {
                $est += empty($r['item']['durationMin']) ? 1 : 0;
            }
        }
        if ($est) {
            $out[] = ['level' => 'info', 'code' => 'capacity.estimated_duration', 'text' => $est . ' mục đang dùng thời lượng ước tính (mặc định theo loại). Đặt thời lượng thật để lịch chính xác hơn.'];
        }
        if ($ctx->unassigned) {
            $out[] = ['level' => 'info', 'code' => 'capacity.unassigned', 'text' => count($ctx->unassigned) . ' mục trong “Đã lưu”, chưa xếp vào ngày nào.'];
        }
        if (!array_filter($out, fn($x) => in_array($x['level'], ['bad', 'warn'], true))) {
            $out[] = ['level' => 'ok', 'code' => 'capacity.ok', 'text' => 'Không có ngày nào quá dày.'];
        }

        return $out;
    }

    public static function h(int $min): string
    {
        return intdiv($min, 60) . 'h' . sprintf('%02d', $min % 60);
    }
}
