<?php

namespace App\Services\Travel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * T-38 tìm khách sạn quanh một tọa độ (Geoapify Places, nhóm accommodation.hotel). Cache 30 phút.
 * Geoapify không có giá phòng: giá hiển thị là ƯỚC TÍNH theo hạng sao (ghi rõ trên giao diện). Không có khóa / lỗi -> giả lập có nhãn.
 */
class HotelSearchService
{
    public const SERVICE = 'geoapify-hotel';

    /** @return array{hotels:list<array>,simulated:bool,provider:string,label:?string,error:?string} */
    public function search(float $lat, float $lng, string $checkIn, int $nights, int $adults): array
    {
        $nights = max(1, min(14, $nights));
        $rooms = max(1, (int) ceil($adults / 2));
        $key = 'hotel:' . round($lat, 3) . ':' . round($lng, 3) . ":{$checkIn}:{$nights}:{$rooms}";
        if ($hit = Cache::get($key)) {
            return $hit;
        }
        $error = null;
        $hotels = null;
        $apiKey = (string) config('travel_apis.hotel_search.geoapify_key');
        if (!TravelApi::simulateOnly() && $apiKey !== '' && TravelApi::available(self::SERVICE)) {
            try {
                $hotels = $this->geoapify($apiKey, $lat, $lng, $nights, $rooms);
                TravelApi::ok(self::SERVICE);
            } catch (\Throwable $e) {
                TravelApi::failed(self::SERVICE, mb_substr($e->getMessage(), 0, 120));
                $error = 'Tạm thời không lấy được danh sách khách sạn, đang hiển thị dữ liệu thử nghiệm.';
            }
        }
        $simulated = $hotels === null;
        if ($simulated) {
            $hotels = $this->simulate($lat, $lng, $nights, $rooms);
        }
        $out = ['hotels' => $hotels, 'simulated' => $simulated, 'provider' => $simulated ? 'simulated' : 'geoapify',
            'label' => $simulated ? TravelApi::SIM_LABEL : null, 'error' => $error, 'checkIn' => $checkIn, 'nights' => $nights, 'rooms' => $rooms];
        Cache::put($key, $out, now()->addMinutes($error ? 2 : (int) config('travel_apis.hotel_search.cache_minutes', 30)));

        return $out;
    }

    private static function estimate(int $stars, int $nights, int $rooms, string $seed): int
    {
        $perNight = [1 => 350000, 2 => 500000, 3 => 850000, 4 => 1600000, 5 => 3200000][max(1, min(5, $stars))];
        $perNight = (int) round($perNight * (0.85 + TravelApi::seeded($seed, 0, 30) / 100) / 10000) * 10000;

        return $perNight * $nights * $rooms;
    }

    private function geoapify(string $key, float $lat, float $lng, int $nights, int $rooms): array
    {
        $c = config('travel_apis.hotel_search');
        $res = Http::acceptJson()->timeout(TravelApi::timeoutSeconds())->connectTimeout(3)->get('https://api.geoapify.com/v2/places', [
            'categories' => 'accommodation.hotel', 'filter' => "circle:{$lng},{$lat}," . (int) $c['radius_m'], 'bias' => "proximity:{$lng},{$lat}",
            'limit' => (int) $c['limit'], 'lang' => 'vi', 'apiKey' => $key,
        ]);
        if (!$res->successful()) {
            throw new \RuntimeException('HTTP ' . $res->status());
        }
        $out = [];
        foreach ((array) $res->json('features', []) as $f) {
            $p = $f['properties'] ?? [];
            if (empty($p['name'])) {
                continue;
            }
            $stars = (int) ($p['accommodation']['stars'] ?? ($p['datasource']['raw']['stars'] ?? 3)) ?: 3;
            $out[] = [
                'id' => (string) ($p['place_id'] ?? md5($p['name'])), 'name' => (string) $p['name'], 'address' => (string) ($p['address_line2'] ?? ''),
                'stars' => $stars, 'lat' => (float) $p['lat'], 'lng' => (float) $p['lon'], 'distanceM' => (int) ($p['distance'] ?? 0),
                'price' => self::estimate($stars, $nights, $rooms, (string) $p['name']), 'priceEstimated' => true, 'simulated' => false,
                'website' => $p['website'] ?? null,
            ];
        }

        return $out;
    }

    public function simulate(float $lat, float $lng, int $nights, int $rooms): array
    {
        $names = ['Khách sạn Hoa Sen', 'Sông Hàn Boutique', 'Mây Hồng Homestay', 'Phố Cổ Inn', 'Lam Viên Hotel', 'Biển Xanh Resort', 'Hoàng Gia Hotel', 'Cây Dừa Villa'];
        $seed = round($lat, 3) . ',' . round($lng, 3);
        $out = [];
        foreach ($names as $i => $n) {
            $stars = TravelApi::seeded($seed . $n, 2, 5);
            $dLat = (TravelApi::seeded($seed, 0, 400, 50 + $i) - 200) / 100000;
            $dLng = (TravelApi::seeded($seed, 0, 400, 60 + $i) - 200) / 100000;
            $out[] = [
                'id' => 'sim_' . substr(md5($seed . $n), 0, 10), 'name' => $n, 'address' => 'Gần trung tâm', 'stars' => $stars,
                'lat' => round($lat + $dLat, 6), 'lng' => round($lng + $dLng, 6), 'distanceM' => (int) round(sqrt($dLat ** 2 + $dLng ** 2) * 111000),
                'price' => self::estimate($stars, $nights, $rooms, $seed . $n), 'priceEstimated' => true, 'simulated' => true, 'website' => null,
            ];
        }
        usort($out, fn($a, $b) => $a['distanceM'] <=> $b['distanceM']);

        return array_slice($out, 0, 6);
    }
}
