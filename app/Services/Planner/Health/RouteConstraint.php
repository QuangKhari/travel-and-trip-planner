<?php

namespace App\Services\Planner\Health;

/**
 * Tuyến đường: đi lại quá nhiều trong ngày, đoạn quá xa, mục thiếu vị trí,
 * và CHẤT LƯỢNG số liệu từng chặng: unreachable (không có đường, lỗi cứng), unknown (không tính được, cảnh báo),
 * estimated (ước tính, thông tin). Không chặng nào bị coi là "0 phút" chỉ vì thiếu dữ liệu.
 */
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
            foreach ($d->rows as $i => $r) {
                $st = $r['routeStatus'] ?? null;
                $next = $d->rows[$i + 1]['item']['name'] ?? 'điểm kế tiếp';
                if ($st === \App\Services\Planner\Route\RouteProvider::UNREACHABLE) {
                    $out[] = ['level' => 'bad', 'code' => 'route.unreachable', 'day' => $d->day, 'index' => $i, 'action' => 'place',
                        'text' => 'Ngày ' . $d->day . ': không tìm thấy đường bộ từ “' . $r['item']['name'] . '” tới “' . $next . '”. Số phút đang hiện chỉ là ước tính đường chim bay; kiểm tra lại hoặc đổi phương tiện (tàu, thuyền).'];
                    continue;
                }
                if ($st === \App\Services\Planner\Route\RouteProvider::UNKNOWN) {
                    $out[] = ['level' => 'warn', 'code' => 'route.unknown', 'day' => $d->day, 'index' => $i, 'action' => 'place',
                        'text' => 'Ngày ' . $d->day . ': chưa tính được đường đi giữa “' . $r['item']['name'] . '” và “' . $next . '” vì một trong hai điểm có tọa độ không hợp lệ. Hãy sửa vị trí.'];
                    continue;
                }
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
