<?php

namespace App\Services\Discover;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Thời tiết Open-Meteo, cache trong tbl_destination_weather.
 * refreshAll() chỉ gọi từ lệnh/schedule; web và API chỉ đọc DB (get/getMany).
 * Dữ liệu thời tiết: Open-Meteo.com (CC BY 4.0).
 */
class WeatherService
{
    /** WMO weather code -> [nhãn tiếng Việt, icon Font Awesome] */
    private const CODES = [
        0 => ['Trời quang', 'fa-sun'],
        1 => ['Ít mây', 'fa-cloud-sun'],
        2 => ['Có mây', 'fa-cloud-sun'],
        3 => ['Nhiều mây', 'fa-cloud'],
        45 => ['Sương mù', 'fa-smog'],
        48 => ['Sương mù', 'fa-smog'],
        51 => ['Mưa phùn nhẹ', 'fa-cloud-rain'],
        53 => ['Mưa phùn', 'fa-cloud-rain'],
        55 => ['Mưa phùn dày', 'fa-cloud-rain'],
        56 => ['Mưa phùn lạnh', 'fa-cloud-rain'],
        57 => ['Mưa phùn lạnh', 'fa-cloud-rain'],
        61 => ['Mưa nhẹ', 'fa-cloud-rain'],
        63 => ['Mưa vừa', 'fa-cloud-showers-heavy'],
        65 => ['Mưa to', 'fa-cloud-showers-heavy'],
        66 => ['Mưa lạnh', 'fa-cloud-rain'],
        67 => ['Mưa lạnh to', 'fa-cloud-showers-heavy'],
        71 => ['Tuyết nhẹ', 'fa-snowflake'],
        73 => ['Tuyết', 'fa-snowflake'],
        75 => ['Tuyết dày', 'fa-snowflake'],
        77 => ['Hạt tuyết', 'fa-snowflake'],
        80 => ['Mưa rào nhẹ', 'fa-cloud-sun-rain'],
        81 => ['Mưa rào', 'fa-cloud-showers-heavy'],
        82 => ['Mưa rào lớn', 'fa-cloud-showers-heavy'],
        85 => ['Tuyết rào', 'fa-snowflake'],
        86 => ['Tuyết rào lớn', 'fa-snowflake'],
        95 => ['Dông', 'fa-bolt'],
        96 => ['Dông kèm mưa đá', 'fa-bolt'],
        99 => ['Dông kèm mưa đá', 'fa-bolt'],
    ];

    public static function describe(?int $code): array
    {
        [$label, $icon] = self::CODES[$code] ?? ['Không rõ', 'fa-cloud'];

        return ['label' => $label, 'icon' => $icon];
    }

    /**
     * Chuẩn hoá 1 phần tử trả về của Open-Meteo thành payload lưu DB.
     */
    public static function normalize(array $r): ?array
    {
        $c = $r['current'] ?? null;
        $d = $r['daily'] ?? null;
        if (!$c || !$d || !isset($d['time'])) {
            return null;
        }

        $code = isset($c['weather_code']) ? (int) $c['weather_code'] : null;
        $days = [];
        foreach ($d['time'] as $i => $date) {
            $dc = isset($d['weather_code'][$i]) ? (int) $d['weather_code'][$i] : null;
            $days[] = [
                'date' => $date,
                'code' => $dc,
                'label' => self::describe($dc)['label'],
                'icon' => self::describe($dc)['icon'],
                'tMax' => isset($d['temperature_2m_max'][$i]) ? round($d['temperature_2m_max'][$i]) : null,
                'tMin' => isset($d['temperature_2m_min'][$i]) ? round($d['temperature_2m_min'][$i]) : null,
                'rain' => $d['precipitation_probability_max'][$i] ?? null,
            ];
        }

        return [
            'current' => [
                'temp' => isset($c['temperature_2m']) ? round($c['temperature_2m']) : null,
                'feels' => isset($c['apparent_temperature']) ? round($c['apparent_temperature']) : null,
                'humidity' => $c['relative_humidity_2m'] ?? null,
                'wind' => isset($c['wind_speed_10m']) ? round($c['wind_speed_10m']) : null,
                'code' => $code,
                'label' => self::describe($code)['label'],
                'icon' => self::describe($code)['icon'],
                'time' => $c['time'] ?? null,
            ],
            'daily' => $days,
        ];
    }

    /**
     * Gọi Open-Meteo cho tất cả điểm đến (gộp nhiều toạ độ / request) rồi ghi cache.
     *
     * @return array{updated:int,failed:int}
     */
    public function refreshAll(?string $slug = null): array
    {
        $q = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('destinationId');
        if ($slug) {
            $q->where('slug', $slug);
        }
        $rows = $q->get(['destinationId', 'latitude', 'longitude']);

        $updated = $failed = 0;
        foreach ($rows->chunk((int) config('discover.weather.chunk', 20)) as $chunk) {
            $chunk = $chunk->values();
            $params = [
                'latitude' => $chunk->pluck('latitude')->map(fn($v) => (float) $v)->implode(','),
                'longitude' => $chunk->pluck('longitude')->map(fn($v) => (float) $v)->implode(','),
                'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                'timezone' => config('discover.weather.timezone'),
                'forecast_days' => (int) config('discover.weather.days', 5),
            ];
            if ($key = config('discover.weather.apikey')) {
                $params['apikey'] = $key;
            }

            $res = Http::withHeaders(['User-Agent' => config('discover.user_agent')])
                ->timeout(config('discover.http_timeout'))
                ->retry(2, 1000, throw: false)
                ->get(config('discover.weather.endpoint'), $params);

            if (!$res->ok()) {
                $failed += $chunk->count();
                continue;
            }

            // 1 toạ độ -> object; nhiều toạ độ -> mảng object (cùng thứ tự)
            $json = $res->json();
            $list = isset($json['current']) ? [$json] : (array) $json;

            foreach ($chunk as $i => $row) {
                $payload = isset($list[$i]) ? self::normalize($list[$i]) : null;
                if (!$payload) {
                    $failed++;
                    continue;
                }
                DB::table('tbl_destination_weather')->updateOrInsert(
                    ['destinationId' => $row->destinationId],
                    ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'fetchedAt' => now()]
                );
                $updated++;
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** Đọc cache của 1 điểm đến (không gọi mạng). */
    public function get(int $destinationId): ?array
    {
        return $this->getMany([$destinationId])[$destinationId] ?? null;
    }

    /** @return array<int,array> destinationId => payload + fetchedAt + stale */
    public function getMany(array $ids): array
    {
        $fresh = now()->subMinutes((int) config('discover.weather.fresh_minutes', 180));
        $out = [];
        foreach (DB::table('tbl_destination_weather')->whereIn('destinationId', $ids)->get() as $r) {
            $p = json_decode($r->payload, true);
            if (!is_array($p)) {
                continue;
            }
            $at = \Illuminate\Support\Carbon::parse($r->fetchedAt);
            $p['fetchedAt'] = $at->toIso8601String();
            $p['stale'] = $at->lt($fresh);
            $out[(int) $r->destinationId] = $p;
        }

        return $out;
    }
}
