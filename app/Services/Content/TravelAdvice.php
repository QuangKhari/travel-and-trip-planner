<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * Tuần 5: "Thời điểm đi" (Travel Intelligence).
 * Biến mức tháng (tbl_destination_months) và khí hậu (tbl_destination_climate) thành lời khuyên đọc được:
 * mức đẹp, nên làm gì, cần lưu ý gì, nên mang gì. Luật nằm ở config/travel_advice.php; mọi câu đều suy ra từ số liệu.
 */
class TravelAdvice
{
    public const LABELS = ['best' => 'Đẹp nhất', 'ok' => 'Đi được', 'avoid' => 'Nên cân nhắc', 'unknown' => 'Chưa có đánh giá'];
    public const DISCLAIMER = 'Gợi ý từ trung bình khí hậu nhiều năm (Open-Meteo.com, CC BY 4.0), không phải dự báo thời tiết.';

    /** Số ngày mỗi tháng (tháng 2 tính 28, giống ClimateRules vì dữ liệu là trung bình nhiều năm). */
    private const DAYS = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

    /**
     * Hàm thuần (không đụng DB) để dễ kiểm thử.
     *
     * @param array|null $m  {tMax,tMin,rainMm,rainyDays} hoặc null nếu chưa có khí hậu
     * @return array{month:int,level:string,label:string,stars:int,summary:string,suitable:list<string>,caution:list<string>,packing:list<string>,hasData:bool}
     */
    public static function build(?array $m, string $level, ?string $note, int $month, ?array $cfg = null): array
    {
        $cfg ??= config('travel_advice');
        $level = isset(self::LABELS[$level]) ? $level : 'unknown';
        $has = $m !== null && isset($m['tMax'], $m['tMin'], $m['rainMm'], $m['rainyDays']);

        $f = ['hot' => false, 'cold' => false, 'rainy' => false, 'dry' => false, 'pleasant' => false];
        $caution = [];
        if ($has) {
            $tMax = (float) $m['tMax'];
            $tMin = (float) $m['tMin'];
            $rain = (float) $m['rainMm'];
            $rd = (float) $m['rainyDays'];
            $share = $rd / self::DAYS[$month];

            $f['hot'] = $tMax > $cfg['hot_tmax'];
            $f['cold'] = $tMin < $cfg['cold_tmin'];
            $f['rainy'] = $share >= $cfg['rainy_share_high'] || $rain > $cfg['heavy_rain_mm'];
            $f['dry'] = $share < $cfg['rainy_share_low'];
            $f['pleasant'] = !$f['hot'] && !$f['cold'] && !$f['rainy'];

            if ($share >= $cfg['rainy_share_high']) {
                $caution[] = 'Mưa nhiều: khoảng ' . (int) round($rd) . ' ngày mưa trong tháng.';
            }
            if ($rain > $cfg['heavy_rain_mm']) {
                $caution[] = 'Tổng lượng mưa khoảng ' . (int) round($rain) . ' mm, có thể có mưa lớn kéo dài.';
            }
            if ($f['hot']) {
                $caution[] = 'Nắng nóng (cao nhất trung bình ' . (int) round($tMax) . '°C): nên tránh khoảng 11h–15h.';
            }
            if ($f['cold']) {
                $caution[] = 'Trời lạnh (thấp nhất trung bình ' . (int) round($tMin) . '°C): sáng sớm và đêm cần giữ ấm.';
            }
        }

        $suitable = [];
        $packing = [];
        $pick = function (string $key) use ($cfg, &$suitable, &$packing) {
            $suitable = array_merge($suitable, $cfg['suitable'][$key] ?? []);
            $packing = array_merge($packing, $cfg['packing'][$key] ?? []);
        };
        if ($f['pleasant']) {
            $pick('pleasant');
        }
        if ($f['dry'] && !$f['hot'] && !$f['cold']) {
            $pick('trek');
        }
        foreach (['hot', 'rainy', 'cold'] as $k) {
            if ($f[$k]) {
                $pick($k);
            }
        }

        $stars = match ($level) {
            'best' => $f['pleasant'] ? 5 : 4,
            'ok' => 3,
            'avoid' => 1,
            default => 0,
        };

        if ($has) {
            $summary = self::LABELS[$level] . ' · ' . (int) round((float) $m['tMin']) . '–' . (int) round((float) $m['tMax']) . '°C, khoảng ' . (int) round((float) $m['rainyDays']) . ' ngày mưa';
        } else {
            $summary = $note ?: 'Chưa đủ dữ liệu khí hậu cho tháng này.';
        }

        return [
            'month' => $month,
            'level' => $level,
            'label' => self::LABELS[$level],
            'stars' => $stars,
            'summary' => $summary,
            'suitable' => array_values(array_unique($suitable)),
            'caution' => $caution,
            'packing' => array_values(array_unique($packing)),
            'hasData' => $has,
        ];
    }

    /** Lời khuyên cả 12 tháng của một điểm đến (2 truy vấn). @return array<int,array> */
    public function forDestination(int $destinationId): array
    {
        $lv = DB::table('tbl_destination_months')->where('destinationId', $destinationId)->get()->keyBy('month');
        $cl = DB::table('tbl_destination_climate')->where('destinationId', $destinationId)->get()->keyBy('month');
        $cfg = config('travel_advice');
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $m = isset($cl[$i]) ? ['tMax' => $cl[$i]->tMax, 'tMin' => $cl[$i]->tMin, 'rainMm' => $cl[$i]->rainMm, 'rainyDays' => $cl[$i]->rainyDays] : null;
            $out[$i] = self::build($m, $lv[$i]->level ?? 'unknown', $lv[$i]->note ?? null, $i, $cfg);
        }

        return $out;
    }

    public function forMonth(int $destinationId, int $month): array
    {
        return $this->forDestination($destinationId)[max(1, min(12, $month))];
    }

    /**
     * Mức tháng của nhiều điểm đến trong một tháng (cho cảnh báo của Planner).
     *
     * @param list<int> $destinationIds
     * @return array<int,array{level:string,note:?string}>
     */
    public function verdicts(array $destinationIds, int $month): array
    {
        if (!$destinationIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbl_destination_months')->whereIn('destinationId', $destinationIds)->where('month', $month)->get() as $r) {
            $out[(int) $r->destinationId] = ['level' => $r->level, 'note' => $r->note];
        }

        return $out;
    }

    /** [10, 11, 12, 1] => "T10–T1"; [3, 7] => "T3, T7". Tháng liền nhau qua năm được gộp. */
    public static function ranges(array $months): string
    {
        $m = array_values(array_unique(array_map('intval', $months)));
        sort($m);
        if (!$m) {
            return '';
        }
        $runs = [];
        foreach ($m as $x) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][1] === $x - 1) {
                $runs[$last][1] = $x;
            } else {
                $runs[] = [$x, $x];
            }
        }
        if (count($runs) > 1 && $runs[0][0] === 1 && $runs[count($runs) - 1][1] === 12) {
            $tail = array_pop($runs);
            $runs[0][0] = $tail[0];   // gộp cuối năm với đầu năm: bắt đầu từ tháng của đoạn cuối, kết thúc ở đoạn đầu
        }
        $parts = array_map(fn($r) => $r[0] === $r[1] ? 'T' . $r[0] : 'T' . $r[0] . '–T' . $r[1], $runs);

        return implode(', ', $parts);
    }
}
