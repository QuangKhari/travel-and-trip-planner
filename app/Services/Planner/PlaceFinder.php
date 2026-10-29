<?php

namespace App\Services\Planner;

use App\Support\PlaceKind;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tìm địa điểm THEO TIÊU CHÍ (không cần biết tên): loại + mức giá + khoảng cách + ăn chay + trong nhà/ngoài trời + hạng sao...
 *
 * Nguồn dữ liệu và độ tin cậy của từng tiêu chí (giao diện hiển thị rõ nguồn, không bịa dữ liệu):
 *  - Trải nghiệm, tour của Travela: giá thật (priceMin / priceAdult), trong nhà/ngoài trời, thời lượng.
 *  - Địa điểm đã đồng bộ (tbl_external_places): loại, vị trí, độ nổi bật. Không có giá.
 *  - OpenStreetMap (Overpass, có cache): loại, vị trí và các thẻ tình nguyện viên điền khi có: hạng sao, món (cuisine), ăn chay, wifi, giờ mở cửa.
 *    OSM KHÔNG có giá. Mức giá của khách sạn suy từ hạng sao, của quán/nhà trọ từ loại hình, và được đánh dấu "ước tính".
 *  Mục thiếu thông tin cho một tiêu chí bị ẩn khi tiêu chí đó đang bật, trừ khi người dùng chọn "Hiện cả mục chưa rõ".
 *
 * Phần lọc, sắp xếp, đọc dữ liệu OSM là hàm thuần (static) để kiểm thử bằng dữ liệu mẫu, không cần mạng hay cơ sở dữ liệu.
 */
class PlaceFinder
{
    /** Truy vấn OSM theo nhóm: danh sách bộ chọn Overpass (đã có [name]). */
    private const OSM = [
        'hotel' => ['[tourism~"^(hotel|guest_house|hostel|motel|apartment|chalet)$"]', '[leisure=resort]'],
        'restaurant' => ['[amenity~"^(restaurant|fast_food|food_court)$"]'],
        'cafe' => ['[amenity=cafe]'],
        'bar' => ['[amenity~"^(bar|pub|nightclub)$"]'],
        'market' => ['[amenity=marketplace]', '[shop~"^(mall|supermarket)$"]'],
        'sight' => ['[tourism~"^(attraction|viewpoint|museum|gallery|theme_park|zoo|aquarium)$"]', '[historic~"^(monument|memorial|castle|ruins|archaeological_site|temple|fort)$"]',
            '[natural~"^(beach|waterfall|peak|cave_entrance|hot_spring)$"]', '[amenity=place_of_worship]', '[leisure=park]'],
    ];

    /** Nhóm => các loại (kind) có trong tbl_external_places. */
    private const DB_KINDS = [
        'restaurant' => ['restaurant'], 'cafe' => ['cafe'],
        'sight' => ['attraction', 'peak', 'hot_spring', 'cave_entrance', 'historic', 'viewpoint', 'place_of_worship', 'museum', 'beach', 'zoo', 'theme_park', 'gallery', 'aquarium'],
    ];

    private const KIND_EXTRA = [
        'hotel' => 'Khách sạn', 'guest_house' => 'Nhà nghỉ', 'hostel' => 'Hostel', 'motel' => 'Nhà nghỉ', 'apartment' => 'Căn hộ', 'chalet' => 'Nhà gỗ', 'resort' => 'Resort',
        'fast_food' => 'Quán ăn nhanh', 'food_court' => 'Khu ẩm thực', 'bar' => 'Quán bar', 'pub' => 'Quán bar', 'nightclub' => 'Hộp đêm',
        'marketplace' => 'Chợ', 'mall' => 'Trung tâm thương mại', 'supermarket' => 'Siêu thị', 'waterfall' => 'Thác nước', 'park' => 'Công viên', 'temple' => 'Chùa, đền',
        'monument' => 'Tượng đài', 'memorial' => 'Đài tưởng niệm', 'castle' => 'Thành', 'ruins' => 'Di tích', 'fort' => 'Pháo đài', 'archaeological_site' => 'Di chỉ',
    ];

    // ------------------------------------------------------------------ Tiêu chí

    /**
     * Chuẩn hóa tiêu chí từ tham số web (đã kiểm tra) hoặc từ PlaceQuery::parse().
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function criteria(array $in): array
    {
        $max = (int) config('planner.browse.max_radius_m', 20000);
        $def = (int) config('planner.browse.default_radius_m', 3000);
        $km = isset($in['radiusKm']) && is_numeric($in['radiusKm']) ? (float) $in['radiusKm'] : null;
        $levels = $in['levels'] ?? null;
        if (is_array($levels) && count($levels) === 2) {
            $lo = max(1, min(4, (int) $levels[0]));
            $hi = max($lo, min(4, (int) $levels[1]));
            $levels = [$lo, $hi];
        } else {
            $levels = null;
        }
        $kinds = isset($in['kinds']) && is_array($in['kinds']) ? array_values(array_filter(array_map('strval', $in['kinds']), fn($k) => preg_match('/^[a-z_]{2,30}$/', $k))) : null;
        $num = fn($k) => isset($in[$k]) && is_numeric($in[$k]) && (float) $in[$k] >= 0 ? (int) round((float) $in[$k]) : null;

        return [
            'cat' => in_array($in['cat'] ?? null, ['hotel', 'restaurant', 'cafe', 'bar', 'market', 'sight', 'experience', 'tour'], true) ? $in['cat'] : null,
            'kinds' => $kinds ?: null,
            'levels' => $levels,
            'minPrice' => $num('minPrice'),
            'maxPrice' => $num('maxPrice'),
            'free' => !empty($in['free']),
            'veg' => !empty($in['veg']),
            'cuisine' => isset($in['cuisine']) && preg_match('/^[a-z_]{2,30}$/', (string) $in['cuisine']) ? (string) $in['cuisine'] : null,
            'indoor' => array_key_exists('indoor', $in) && $in['indoor'] !== null && $in['indoor'] !== '' ? (bool) $in['indoor'] : null,
            'stars' => isset($in['stars']) && is_numeric($in['stars']) && $in['stars'] >= 1 && $in['stars'] <= 5 ? (int) $in['stars'] : null,
            'radiusM' => (int) max(300, min($max, $km !== null ? $km * 1000 : (isset($in['radiusM']) && is_numeric($in['radiusM']) ? (int) $in['radiusM'] : $def))),
            'sort' => in_array($in['sort'] ?? null, ['near', 'rank', 'price'], true) ? $in['sort'] : null,
            'includeUnknown' => !empty($in['includeUnknown']),
            'lat' => isset($in['lat']) && is_numeric($in['lat']) ? (float) $in['lat'] : null,
            'lng' => isset($in['lng']) && is_numeric($in['lng']) ? (float) $in['lng'] : null,
            'destId' => isset($in['destId']) && (int) $in['destId'] > 0 ? (int) $in['destId'] : null,
        ];
    }

    public static function hasPriceFilter(array $c): bool
    {
        return $c['levels'] !== null || $c['minPrice'] !== null || $c['maxPrice'] !== null || $c['free'];
    }

    // ------------------------------------------------------------------ Hàm thuần: giá, khoảng cách, lọc

    /** Giá thật (đồng) -> mức 1..4 theo ngưỡng của nhóm. */
    public static function levelFromPrice(string $cat, float $vnd, ?array $bands = null): int
    {
        $b = $bands ?? (config('planner.browse.level_bands')[$cat] ?? [100000, 300000, 700000]);

        return $vnd <= $b[0] ? 1 : ($vnd <= $b[1] ? 2 : ($vnd <= $b[2] ? 3 : 4));
    }

    /** Khoảng cách đường chim bay (mét). */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }

    /**
     * Lọc + sắp xếp.
     *
     * @param list<array<string,mixed>> $list mỗi mục có distM (đã tính từ tâm), priceLevel, priceVnd, stars, veg, cuisine, indoor, kindKey, rank
     * @return array{list:list<array<string,mixed>>,hidden:int,facets:array<string,mixed>}  hidden = số mục bị ẩn vì thiếu thông tin cho tiêu chí đang bật
     */
    public static function apply(array $list, array $c): array
    {
        $base = [];
        foreach ($list as $it) {
            if ($it['distM'] === null || $it['distM'] > $c['radiusM']) {
                continue;
            }
            if ($c['kinds'] && !in_array($it['kindKey'] ?? '', $c['kinds'], true)) {
                continue;
            }
            $base[] = $it;
        }
        $facets = ['kinds' => self::kindFacets($base)];

        $hidden = 0;
        $out = [];
        $price = self::hasPriceFilter($c);
        foreach ($base as $it) {
            $unknown = false;
            $fail = false;
            if ($price) {
                $r = self::priceMatch($it, $c);
                if ($r === null) { $unknown = true; } elseif ($r === false) { $fail = true; }
            }
            if (!$fail && $c['stars'] !== null) {
                if (($it['stars'] ?? null) === null) { $unknown = true; } elseif ((int) $it['stars'] !== $c['stars']) { $fail = true; }
            }
            if (!$fail && $c['veg']) {
                if (($it['veg'] ?? null) === null) { $unknown = true; } elseif (!$it['veg']) { $fail = true; }
            }
            if (!$fail && $c['cuisine'] !== null) {
                $cu = $it['cuisine'] ?? null;
                if (!$cu) { $unknown = true; } elseif (!in_array($c['cuisine'], $cu, true)) { $fail = true; }
            }
            if (!$fail && $c['indoor'] !== null) {
                if (($it['indoor'] ?? null) === null) { $unknown = true; } elseif ((bool) $it['indoor'] !== $c['indoor']) { $fail = true; }
            }
            if ($fail) {
                continue;
            }
            if ($unknown && !$c['includeUnknown']) {
                $hidden++;
                continue;
            }
            $it['unknownSome'] = $unknown;
            $out[] = $it;
        }

        $sort = $c['sort'] ?? (self::hasPriceFilter($c) && $c['levels'] !== null && $c['levels'][1] <= 2 ? 'price' : 'rank');
        usort($out, function ($a, $b) use ($sort) {
            if ($sort === 'near') {
                return [$a['distM'], -($a['rank'] ?? 0)] <=> [$b['distM'], -($b['rank'] ?? 0)];
            }
            if ($sort === 'price') {
                $pa = self::priceKey($a);
                $pb = self::priceKey($b);

                return [$pa, $a['distM']] <=> [$pb, $b['distM']];
            }

            return [-($a['rank'] ?? 0), $a['distM']] <=> [-($b['rank'] ?? 0), $b['distM']];
        });

        return ['list' => $out, 'hidden' => $hidden, 'facets' => $facets, 'sort' => $sort];
    }

    /** true khớp, false không khớp, null thiếu thông tin giá. */
    private static function priceMatch(array $it, array $c): ?bool
    {
        $vnd = $it['priceVnd'] ?? null;
        $lv = $it['priceLevel'] ?? null;
        if ($c['free']) {
            return $vnd === null ? null : $vnd <= 0;
        }
        if ($c['minPrice'] !== null || $c['maxPrice'] !== null) {
            if ($vnd === null) {
                return null;
            }
            if ($c['minPrice'] !== null && $vnd < $c['minPrice']) { return false; }
            if ($c['maxPrice'] !== null && $vnd > $c['maxPrice']) { return false; }
        }
        if ($c['levels'] !== null) {
            if ($lv === null) {
                return null;
            }
            if ($lv < $c['levels'][0] || $lv > $c['levels'][1]) { return false; }
        }

        return true;
    }

    private static function priceKey(array $it): float
    {
        if (($it['priceVnd'] ?? null) !== null) {
            return (float) $it['priceVnd'];
        }

        return ($it['priceLevel'] ?? null) !== null ? $it['priceLevel'] * 1.0e6 : 1.0e12;   // chưa rõ giá xếp cuối
    }

    /** @return list<array{key:string,label:string,count:int}> */
    private static function kindFacets(array $list): array
    {
        $n = [];
        foreach ($list as $it) {
            $k = $it['kindKey'] ?? '';
            if ($k !== '') {
                $n[$k] = ($n[$k] ?? ['key' => $k, 'label' => $it['kind'] ?? $k, 'count' => 0]);
                $n[$k]['count']++;
            }
        }
        usort($n, fn($a, $b) => $b['count'] <=> $a['count']);

        return array_slice(array_values($n), 0, 8);
    }

    // ------------------------------------------------------------------ OpenStreetMap (Overpass)

    public static function overpassQuery(string $cat, float $lat, float $lng, int $radiusM, int $limit = 250): ?string
    {
        if (!isset(self::OSM[$cat])) {
            return null;
        }
        $a = '(around:' . $radiusM . ',' . round($lat, 6) . ',' . round($lng, 6) . ')';
        $parts = '';
        foreach (self::OSM[$cat] as $sel) {
            $parts .= 'nwr' . $a . $sel . '[name];';
        }

        return '[out:json][timeout:25];(' . $parts . ');out center tags ' . $limit . ';';
    }

    /**
     * Đọc kết quả Overpass thành danh sách mục (chưa tính khoảng cách).
     *
     * @return list<array<string,mixed>>
     */
    public static function parseOverpass(array $json, string $cat): array
    {
        $out = [];
        $bands = config('planner.browse.level_bands')[$cat] ?? null;
        foreach ($json['elements'] ?? [] as $el) {
            $t = $el['tags'] ?? [];
            $name = trim((string) ($t['name:vi'] ?? $t['name'] ?? ''));
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($name === '' || $lat === null || $lng === null || !isset($el['type'], $el['id'])) {
                continue;
            }
            $kindKey = self::osmKind($t);
            $stars = null;
            if (isset($t['stars']) && preg_match('/^([1-5])/', (string) $t['stars'], $m)) {
                $stars = (int) $m[1];
            }
            [$level, $psrc] = self::osmLevel($cat, $kindKey, $stars);
            $cuisine = isset($t['cuisine']) ? array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), explode(';', (string) $t['cuisine'])))) : null;
            $diet = strtolower((string) ($t['diet:vegetarian'] ?? $t['diet:vegan'] ?? ''));
            $veg = in_array($diet, ['yes', 'only'], true) || ($cuisine && (in_array('vegetarian', $cuisine, true) || in_array('vegan', $cuisine, true))) ? true : null;
            $addr = trim(implode(', ', array_filter([
                trim(($t['addr:housenumber'] ?? '') . ' ' . ($t['addr:street'] ?? '')),
                $t['addr:suburb'] ?? $t['addr:district'] ?? null,
                $t['addr:city'] ?? null,
            ])));
            $rank = (isset($t['wikidata']) ? 3 : 0) + (isset($t['wikipedia']) ? 2 : 0) + (isset($t['website']) || isset($t['contact:website']) ? 1 : 0)
                + (isset($t['name:en']) ? 1 : 0) + (isset($t['heritage']) ? 2 : 0) + ($stars ? 1 : 0) + (isset($t['opening_hours']) ? 1 : 0);
            $out[] = [
                'key' => 'osm:' . $el['type'] . '/' . $el['id'], 'src' => 'osm', 'type' => 'note', 'refId' => null,
                'name' => mb_substr($name, 0, 160), 'kindKey' => $kindKey, 'kind' => self::kindLabel($kindKey, $t),
                'address' => $addr !== '' ? mb_substr($addr, 0, 255) : null, 'lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6),
                'priceLevel' => $level, 'priceSource' => $psrc, 'priceVnd' => null, 'stars' => $stars,
                'veg' => $veg, 'cuisine' => $cuisine ?: null, 'indoor' => self::indoorOf($kindKey), 'durationMin' => null,
                'wifi' => in_array(strtolower((string) ($t['internet_access'] ?? '')), ['wlan', 'wifi', 'yes'], true) ? true : null,
                'hours' => isset($t['opening_hours']) ? mb_substr((string) $t['opening_hours'], 0, 120) : null,
                'website' => isset($t['website']) ? mb_substr($t['website'], 0, 255) : (isset($t['contact:website']) ? mb_substr($t['contact:website'], 0, 255) : null),
                'phone' => isset($t['phone']) ? mb_substr((string) $t['phone'], 0, 40) : null,
                'rank' => $rank, 'url' => null, 'cost' => null,
            ];
        }

        return $out;
    }

    private static function osmKind(array $t): string
    {
        foreach (['tourism', 'amenity', 'leisure', 'shop', 'natural', 'historic'] as $k) {
            if (isset($t[$k])) {
                $v = (string) $t[$k];
                if ($k === 'historic') { return 'historic'; }
                if ($v === 'place_of_worship' && strtolower((string) ($t['religion'] ?? '')) === 'buddhist') { return 'temple'; }

                return $v;
            }
        }

        return 'attraction';
    }

    /** @return array{0:?int,1:?string} [mức giá, nguồn: stars|kind|null] */
    private static function osmLevel(string $cat, string $kind, ?int $stars): array
    {
        if ($cat === 'hotel') {
            if ($stars !== null) {
                return [$stars <= 2 ? 1 : ($stars === 3 ? 2 : ($stars === 4 ? 3 : 4)), 'stars'];
            }
            if (in_array($kind, ['hostel', 'guest_house', 'motel'], true)) {
                return [1, 'kind'];
            }

            return [null, null];
        }
        if ($cat === 'restaurant' && in_array($kind, ['fast_food', 'food_court'], true)) {
            return [1, 'kind'];
        }
        if ($cat === 'market' && $kind === 'marketplace') {
            return [1, 'kind'];
        }

        return [null, null];
    }

    private static function kindLabel(string $kind, array $tags): string
    {
        return self::KIND_EXTRA[$kind] ?? PlaceKind::label($kind) ?? 'Địa điểm';
    }

    private static function indoorOf(string $kind): ?bool
    {
        if (in_array($kind, (array) config('planner.browse.indoor_kinds', []), true)) { return true; }
        if (in_array($kind, (array) config('planner.browse.outdoor_kinds', []), true)) { return false; }

        return null;
    }

    /** Bỏ mục trùng (cùng tên đã bỏ dấu, cách nhau dưới 120 m), giữ mục đầu tiên. */
    public static function dedupe(array $list): array
    {
        $seen = [];
        $out = [];
        foreach ($list as $it) {
            $k = PlaceQuery::fold($it['name']);
            $dup = false;
            foreach ($seen[$k] ?? [] as [$la, $ln]) {
                if (self::distanceM($la, $ln, $it['lat'], $it['lng']) < 120) { $dup = true; break; }
            }
            if (!$dup) {
                $seen[$k][] = [$it['lat'], $it['lng']];
                $out[] = $it;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ Truy vấn (cơ sở dữ liệu, mạng)

    /**
     * @param array<string,mixed> $c kết quả criteria()
     * @return array<string,mixed> {ok, center, results, hidden, facets, sort, total, sources:{travela,db,osm}, osmOk, note}
     */
    public function browse(object $trip, array $c): array
    {
        $cat = $c['cat'];
        $group = max(1, (int) $trip->groupSize);
        [$lat, $lng] = $this->center($trip, $c);
        $items = [];
        $src = ['travela' => 0, 'db' => 0, 'osm' => 0];
        $osmOk = true;
        $destId = $c['destId'] ?? (!empty($trip->destinationId) ? (int) $trip->destinationId : null);

        if ($cat === null || $cat === 'experience') {
            $r = $this->experiences($destId, $lat, $lng, $c);
            $items = array_merge($items, $r);
            $src['travela'] += count($r);
        }
        if ($cat === null || $cat === 'tour') {
            $r = $this->tours($destId, $group, $trip, $c);
            $items = array_merge($items, $r);
            $src['travela'] += count($r);
        }
        if ($cat !== null && isset(self::DB_KINDS[$cat]) && $lat !== null) {
            $r = $this->dbPlaces($cat, $lat, $lng, $c);
            $items = array_merge($items, $r);
            $src['db'] += count($r);
        }
        if ($cat !== null && isset(self::OSM[$cat]) && $lat !== null) {
            [$ok, $r] = $this->overpass($cat, $lat, $lng, $c['radiusM']);
            $osmOk = $ok;
            $items = array_merge($items, $r);
            $src['osm'] += count($r);
        }

        $items = self::dedupe($items);
        foreach ($items as &$it) {
            $it['distM'] = $lat !== null && $it['lat'] !== null ? self::distanceM($lat, $lng, (float) $it['lat'], (float) $it['lng']) : null;
        }
        unset($it);
        // mục chỉ biết ở mức điểm đến (tour, trải nghiệm chưa có tọa độ riêng) hoặc không có vị trí: không loại theo khoảng cách, không hiện số mét
        foreach ($items as &$it) {
            if (!empty($it['destLevel']) || $it['distM'] === null) {
                $it['distM'] = 0;
                $it['destLevel'] = true;
            }
        }
        unset($it);

        $res = self::apply($items, $c);
        $limit = (int) config('planner.browse.limit', 40);

        return [
            'ok' => true, 'center' => $lat !== null ? ['lat' => $lat, 'lng' => $lng] : null,
            'results' => array_slice($res['list'], 0, $limit), 'total' => count($res['list']), 'hidden' => $res['hidden'],
            'facets' => $res['facets'], 'sort' => $res['sort'], 'sources' => $src, 'osmOk' => $osmOk,
            'note' => $osmOk ? null : 'Chưa tải được dữ liệu OpenStreetMap lúc này, đang hiện các mục có sẵn trên Travela.',
        ];
    }

    /** @return array{0:?float,1:?float} */
    private function center(object $trip, array $c): array
    {
        if ($c['lat'] !== null && $c['lng'] !== null) {
            return [$c['lat'], $c['lng']];
        }
        $id = $c['destId'] ?? (!empty($trip->destinationId) ? (int) $trip->destinationId : null);
        if ($id) {
            $d = DB::table('tbl_destinations')->where('destinationId', $id)->first(['latitude', 'longitude']);
            if ($d && $d->latitude !== null && $d->longitude !== null) {
                return [(float) $d->latitude, (float) $d->longitude];
            }
        }

        return [null, null];
    }

    /** @return list<array<string,mixed>> */
    private function experiences(?int $destId, ?float $lat, ?float $lng, array $c): array
    {
        $q = DB::table('tbl_experiences as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')
            ->when($destId, fn($x) => $x->where('e.destinationId', $destId))
            ->orderByDesc('e.isMustTry')->orderBy('e.name')->limit(120)
            ->select('e.*', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng');
        $out = [];
        foreach ($q->get() as $x) {
            $min = isset($x->priceMin) && $x->priceMin !== null ? (float) $x->priceMin : null;
            $level = isset($x->priceLevel) && $x->priceLevel !== null ? (int) $x->priceLevel : ($min !== null ? self::levelFromPrice('experience', $min) : null);
            $la = $x->latitude ?? $x->dLat;
            $ln = $x->longitude ?? $x->dLng;
            $out[] = [
                'key' => 'experience:' . $x->experienceId, 'src' => 'travela', 'type' => 'experience', 'refId' => (int) $x->experienceId,
                'destLevel' => ($x->latitude ?? null) === null, 'name' => $x->name, 'kindKey' => 'experience', 'kind' => 'Trải nghiệm', 'address' => $x->destName, 'lat' => $la !== null ? (float) $la : null, 'lng' => $ln !== null ? (float) $ln : null,
                'priceLevel' => $level, 'priceSource' => $level !== null ? 'travela' : null, 'priceVnd' => $min,
                'priceText' => $min !== null ? ($min <= 0 ? 'Miễn phí' : 'Từ ' . number_format($min, 0, ',', '.') . 'đ') : ($x->priceNote ?? null),
                'stars' => null, 'veg' => null, 'cuisine' => null, 'indoor' => isset($x->indoor) && $x->indoor !== null ? (bool) $x->indoor : null,
                'durationMin' => isset($x->durationMinutes) && $x->durationMinutes !== null ? (int) $x->durationMinutes : null, 'duration' => $x->duration,
                'hours' => null, 'website' => null, 'phone' => null, 'rank' => ($x->isMustTry ? 10 : 5), 'url' => route('experiences.show', ['slug' => $x->slug]), 'cost' => null,
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function tours(?int $destId, int $group, object $trip, array $c): array
    {
        $q = DB::table('tbl_tours as t')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('t.availability', 1)->where('t.quantity', '>', 0)->whereDate('t.startDate', '>', now()->toDateString())
            ->when($destId, fn($x) => $x->where('t.destinationId', $destId));
        !empty($trip->startDate) ? $q->orderByRaw('ABS(DATEDIFF(t.startDate, ?))', [$trip->startDate]) : $q->orderBy('t.startDate');
        $out = [];
        foreach ($q->limit(60)->get(['t.tourId', 't.title', 't.startDate', 't.priceAdult', 't.destinationId', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng']) as $t) {
            $p = (float) $t->priceAdult;
            $out[] = [
                'key' => 'tour:' . $t->tourId, 'src' => 'travela', 'type' => 'tour', 'destLevel' => true, 'refId' => (int) $t->tourId, 'name' => $t->title, 'kindKey' => 'tour', 'kind' => 'Tour',
                'address' => $t->destName, 'lat' => $t->dLat !== null ? (float) $t->dLat : null, 'lng' => $t->dLng !== null ? (float) $t->dLng : null,
                'priceLevel' => self::levelFromPrice('tour', $p), 'priceSource' => 'travela', 'priceVnd' => $p,
                'priceText' => number_format($p, 0, ',', '.') . 'đ/người · khởi hành ' . date('d/m/Y', strtotime($t->startDate)),
                'stars' => null, 'veg' => null, 'cuisine' => null, 'indoor' => null, 'durationMin' => null, 'hours' => null, 'website' => null, 'phone' => null,
                'rank' => 6, 'url' => route('tour-detail', ['id' => $t->tourId]), 'cost' => round($p * $group, 2),
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> địa điểm đã đồng bộ của Travela */
    private function dbPlaces(string $cat, float $lat, float $lng, array $c): array
    {
        $dLat = $c['radiusM'] / 111000.0;
        $dLng = $c['radiusM'] / (111000.0 * max(0.2, cos(deg2rad($lat))));
        $kinds = self::DB_KINDS[$cat];
        $rows = DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
            ->where('d.isActive', 1)->where('p.reviewStatus', 'published')->whereIn('p.kind', $kinds)
            ->whereBetween('p.latitude', [$lat - $dLat, $lat + $dLat])->whereBetween('p.longitude', [$lng - $dLng, $lng + $dLng])
            ->orderByDesc('p.rank')->limit(200)
            ->get(['p.placeId', 'p.name', 'p.kind', 'p.address', 'p.latitude', 'p.longitude', 'p.rank', 'p.website']);
        $out = [];
        foreach ($rows as $p) {
            $out[] = [
                'key' => 'place:' . $p->placeId, 'src' => 'db', 'type' => 'place', 'refId' => (int) $p->placeId, 'name' => $p->name, 'kindKey' => (string) $p->kind,
                'kind' => PlaceKind::label($p->kind) ?? 'Địa điểm', 'address' => $p->address, 'lat' => (float) $p->latitude, 'lng' => (float) $p->longitude,
                'priceLevel' => null, 'priceSource' => null, 'priceVnd' => null, 'stars' => null, 'veg' => null, 'cuisine' => null, 'indoor' => self::indoorOf((string) $p->kind),
                'durationMin' => null, 'hours' => null, 'website' => $p->website, 'phone' => null, 'rank' => 4 + min(6, (int) $p->rank), 'url' => null, 'cost' => null,
            ];
        }

        return $out;
    }

    /** @return array{0:bool,1:list<array<string,mixed>>} [gọi được, kết quả] */
    private function overpass(string $cat, float $lat, float $lng, int $radiusM): array
    {
        // làm tròn để nhiều người cùng tìm một khu vực dùng chung một bản cache
        $rLat = round($lat, 3);
        $rLng = round($lng, 3);
        $radius = (int) (ceil($radiusM / 500) * 500);
        $key = 'browse:osm:' . $cat . ':' . $rLat . ',' . $rLng . ':' . $radius;
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return [true, $hit];
        }
        $ql = self::overpassQuery($cat, $rLat, $rLng, $radius, (int) config('planner.browse.osm_limit', 250));
        if ($ql === null) {
            return [true, []];
        }
        foreach ((array) config('planner.browse.overpass_urls', []) as $url) {
            try {
                $res = Http::withHeaders(['User-Agent' => (string) config('discover.user_agent', 'TravelaGraduationProject/1.0')])
                    ->timeout((int) config('planner.browse.timeout', 14))->asForm()->post($url, ['data' => $ql]);
                if ($res->ok() && is_array($res->json())) {
                    $list = self::parseOverpass($res->json(), $cat);
                    Cache::put($key, $list, now()->addMinutes((int) config('planner.browse.cache_minutes', 720)));

                    return [true, $list];
                }
                Log::info('PlaceFinder overpass ' . parse_url($url, PHP_URL_HOST) . ' HTTP ' . $res->status());
            } catch (Throwable $e) {
                Log::info('PlaceFinder overpass ' . parse_url($url, PHP_URL_HOST) . ': ' . $e->getMessage());
            }
        }

        return [false, []];
    }
}
