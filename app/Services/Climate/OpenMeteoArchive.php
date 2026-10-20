<?php

namespace App\Services\Climate;

use Illuminate\Support\Facades\Http;

/** Lấy dữ liệu ngày từ Open-Meteo Archive (ERA5) và gộp thành trung bình 12 tháng. */
class OpenMeteoArchive
{
    /** @return array{daily: array} */
    public function fetch(float $lat, float $lng, string $from, string $to): array
    {
        $res = Http::withHeaders(['User-Agent' => config('discover.user_agent')])->timeout(60)->get(config('climate.archive_endpoint'), [
            'latitude' => $lat, 'longitude' => $lng, 'start_date' => $from, 'end_date' => $to,
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum',
            'timezone' => config('climate.timezone'),
        ] + (config('discover.weather.apikey') ? ['apikey' => config('discover.weather.apikey')] : []));

        if (!$res->ok() || !is_array($res->json('daily.time'))) {
            throw new \RuntimeException('Open-Meteo Archive HTTP ' . $res->status() . ' ' . mb_substr((string) $res->json('reason'), 0, 120));
        }

        return ['daily' => $res->json('daily')];
    }

    /**
     * Gộp dữ liệu ngày thành trung bình theo tháng của năm: nhiệt độ trung bình của ngày cao nhất/thấp nhất,
     * tổng mưa của tháng và số ngày mưa trung bình qua các năm. Bỏ qua ngày thiếu dữ liệu (null).
     *
     * @param array $daily {time[],temperature_2m_max[],temperature_2m_min[],precipitation_sum[]}
     * @return array<int,array{tMax:?float,tMin:?float,rainMm:?float,rainyDays:?float,years:int}>
     */
    public static function aggregate(array $daily, float $rainyDayMm = 1.0): array
    {
        $acc = []; // [month][year] => sums
        foreach ($daily['time'] as $i => $date) {
            $y = (int) substr($date, 0, 4);
            $mo = (int) substr($date, 5, 2);
            $a = &$acc[$mo][$y];
            $a ??= ['mx' => 0.0, 'nmx' => 0, 'mn' => 0.0, 'nmn' => 0, 'rain' => 0.0, 'nr' => 0, 'rd' => 0];
            $tx = $daily['temperature_2m_max'][$i] ?? null;
            $tn = $daily['temperature_2m_min'][$i] ?? null;
            $pr = $daily['precipitation_sum'][$i] ?? null;
            if ($tx !== null) { $a['mx'] += $tx; $a['nmx']++; }
            if ($tn !== null) { $a['mn'] += $tn; $a['nmn']++; }
            if ($pr !== null) { $a['rain'] += $pr; $a['nr']++; if ($pr >= $rainyDayMm) { $a['rd']++; } }
            unset($a);
        }

        $out = [];
        for ($mo = 1; $mo <= 12; $mo++) {
            $years = $acc[$mo] ?? [];
            $avg = function (callable $val, callable $ok) use ($years) {
                $vals = [];
                foreach ($years as $a) {
                    if ($ok($a)) { $vals[] = $val($a); }
                }

                return $vals ? round(array_sum($vals) / count($vals), 1) : null;
            };
            $out[$mo] = [
                'tMax' => $avg(fn ($a) => $a['mx'] / $a['nmx'], fn ($a) => $a['nmx'] > 0),
                'tMin' => $avg(fn ($a) => $a['mn'] / $a['nmn'], fn ($a) => $a['nmn'] > 0),
                'rainMm' => $avg(fn ($a) => $a['rain'], fn ($a) => $a['nr'] > 0),
                'rainyDays' => $avg(fn ($a) => $a['rd'], fn ($a) => $a['nr'] > 0),
                'years' => count($years),
            ];
        }

        return $out;
    }
}
