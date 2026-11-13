<?php

namespace App\Services\Planner\Health;

use App\Services\Planner\TripWeather;

/**
 * Thời tiết theo DỰ BÁO GIỜ (T-16 xung đột thời tiết, T-18 nắng nóng, T-19 dông / gió mạnh, T-21 phương tiện theo thời tiết).
 * Chỉ chạy cho ngày có dự báo (≤ 16 ngày tới); ngày xa hơn vẫn do ContextConstraint xét theo mùa.
 *
 *  weather.rain      CẢNH BÁO  hoạt động ngoài trời trùng giờ có khả năng mưa ≥ rain_warn (đã có phương án mưa cho ngày đó → INFO)
 *  weather.storm     CẢNH BÁO  hoạt động ngoài trời trùng giờ có dông hoặc gió ≥ wind_warn
 *  weather.heat      CẢNH BÁO  hoạt động ngoài trời trong 11–15h khi nhiệt độ ≥ heat_warn
 *  weather.transport INFO      ngày mưa mà phương tiện mặc định là xe máy / đi bộ: nên đổi sang ô tô, taxi
 *  weather.ok / weather.source INFO
 * Mọi số đều là dự báo (ghi rõ nguồn Open-Meteo và giờ cập nhật), không phải ràng buộc cứng.
 */
final class WeatherConstraint implements Constraint
{
    public function group(): string
    {
        return 'weather';
    }

    public function check(TripContext $ctx): array
    {
        $w = $ctx->payload['weather'] ?? null;
        if (!$w || ($w['source'] ?? 'none') !== 'forecast' || empty($w['days'])) {
            return [];
        }
        $cfg = (array) config('planner.weather');
        $rainWarn = (int) ($cfg['rain_warn'] ?? 60);
        $heatWarn = (int) ($cfg['heat_warn'] ?? 35);
        $windWarn = (int) ($cfg['wind_warn'] ?? 40);
        $plan = $ctx->payload['rain']['plan'] ?? [];
        $mode = (string) config('planner.route.mode', 'driving');
        $out = [];
        foreach ($ctx->days as $d) {
            $wd = $w['days'][$d->day] ?? null;
            if (!$wd) {
                continue;
            }
            foreach ($d->rows as $i => $r) {
                if (($r['item']['outdoor'] ?? null) !== true) {
                    continue;
                }
                $x = TripWeather::window($wd, (int) $r['start'], (int) $r['end']);
                if (!$x['hours']) {
                    continue;
                }
                $when = 'Ngày ' . $d->day . ' · ' . DaySchedule::hhmm($r['start']) . '–' . DaySchedule::hhmm($r['end']) . ' ' . $r['item']['name'];
                if ($x['storm'] || $x['wind'] >= $windWarn) {
                    $out[] = ['level' => 'warn', 'code' => 'weather.storm', 'day' => $d->day, 'index' => $i, 'action' => 'rain',
                        'text' => $when . ' (ngoài trời): dự báo ' . ($x['storm'] ? 'có dông' : 'gió mạnh ' . $x['wind'] . ' km/h') . '. Nên dời giờ hoặc đổi sang nơi trong nhà.'];
                } elseif ($x['p'] >= $rainWarn) {
                    $has = !empty($plan[$d->day]);
                    $out[] = ['level' => $has ? 'info' : 'warn', 'code' => 'weather.rain', 'day' => $d->day, 'index' => $i, 'action' => 'rain',
                        'text' => $when . ' (ngoài trời): dự báo khả năng mưa ' . $x['p'] . '%.' . ($has ? ' Đã có phương án mưa cho ngày này.' : ' Nên chuẩn bị nơi trong nhà gần đó.')];
                }
                if ($x['t'] !== null && $x['t'] >= $heatWarn && (int) $r['start'] < 15 * 60 && (int) $r['end'] > 11 * 60) {
                    $out[] = ['level' => 'warn', 'code' => 'weather.heat', 'day' => $d->day, 'index' => $i,
                        'text' => $when . ' (ngoài trời): dự báo nắng nóng ' . $x['t'] . '°C giữa trưa. Nên dời sang sáng sớm hoặc chiều muộn.'];
                }
            }
            if (in_array($mode, ['motorbike', 'walking'], true) && $d->travelMin > 0 && TripWeather::dayRain($wd) >= $rainWarn) {
                $out[] = ['level' => 'info', 'code' => 'weather.transport', 'day' => $d->day,
                    'text' => 'Ngày ' . $d->day . ' dự báo mưa ' . TripWeather::dayRain($wd) . '% mà lịch đang tính đi ' . ($mode === 'walking' ? 'bộ' : 'xe máy')
                        . ' (' . $d->travelMin . ' phút). Nên đi ô tô hoặc taxi cho các chặng dài.'];
            }
        }
        $src = 'Dự báo theo giờ của Open-Meteo' . (!empty($w['fetchedAt']) ? ', cập nhật ' . \Carbon\Carbon::parse($w['fetchedAt'])->format('H:i d/m') : '')
            . (!empty($w['stale']) ? ' (chưa cập nhật được bản mới)' : '') . ' cho ' . count($w['days']) . ' ngày của chuyến.';
        if (!array_filter($out, fn($x) => $x['level'] === 'warn')) {
            $out[] = ['level' => 'ok', 'code' => 'weather.ok', 'text' => 'Dự báo không có mưa lớn, dông hay nắng nóng vào giờ các hoạt động ngoài trời.'];
        }
        $out[] = ['level' => 'info', 'code' => 'weather.source', 'text' => $src];

        return $out;
    }

    /** Khối thời tiết gửi cho giao diện: icon từng ngày (A-10a), WeatherFit (A-10b), số theo giờ 6–22h (T-15). */
    public static function summary(TripContext $ctx): ?array
    {
        $w = $ctx->payload['weather'] ?? null;
        if (!$w || ($w['source'] ?? 'none') !== 'forecast') {
            return $w ? ['source' => $w['source'] ?? 'none', 'reason' => $w['reason'] ?? null, 'days' => []] : null;
        }
        $days = [];
        $fits = [];
        foreach ($w['days'] as $n => $wd) {
            $rows = isset($ctx->days[$n]) ? array_map(fn($r) => ['start' => $r['start'], 'end' => $r['end'], 'outdoor' => $r['item']['outdoor'] ?? null], $ctx->days[$n]->rows) : [];
            $fit = TripWeather::fit($wd, $rows);
            $hasOutdoor = (bool) array_filter($rows, fn($r) => $r['outdoor'] === true);
            if ($hasOutdoor) {
                $fits[] = $fit;
            }
            $hours = [];
            foreach ($wd['hours'] as $h => $x) {
                if ($h >= 6 && $h <= 22) {
                    $hours[] = ['h' => (int) $h, 'p' => $x['p'], 't' => $x['t'], 'icon' => \App\Services\Discover\WeatherService::describe($x['code'])['icon'], 'bad' => TripWeather::badHour($x, (int) $h)];
                }
            }
            $days[] = ['day' => (int) $n, 'date' => $wd['date'], 'icon' => $wd['icon'], 'label' => $wd['label'], 'tMax' => $wd['tMax'], 'tMin' => $wd['tMin'],
                'rain' => TripWeather::dayRain($wd), 'fitPct' => $hasOutdoor ? (int) round($fit * 100) : null, 'hours' => $hours];
        }

        return ['source' => 'forecast', 'fetchedAt' => $w['fetchedAt'], 'stale' => $w['stale'], 'days' => $days,
            'fitPct' => $fits ? (int) round(array_sum($fits) / count($fits) * 100) : null, 'attribution' => 'Open-Meteo.com (CC BY 4.0)'];
    }
}
