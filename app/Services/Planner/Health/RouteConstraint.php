<?php

namespace App\Services\Planner\Health;

/** Tuyến đường: đi lại quá nhiều trong ngày, đoạn quá xa, mục thiếu vị trí. */
final class RouteConstraint implements Constraint
{
    public function group(): string
    {
        return 'route';
    }

    public function check(TripContext $ctx): array
    {
        $out = [];
        $warn = \App\Services\Planner\PlannerRules::fromConfig()->travelWarnMin();
        $noPos = 0;
        foreach ($ctx->days as $d) {
            $noPos += $d->noPos;
            if ($d->travelMin > $warn) {
                $out[] = ['level' => 'warn', 'code' => 'route.long_day', 'day' => $d->day, 'action' => 'optimize',
                    'text' => 'Ngày ' . $d->day . ' đi lại khoảng ' . round($d->travelMin / 60, 1) . ' giờ (' . round($d->travelKm) . ' km).'];
            }
            foreach ($d->rows as $r) {
                if ($r['travelKm'] >= 40) {
                    $out[] = ['level' => 'warn', 'code' => 'route.long_leg', 'day' => $d->day,
                        'text' => 'Ngày ' . $d->day . ': đoạn từ “' . $r['item']['name'] . '” dài khoảng ' . round($r['travelKm']) . ' km, nên tách sang ngày khác hoặc thêm mục di chuyển.'];
                }
            }
        }
        // nguồn số liệu: OSRM được cấu hình nhưng phải dùng ước tính -> nói rõ (INFO, không phải lỗi)
        $st = app(\App\Services\Planner\Route\RouteService::class)->status();
        if ($st['fallback']) {
            $out[] = ['level' => 'info', 'code' => 'route.fallback', 'text' => 'Chưa lấy được đường bộ thật (' . ($st['reason'] ?? 'OSRM không phản hồi') . '); quãng đường và thời gian đang là ước tính.'];
        }
        if ($noPos) {
            $out[] = ['level' => 'info', 'code' => 'route.no_position', 'action' => 'place', 'text' => $noPos . ' mục chưa có vị trí nên chưa tính quãng đường.'];
        }
        if (!array_filter($out, fn($x) => $x['level'] !== 'info')) {
            $out[] = ['level' => 'ok', 'code' => 'route.ok', 'text' => 'Tuyến di chuyển hợp lý.'];
        }

        return $out;
    }
}
