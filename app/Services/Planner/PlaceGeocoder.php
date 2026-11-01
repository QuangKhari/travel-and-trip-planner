<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tìm địa điểm THEO TÊN ở ngoài kho nội dung của Travela (khách sạn, quán ăn, điểm tham quan... bất kỳ).
 * Máy chủ gọi dịch vụ ngoài, trình duyệt chỉ gọi route của Travela. Kết quả KHÔNG tự lưu: chỉ khi người dùng bấm "Thêm"
 * thì thành một mục tự thêm của riêng chuyến đi (có tọa độ, source = 'search'), nên không đi vào kho nội dung và không cần duyệt.
 *
 * Nhà cung cấp: photon (OpenStreetMap, không khóa) hoặc geoapify (cần khóa). Xem config/planner.php.
 * Hàm parse* là hàm thuần để kiểm thử bằng dữ liệu mẫu.
 */
class PlaceGeocoder
{
    /** osm_value / loại Geoapify => nhãn tiếng Việt ngắn hiển thị cạnh kết quả. */
    private const KIND = [
        'hotel' => 'Khách sạn', 'guest_house' => 'Nhà nghỉ', 'hostel' => 'Hostel', 'motel' => 'Nhà nghỉ', 'apartment' => 'Căn hộ', 'resort' => 'Resort', 'homestay' => 'Homestay',
        'restaurant' => 'Nhà hàng', 'fast_food' => 'Quán ăn nhanh', 'food_court' => 'Khu ẩm thực', 'cafe' => 'Quán cà phê', 'bar' => 'Quán bar', 'pub' => 'Quán bar', 'ice_cream' => 'Kem',
        'museum' => 'Bảo tàng', 'gallery' => 'Phòng tranh', 'attraction' => 'Điểm tham quan', 'viewpoint' => 'Điểm ngắm cảnh', 'theme_park' => 'Công viên giải trí', 'zoo' => 'Sở thú',
        'park' => 'Công viên', 'beach' => 'Bãi biển', 'peak' => 'Đỉnh núi', 'place_of_worship' => 'Nơi thờ tự', 'temple' => 'Đền, chùa', 'monument' => 'Di tích', 'memorial' => 'Đài tưởng niệm',
        'castle' => 'Thành, lâu đài', 'ruins' => 'Di tích', 'marketplace' => 'Chợ', 'market' => 'Chợ', 'mall' => 'Trung tâm thương mại', 'supermarket' => 'Siêu thị',
        'bus_station' => 'Bến xe', 'station' => 'Nhà ga', 'aerodrome' => 'Sân bay', 'airport' => 'Sân bay', 'ferry_terminal' => 'Bến phà', 'port' => 'Cảng',
        'city' => 'Thành phố', 'town' => 'Thị xã', 'village' => 'Làng', 'suburb' => 'Khu vực', 'district' => 'Quận, huyện', 'island' => 'Đảo', 'bay' => 'Vịnh',
    ];

    public function provider(): string
    {
        $p = strtolower((string) config('planner.geocoder'));
        if ($p === 'off') {
            return 'off';
        }
        if (in_array($p, ['photon', 'geoapify'], true)) {
            return $p;
        }

        return config('planner.geoapify_key') ? 'geoapify' : 'photon';
    }

    public function enabled(): bool
    {
        return $this->provider() !== 'off';
    }

    /**
     * @return array{ok:bool,provider:string,results:list<array{name:string,address:string,kind:?string,lat:float,lng:float,key:string}>}
     *         ok = false khi dịch vụ ngoài lỗi (giao diện báo "chưa tìm được lúc này", không coi là "không có kết quả").
     */
    public function search(string $q, ?float $lat = null, ?float $lng = null): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        $provider = $this->provider();
        if ($provider === 'off' || mb_strlen($q) < 2) {
            return ['ok' => $provider !== 'off', 'provider' => $provider, 'results' => []];
        }
        $q = mb_substr($q, 0, 80);
        $key = 'geo:' . $provider . ':' . md5(mb_strtolower($q) . '|' . ($lat === null ? '' : round($lat, 1)) . ',' . ($lng === null ? '' : round($lng, 1)));
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return ['ok' => true, 'provider' => $provider, 'results' => $hit];
        }

        if (!ExternalGuard::allowed('geocoder')) {
            return ['ok' => false, 'provider' => $provider, 'results' => []];   // vừa lỗi gần đây: trả ngay, không chặn máy chủ
        }
        try {
            $json = $provider === 'geoapify' ? $this->fetchGeoapify($q, $lat, $lng) : $this->fetchPhoton($q, $lat, $lng);
        } catch (Throwable $e) {
            Log::warning('PlaceGeocoder ' . $provider . ': ' . $e->getMessage());
            ExternalGuard::trip('geocoder', mb_substr($e->getMessage(), 0, 120));

            return ['ok' => false, 'provider' => $provider, 'results' => []];
        }
        $results = $provider === 'geoapify' ? self::parseGeoapify($json) : self::parsePhoton($json);
        $results = array_slice($results, 0, (int) config('planner.geocode_limit', 8));
        Cache::put($key, $results, now()->addMinutes((int) config('planner.geocode_cache_minutes', 360)));

        return ['ok' => true, 'provider' => $provider, 'results' => $results];
    }

    private function http()
    {
        // không thử lại: một lần chậm đã đủ làm cả trang (php artisan serve 1 luồng) chờ theo
        return Http::connectTimeout(3)->timeout((int) config('planner.geocode_timeout', 5))
            ->withHeaders(['User-Agent' => (string) config('discover.user_agent', 'TravelaGraduationProject/1.0'), 'Accept' => 'application/json']);
    }

    /** Nhóm của PlaceFinder => [từ khóa, thẻ OSM] cho Photon. */
    private const NEARBY = [
        'hotel' => ['hotel', ['tourism:hotel', 'tourism:guest_house', 'tourism:hostel', 'tourism:motel', 'tourism:apartment']],
        'cafe' => ['cafe', ['amenity:cafe']],
        'restaurant' => ['restaurant', ['amenity:restaurant', 'amenity:fast_food', 'amenity:food_court']],
        'bar' => ['bar', ['amenity:bar', 'amenity:pub', 'amenity:nightclub']],
        'market' => ['market', ['amenity:marketplace', 'shop:mall', 'shop:supermarket']],
        'sight' => ['attraction', ['tourism:attraction', 'tourism:museum', 'tourism:viewpoint', 'historic:monument']],
    ];

    /**
     * Nguồn trực tiếp mặc định khi bấm "Cà phê", "Khách sạn"...: hỏi Photon các địa điểm có thẻ OSM của nhóm quanh tâm.
     * Photon là máy chủ tìm kiếm (trả lời trong ~1 giây), khác Overpass là máy chủ truy vấn dữ liệu (hay quá tải, có khi > 50 giây).
     * Trả về theo dạng mục của PlaceFinder; null = không gọi được (tắt, đang ngắt, lỗi).
     *
     * @return ?list<array<string,mixed>>
     */
    public function nearby(string $cat, float $lat, float $lng, int $radiusM): ?array
    {
        if (!isset(self::NEARBY[$cat]) || $this->provider() !== 'photon' || !ExternalGuard::allowed('geocoder')) {
            return null;
        }
        [$word, $tags] = self::NEARBY[$cat];
        $tagQs = implode('', array_map(fn($t) => '&osm_tag=' . rawurlencode($t), $tags));
        $base = rtrim((string) config('planner.photon_url'), '/?');
        try {
            // 1) /reverse: các nơi GẦN NHẤT có đúng thẻ (khách sạn, cà phê...) quanh tâm, bán kính tính bằng km — nhanh (~1 giây)
            $rev = preg_replace('#/api$#', '/reverse', $base);
            $res = $this->http()->get($rev . '?lat=' . $lat . '&lon=' . $lng . '&radius=' . max(1, (int) ceil($radiusM / 1000))
                . '&limit=50&distance_sort=true' . $tagQs);
            $list = $res->ok() ? self::photonAsPlaces((array) $res->json(), $cat) : [];
            if ($list) {
                return $list;
            }
            // 2) máy chủ không hỗ trợ lọc khi tìm ngược: tìm theo từ khóa loại trong khung quanh tâm
            $dLat = $radiusM / 111000.0;
            $dLng = $radiusM / (111000.0 * max(0.2, cos(deg2rad($lat))));
            $res = $this->http()->get($base . '?q=' . rawurlencode($word) . '&limit=50&lat=' . $lat . '&lon=' . $lng
                . '&bbox=' . round($lng - $dLng, 5) . ',' . round($lat - $dLat, 5) . ',' . round($lng + $dLng, 5) . ',' . round($lat + $dLat, 5) . $tagQs);
            if (!$res->ok()) {
                throw new \RuntimeException('HTTP ' . $res->status());
            }

            return self::photonAsPlaces((array) $res->json(), $cat);
        } catch (Throwable $e) {
            ExternalGuard::trip('geocoder', mb_substr($e->getMessage(), 0, 120));

            return null;
        }
    }

    /** Hàm thuần: GeoJSON Photon -> mục dạng PlaceFinder (src = osm, không có giá). */
    public static function photonAsPlaces(array $json, string $cat): array
    {
        $out = [];
        foreach ($json['features'] ?? [] as $f) {
            $p = (array) ($f['properties'] ?? []);
            $c = $f['geometry']['coordinates'] ?? null;
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '' || !is_array($c) || !is_numeric($c[0] ?? null) || !is_numeric($c[1] ?? null)) {
                continue;
            }
            $kindKey = strtolower((string) ($p['osm_value'] ?? $cat));
            $street = trim(trim((string) ($p['housenumber'] ?? '')) . ' ' . trim((string) ($p['street'] ?? '')));
            $addr = implode(', ', array_filter([$street, $p['district'] ?? null, $p['city'] ?? null]));
            $id = ($p['osm_type'] ?? 'X') . '/' . ($p['osm_id'] ?? md5($name . $c[0] . $c[1]));
            $type = ['N' => 'node', 'W' => 'way', 'R' => 'relation'][$p['osm_type'] ?? ''] ?? 'x';
            $out[] = [
                'key' => 'osm:' . $type . '/' . ($p['osm_id'] ?? $id), 'src' => 'osm', 'type' => 'note', 'refId' => null,
                'name' => mb_substr($name, 0, 160), 'kindKey' => $kindKey, 'kind' => self::KIND[$kindKey] ?? 'Địa điểm',
                'address' => $addr !== '' ? mb_substr($addr, 0, 255) : null, 'lat' => round((float) $c[1], 6), 'lng' => round((float) $c[0], 6),
                'priceLevel' => null, 'priceSource' => null, 'priceVnd' => null, 'stars' => null, 'veg' => null, 'cuisine' => null,
                'indoor' => null, 'durationMin' => null, 'wifi' => null, 'hours' => null, 'website' => null, 'phone' => null,
                'rank' => 1, 'url' => null, 'cost' => null,
            ];
        }

        return $out;
    }

    private function fetchPhoton(string $q, ?float $lat, ?float $lng): array
    {
        $params = ['q' => $q, 'limit' => 10];   // không gửi lang: Photon công cộng không hỗ trợ "vi" và trả 400
        if ($bbox = config('planner.geocode_bbox')) {
            $params['bbox'] = $bbox;
        }
        if ($lat !== null && $lng !== null) {
            $params['lat'] = $lat;
            $params['lon'] = $lng;
        }
        $res = $this->http()->get((string) config('planner.photon_url'), $params);
        if (!$res->ok()) {
            throw new \RuntimeException('HTTP ' . $res->status());
        }

        return (array) $res->json();
    }

    private function fetchGeoapify(string $q, ?float $lat, ?float $lng): array
    {
        $params = ['text' => $q, 'limit' => 10, 'lang' => 'vi', 'format' => 'geojson', 'apiKey' => (string) config('planner.geoapify_key')];
        if (($bbox = config('planner.geocode_bbox')) && str_contains((string) $bbox, ',')) {
            [$a, $b, $c, $d] = array_map('trim', explode(',', (string) $bbox) + [0, 0, 0, 0]);
            $params['filter'] = "rect:$a,$b,$c,$d";
        }
        if ($lat !== null && $lng !== null) {
            $params['bias'] = "proximity:$lng,$lat";
        }
        $res = $this->http()->get('https://api.geoapify.com/v1/geocode/autocomplete', $params);
        if (!$res->ok()) {
            throw new \RuntimeException('HTTP ' . $res->status());
        }

        return (array) $res->json();
    }

    // ------------------------------------------------------------------ Chuẩn hóa kết quả (hàm thuần)

    /** GeoJSON của Photon: properties{name,street,housenumber,district,city,state,osm_value,...}, geometry.coordinates [lng,lat]. */
    public static function parsePhoton(array $json): array
    {
        $out = [];
        foreach ($json['features'] ?? [] as $f) {
            $p = (array) ($f['properties'] ?? []);
            $c = $f['geometry']['coordinates'] ?? null;
            if (!is_array($c) || count($c) < 2 || !is_numeric($c[0]) || !is_numeric($c[1])) {
                continue;
            }
            $name = trim((string) ($p['name'] ?? ''));
            $street = trim(trim((string) ($p['housenumber'] ?? '')) . ' ' . trim((string) ($p['street'] ?? '')));
            if ($name === '') {
                $name = $street;
            }
            if ($name === '') {
                continue;
            }
            $addr = array_filter([$street !== $name ? $street : '', $p['district'] ?? '', $p['city'] ?? ($p['county'] ?? ''), $p['state'] ?? '']);
            $out[] = self::row($name, implode(', ', array_unique(array_map('trim', $addr))), (string) ($p['osm_value'] ?? ''), (float) $c[1], (float) $c[0]);
        }

        return self::dedupe($out);
    }

    /** GeoJSON của Geoapify (autocomplete/search): properties{name,formatted,address_line1,address_line2,lat,lon,result_type,category}. */
    public static function parseGeoapify(array $json): array
    {
        $out = [];
        foreach ($json['features'] ?? [] as $f) {
            $p = (array) ($f['properties'] ?? []);
            $lat = $p['lat'] ?? ($f['geometry']['coordinates'][1] ?? null);
            $lng = $p['lon'] ?? ($f['geometry']['coordinates'][0] ?? null);
            if (!is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }
            $name = trim((string) ($p['name'] ?? ($p['address_line1'] ?? '')));
            if ($name === '') {
                continue;
            }
            $addr = trim((string) ($p['address_line2'] ?? ''));
            if ($addr === '' && !empty($p['formatted'])) {
                $addr = trim(preg_replace('/^' . preg_quote($name, '/') . ',?\s*/u', '', (string) $p['formatted']) ?? '');
            }
            $kind = (string) ($p['result_type'] ?? '');
            $cat = (string) ($p['category'] ?? '');
            $out[] = self::row($name, $addr, $cat !== '' && str_contains($cat, '.') ? substr(strrchr($cat, '.'), 1) : ($kind ?: $cat), (float) $lat, (float) $lng);
        }

        return self::dedupe($out);
    }

    private static function row(string $name, string $address, string $kindKey, float $lat, float $lng): array
    {
        $lat = round($lat, 6);
        $lng = round($lng, 6);

        return [
            'name' => mb_substr($name, 0, 120),
            'address' => mb_substr($address, 0, 255),
            'kind' => self::KIND[strtolower($kindKey)] ?? null,
            'lat' => $lat,
            'lng' => $lng,
            'key' => $lat . ',' . $lng,
        ];
    }

    /** Bỏ kết quả trùng tên và gần như cùng chỗ (~100 m). */
    private static function dedupe(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            if (abs($r['lat']) > 90 || abs($r['lng']) > 180) {
                continue;
            }
            $k = mb_strtolower($r['name']) . '|' . round($r['lat'], 3) . '|' . round($r['lng'], 3);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $r;
        }

        return $out;
    }
}
