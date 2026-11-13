<?php

namespace App\Services\Planner;

use App\Services\Discover\WeatherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Dự báo thời tiết THEO GIỜ cho chuyến đi (T-15, T-22), nguồn Open-Meteo Forecast (CC BY 4.0, không cần khóa).
 *
 * Cache theo giờ (T-22): khóa `wx:fc:{lat}:{lng}:{YYYYmmddHH}` sống tới hết giờ hiện tại, nên mỗi ô lưới (~11 km, làm tròn 0,1°)
 * gọi Open-Meteo tối đa MỘT lần mỗi giờ dù bao nhiêu người mở chuyến. Bản tốt gần nhất giữ thêm 24 giờ (`wx:fc:last:*`) để
 * khi Open-Meteo lỗi vẫn có số liệu (đánh dấu `stale`). Lỗi mạng không được cache; cầu dao ExternalGuard('openmeteo') ngắt 5 phút.
 *
 * Phạm vi: Open-Meteo dự báo 16 ngày. Ngày nằm ngoài phạm vi (hoặc chuyến chưa có ngày đi) dùng số liệu khí hậu theo tháng
 * (payload['rain']['context']) như trước, `source = climate`, không có số theo giờ.
 */
final class TripWeather
{
    public const SERVICE = 'openmeteo';

    public static function enabled(): bool
    {
        return (bool) config('planner.weather.enabled', true);
    }

    /** Khóa cache của giờ hiện tại cho một điểm lưới. */
    public static function cacheKey(float $lat, float $lng, ?\DateTimeInterface $at = null): string
    {
        [$la, $ln] = self::grid($lat, $lng);

        return 'wx:fc:' . $la . ':' . $ln . ':' . ($at ? $at->format('YmdH') : now()->format('YmdH'));
    }

    /** @return array{0:float,1:float} */
    public static function grid(float $lat, float $lng): array
    {
        return [round($lat, 1), round($lng, 1)];
    }

    /**
     * Chuẩn hóa phản hồi Open-Meteo thành theo ngày: date => {code, tMax, tMin, rainMax, hours: [h => {p, t, mm, code, wind}]}
     * Hàm thuần để kiểm thử.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function normalize(array $json): array
    {
        $out = [];
        $h = $json['hourly'] ?? [];
        foreach ((array) ($h['time'] ?? []) as $i => $iso) {
            $date = substr((string) $iso, 0, 10);
            $hour = (int) substr((string) $iso, 11, 2);
            $out[$date]['hours'][$hour] = [
                'p' => isset($h['precipitation_probability'][$i]) ? (int) $h['precipitation_probability'][$i] : null,
                't' => isset($h['temperature_2m'][$i]) ? (int) round($h['temperature_2m'][$i]) : null,
                'mm' => isset($h['precipitation'][$i]) ? round((float) $h['precipitation'][$i], 1) : null,
                'code' => isset($h['weather_code'][$i]) ? (int) $h['weather_code'][$i] : null,
                'wind' => isset($h['wind_speed_10m'][$i]) ? (int) round($h['wind_speed_10m'][$i]) : null,
            ];
        }
        $d = $json['daily'] ?? [];
        foreach ((array) ($d['time'] ?? []) as $i => $date) {
            $out[$date] = ($out[$date] ?? ['hours' => []]) + [
                'code' => isset($d['weather_code'][$i]) ? (int) $d['weather_code'][$i] : null,
                'tMax' => isset($d['temperature_2m_max'][$i]) ? (int) round($d['temperature_2m_max'][$i]) : null,
                'tMin' => isset($d['temperature_2m_min'][$i]) ? (int) round($d['temperature_2m_min'][$i]) : null,
                'rainMax' => isset($d['precipitation_probability_max'][$i]) ? (int) $d['precipitation_probability_max'][$i] : null,
            ];
        }
        ksort($out);

        return $out;
    }

    /**
     * Dự báo cho nhiều điểm, gộp các điểm chưa có cache thành MỘT lần gọi.
     *
     * @param list<array{0:float,1:float}> $points
     * @return array<string,array{days:array,fetchedAt:string,stale:bool}|null>  khóa "lat,lng" đã làm tròn
     */
    public function forPoints(array $points): array
    {
        $out = [];
        $miss = [];
        foreach ($points as $p) {
            [$la, $ln] = self::grid((float) $p[0], (float) $p[1]);
            $k = $la . ',' . $ln;
            if (array_key_exists($k, $out)) {
                continue;
            }
            $hit = $this->cacheGet(self::cacheKey($la, $ln));
            if ($hit !== null) {
                $out[$k] = $hit;
            } else {
                $out[$k] = null;
                $miss[$k] = [$la, $ln];
            }
        }
        if ($miss && self::enabled()) {
            $fetched = ExternalGuard::allowed(self::SERVICE) ? $this->fetch(array_values($miss)) : null;
            foreach (array_keys($miss) as $i => $k) {
                [$la, $ln] = $miss[$k];
                if ($fetched !== null && isset($fetched[$i])) {
                    $v = ['days' => $fetched[$i], 'fetchedAt' => now()->toIso8601String(), 'stale' => false];
                    $this->cachePut(self::cacheKey($la, $ln), $v, now()->startOfHour()->addHour()->addMinute());
                    $this->cachePut('wx:fc:last:' . $la . ':' . $ln, $v, now()->addDay());
                    $out[$k] = $v;
                } else {
                    $last = $this->cacheGet('wx:fc:last:' . $la . ':' . $ln);
                    $out[$k] = $last ? ['stale' => true] + $last : null;
                }
            }
        }

        return $out;
    }

    /** @param list<array{0:float,1:float}> $pts  @return list<array>|null */
    private function fetch(array $pts): ?array
    {
        $cfg = (array) config('planner.weather');
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('discover.user_agent', 'Travela')])
                ->connectTimeout((int) ($cfg['connect_timeout'] ?? 2))->timeout((int) ($cfg['timeout'] ?? 4))
                ->get((string) ($cfg['endpoint'] ?? 'https://api.open-meteo.com/v1/forecast'), [
                    'latitude' => implode(',', array_column($pts, 0)), 'longitude' => implode(',', array_column($pts, 1)),
                    'hourly' => 'temperature_2m,precipitation_probability,precipitation,weather_code,wind_speed_10m',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                    'timezone' => (string) ($cfg['timezone'] ?? 'Asia/Ho_Chi_Minh'), 'forecast_days' => (int) ($cfg['days'] ?? 16),
                ] + (config('discover.weather.apikey') ? ['apikey' => config('discover.weather.apikey')] : []));
        } catch (\Throwable $e) {
            ExternalGuard::trip(self::SERVICE, 'lỗi kết nối: ' . mb_substr($e->getMessage(), 0, 80));

            return null;
        }
        if (!$res->ok()) {
            ExternalGuard::trip(self::SERVICE, 'HTTP ' . $res->status());

            return null;
        }
        $json = $res->json();
        $list = isset($json['hourly']) ? [$json] : (array) $json;
        if (count($list) !== count($pts)) {
            ExternalGuard::trip(self::SERVICE, 'phản hồi không đủ điểm');

            return null;
        }

        return array_map(fn($x) => self::normalize((array) $x), $list);
    }

    /**
     * Thời tiết từng ngày của chuyến: ngày nào có dự báo thì có số theo giờ, còn lại theo mùa.
     *
     * @param array $payload TripPlanner::payload (cần days[].items[].lat/lng, startDate)
     * @return array{source:string,fetchedAt:?string,stale:bool,days:array<int,array<string,mixed>>,reason:?string}
     */
    public function forTrip(object $trip, array $payload): array
    {
        $empty = ['source' => 'none', 'fetchedAt' => null, 'stale' => false, 'days' => [], 'reason' => null];
        if (!self::enabled()) {
            return ['reason' => 'off'] + $empty;
        }
        if (empty($trip->startDate)) {
            return ['reason' => 'no_date'] + $empty;
        }
        $horizon = (int) config('planner.weather.days', 16);
        $today = now()->startOfDay();
        $center = $this->destCenter($trip);
        $want = [];
        foreach ($payload['days'] ?? [] as $d) {
            $date = \Carbon\Carbon::parse($trip->startDate)->addDays((int) $d['day'] - 1)->startOfDay();
            $diff = (int) $today->diffInDays($date, false);
            if ($diff < 0 || $diff >= $horizon) {
                continue;
            }
            $pts = array_values(array_filter(array_map(fn($it) => isset($it['lat'], $it['lng']) && $it['lat'] !== null && $it['lng'] !== null
                && \App\Services\Planner\Route\HaversineProvider::validPoint([(float) $it['lat'], (float) $it['lng']]) ? [(float) $it['lat'], (float) $it['lng']] : null, $d['items'] ?? [])));
            $pt = $pts ? [array_sum(array_column($pts, 0)) / count($pts), array_sum(array_column($pts, 1)) / count($pts)] : $center;
            if ($pt) {
                $want[(int) $d['day']] = ['date' => $date->toDateString(), 'pt' => $pt];
            }
        }
        if (!$want) {
            return ['reason' => 'out_of_range'] + $empty;
        }
        $fc = $this->forPoints(array_column($want, 'pt'));
        $days = [];
        $fetchedAt = null;
        $stale = false;
        foreach ($want as $day => $w) {
            [$la, $ln] = self::grid($w['pt'][0], $w['pt'][1]);
            $f = $fc[$la . ',' . $ln] ?? null;
            $dd = $f['days'][$w['date']] ?? null;
            if (!$dd) {
                continue;
            }
            $fetchedAt = $fetchedAt ?: ($f['fetchedAt'] ?? null);
            $stale = $stale || !empty($f['stale']);
            $desc = WeatherService::describe($dd['code'] ?? null);
            $days[$day] = ['day' => $day, 'date' => $w['date'], 'code' => $dd['code'] ?? null, 'label' => $desc['label'], 'icon' => $desc['icon'],
                'tMax' => $dd['tMax'] ?? null, 'tMin' => $dd['tMin'] ?? null, 'rainMax' => $dd['rainMax'] ?? null, 'hours' => $dd['hours'] ?? []];
        }
        if (!$days) {
            return ['reason' => 'unavailable'] + $empty;
        }

        return ['source' => 'forecast', 'fetchedAt' => $fetchedAt, 'stale' => $stale, 'days' => $days, 'reason' => null];
    }

    /** Ngày có mưa đáng kể trong khung giờ đi chơi (mặc định 08–20h). */
    public static function dayRain(array $day): int
    {
        $max = 0;
        foreach ((array) ($day['hours'] ?? []) as $h => $x) {
            if ($h >= 8 && $h <= 20 && isset($x['p'])) {
                $max = max($max, (int) $x['p']);
            }
        }

        return $max ?: (int) ($day['rainMax'] ?? 0);
    }

    /**
     * Số theo giờ trong khoảng [startMin, endMin): khả năng mưa cao nhất, nhiệt độ cao nhất, gió lớn nhất, có dông không.
     * @return array{p:int,t:?int,wind:int,storm:bool,hours:int}
     */
    public static function window(array $day, int $startMin, int $endMin): array
    {
        $p = 0;
        $t = null;
        $wind = 0;
        $storm = false;
        $n = 0;
        $from = intdiv(max(0, $startMin), 60);
        $to = intdiv(max($startMin + 1, $endMin) - 1, 60);
        for ($h = $from; $h <= min(23, $to); $h++) {
            $x = $day['hours'][$h] ?? null;
            if (!$x) {
                continue;
            }
            $n++;
            $p = max($p, (int) ($x['p'] ?? 0));
            if (isset($x['t'])) {
                $t = $t === null ? (int) $x['t'] : max($t, (int) $x['t']);
            }
            $wind = max($wind, (int) ($x['wind'] ?? 0));
            $storm = $storm || (int) ($x['code'] ?? 0) >= 95;
        }

        return ['p' => $p, 't' => $t, 'wind' => $wind, 'storm' => $storm, 'hours' => $n];
    }

    /** Giờ có thời tiết xấu cho hoạt động ngoài trời (mưa, dông, gió mạnh, nắng nóng giữa trưa). */
    public static function badHour(array $x, int $h): bool
    {
        $c = (array) config('planner.weather');

        return (int) ($x['p'] ?? 0) >= (int) ($c['rain_warn'] ?? 60)
            || (int) ($x['code'] ?? 0) >= 95
            || (int) ($x['wind'] ?? 0) >= (int) ($c['wind_warn'] ?? 40)
            || ($h >= 11 && $h < 15 && (int) ($x['t'] ?? 0) >= (int) ($c['heat_warn'] ?? 35));
    }

    /**
     * A-10b WeatherFit của một ngày: 1 − (phút hoạt động ngoài trời rơi vào giờ thời tiết xấu / tổng phút ngoài trời).
     * Ngày không có hoạt động ngoài trời = 1.
     *
     * @param list<array{start:int,end:int,outdoor:?bool}> $rows
     */
    public static function fit(array $day, array $rows): float
    {
        $total = 0;
        $bad = 0;
        foreach ($rows as $r) {
            if (($r['outdoor'] ?? null) !== true) {
                continue;
            }
            for ($m = (int) $r['start']; $m < (int) $r['end']; $m += 5) {
                $total += 5;
                $h = intdiv($m, 60);
                if (isset($day['hours'][$h]) && self::badHour($day['hours'][$h], $h)) {
                    $bad += 5;
                }
            }
        }

        return $total ? round(1 - $bad / $total, 3) : 1.0;
    }

    private function destCenter(object $trip): ?array
    {
        if (empty($trip->destinationId)) {
            return null;
        }
        $d = \Illuminate\Support\Facades\DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->first(['latitude', 'longitude']);

        return $d && $d->latitude !== null && $d->longitude !== null ? [(float) $d->latitude, (float) $d->longitude] : null;
    }

    private function cacheGet(string $k): ?array
    {
        try {
            $v = Cache::get($k);

            return is_array($v) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cachePut(string $k, array $v, \DateTimeInterface $until): void
    {
        try {
            Cache::put($k, $v, $until);
        } catch (\Throwable) {
        }
    }
}
