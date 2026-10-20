<?php

namespace App\Services\Climate;

/**
 * A5: luật chấm điểm tháng đi (không phụ thuộc Laravel, ngưỡng truyền vào từ config/climate.php).
 * Điểm = 100 - (lạnh) - (rất lạnh) - (nóng) - (tỉ lệ ngày mưa) - (mưa lớn); phân loại best / ok / avoid.
 * Luật giải thích được trong báo cáo: mỗi khoản trừ có ngưỡng và trần ở config.
 */
final class ClimateRules
{
    /** Số ngày mỗi tháng (tháng 2 tính 28: dữ liệu là trung bình nhiều năm). */
    private const DAYS = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

    /**
     * @param array $m {tMax,tMin,rainMm,rainyDays}
     * @return array{score:float,dominant:string}  dominant: cold|hot|rain|none (khoản trừ lớn nhất)
     */
    public static function score(array $m, int $month, array $r): array
    {
        $days = self::DAYS[$month];
        $mean = ($m['tMax'] + $m['tMin']) / 2;
        $p = ['cold' => 0.0, 'hot' => 0.0, 'rain' => 0.0];

        if ($mean < $r['cold_below']) {
            $p['cold'] += min($r['cold_cap'], ($r['cold_below'] - $mean) * $r['cold_per_deg']);
        }
        if ($m['tMin'] < $r['very_cold_tmin']) {
            $p['cold'] += $r['very_cold_penalty'];
        }
        if ($m['tMax'] > $r['hot_above']) {
            $p['hot'] += min($r['hot_cap'], ($m['tMax'] - $r['hot_above']) * $r['hot_per_deg']);
        }
        $p['rain'] += $m['rainyDays'] / $days * $r['rainy_share_weight'];
        if ($m['rainMm'] > $r['heavy_rain_mm']) {
            $p['rain'] += min($r['heavy_rain_cap'], ($m['rainMm'] - $r['heavy_rain_mm']) / 10 * $r['heavy_rain_per_10mm']);
        }

        arsort($p);
        $top = array_key_first($p);

        return ['score' => round(max(0, min(100, 100 - array_sum($p))), 2), 'dominant' => $p[$top] > 0 ? $top : 'none'];
    }

    /**
     * Phân loại cả 12 tháng của một điểm đến.
     *
     * @param array<int,array> $months  [tháng => {tMax,tMin,rainMm,rainyDays}] (thiếu tháng nào bỏ qua tháng đó)
     * @return array<int,array{level:string,note:?string,score:float}>
     */
    public static function evaluate(array $months, array $r): array
    {
        $out = [];
        foreach ($months as $month => $m) {
            if ($m['tMax'] === null || $m['tMin'] === null || $m['rainMm'] === null || $m['rainyDays'] === null) {
                continue;
            }
            $s = self::score($m, (int) $month, $r);
            $level = $s['score'] >= $r['best_min_score'] ? 'best' : ($s['score'] <= $r['avoid_max_score'] ? 'avoid' : 'ok');
            $out[(int) $month] = ['level' => $level, 'score' => $s['score'], 'dominant' => $s['dominant'], 'm' => $m];
        }
        if (!$out) {
            return [];
        }

        // Giới hạn số tháng best; luôn có ít nhất min_best tháng best (tương đối).
        $scores = array_map(fn ($x) => $x['score'], $out);
        arsort($scores);
        $bestMonths = array_keys(array_filter($out, fn ($x) => $x['level'] === 'best'));
        if (count($bestMonths) > $r['max_best']) {
            $keep = array_slice(array_keys($scores), 0, $r['max_best']);
            foreach ($bestMonths as $b) {
                if (!in_array($b, $keep, true)) {
                    $out[$b]['level'] = 'ok';
                }
            }
        } elseif (count($bestMonths) < $r['min_best']) {
            foreach (array_slice(array_keys($scores), 0, $r['min_best']) as $b) {
                if ($out[$b]['level'] !== 'avoid') {
                    $out[$b]['level'] = 'best';
                }
            }
        }

        foreach ($out as $month => &$x) {
            $x['note'] = self::note($x['level'], $x['dominant'], $x['m'], $month);
            unset($x['m'], $x['dominant']);
        }

        return $out;
    }

    private static function note(string $level, string $dominant, array $m, int $month): ?string
    {
        $range = round($m['tMin']) . '–' . round($m['tMax']) . ' °C';
        if ($level === 'best') {
            $share = $m['rainyDays'] / self::DAYS[$month];

            return ($share < 0.2 ? 'Ít mưa' : 'Mưa không nhiều') . ', ' . $range;
        }
        if ($level === 'avoid') {
            return match ($dominant) {
                'rain' => 'Mưa nhiều',
                'hot' => 'Nắng nóng',
                'cold' => 'Trời lạnh',
                default => 'Thời tiết kém thuận lợi',
            } . ', ' . $range;
        }

        return null;
    }
}
