<?php

namespace App\Services\Planner;

use App\Services\Content\SearchCatalog;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * Người dùng nhập một nơi mà Travela chưa có dữ liệu. Thay vì báo lỗi, đi từng bậc:
 *   1. Có thể gõ sai/thiếu dấu? -> "Ý bạn là ...?" (so khớp mờ trên tên điểm đến đã bỏ dấu).
 *   2. Tìm tọa độ nơi đó (OpenStreetMap/Geoapify) rồi chỉ ra điểm đến Travela GẦN NHẤT có dữ liệu.
 *   3. Vẫn cho lập chuyến thủ công quanh nơi đó (mục tự thêm có tọa độ), nói rõ phần nào là dữ liệu ngoài Travela.
 *   4. Ghi nhu cầu vào sổ ContentRequests để biên tập viên biết nơi này được hỏi.
 * fuzzy() và nearest() là hàm thuần để kiểm thử.
 */
final class UnknownPlace
{
    public function __construct(private ?PlaceGeocoder $geo = null, private ?ContentRequests $log = null)
    {
        $this->geo ??= new PlaceGeocoder();
        $this->log ??= new ContentRequests();
    }

    /**
     * Điểm đến có tên GẦN GIỐNG $folded (đã bỏ dấu). Khớp mờ theo khoảng cách chỉnh sửa, ngưỡng tăng theo độ dài.
     *
     * @param list<array{slug:string,name:string,folded:string}> $dests
     * @return list<array{slug:string,name:string}>
     */
    public static function fuzzy(string $folded, array $dests, int $limit = 3): array
    {
        $folded = trim($folded);
        if (mb_strlen($folded) < 3) {
            return [];
        }
        $scored = [];
        foreach ($dests as $d) {
            $n = $d['folded'];
            if ($n === $folded) {
                continue;   // khớp chính xác không phải trường hợp "gõ gần giống"
            }
            $max = strlen($n) >= 8 ? 2 : (strlen($n) >= 5 ? 1 : 0);
            $dist = levenshtein($folded, $n);
            if ($max > 0 && $dist <= $max) {
                $scored[] = [$dist, $d];
            } elseif (strlen($folded) >= 4 && (str_starts_with($n, $folded) || str_starts_with($folded, $n)) && strlen($n) >= 4) {
                $scored[] = [3, $d];
            }
        }
        usort($scored, fn($a, $b) => [$a[0], $a[1]['name']] <=> [$b[0], $b[1]['name']]);

        return array_map(fn($s) => ['slug' => $s[1]['slug'], 'name' => $s[1]['name']], array_slice($scored, 0, $limit));
    }

    /**
     * Điểm đến gần tọa độ cho trước nhất, trong bán kính $maxKm.
     *
     * @param list<array{id:int,slug:string,name:string,lat:?float,lng:?float}> $dests
     * @return list<array{id:int,slug:string,name:string,km:int}>
     */
    public static function nearest(float $lat, float $lng, array $dests, int $n = 3, float $maxKm = 150.0): array
    {
        $out = [];
        foreach ($dests as $d) {
            if ($d['lat'] === null || $d['lng'] === null) {
                continue;
            }
            $km = Geo::distanceKm($lat, $lng, (float) $d['lat'], (float) $d['lng']);
            if ($km <= $maxKm) {
                $out[] = ['id' => (int) $d['id'], 'slug' => $d['slug'], 'name' => $d['name'], 'km' => (int) round($km)];
            }
        }
        usort($out, fn($a, $b) => [$a['km'], $a['name']] <=> [$b['km'], $b['name']]);

        return array_slice($out, 0, $n);
    }

    /**
     * @return array{term:string,fuzzy:list<array{slug:string,name:string}>,place:?array{name:string,address:string,lat:float,lng:float},nearest:list<array{id:int,slug:string,name:string,km:int}>,external:string}
     *         external = 'ok' | 'off' | 'error' | 'none' (dịch vụ ngoài tắt / lỗi / không thấy nơi nào)
     */
    public function resolve(string $term, bool $useExternal = true): array
    {
        $term = trim(preg_replace('/\s+/u', ' ', $term) ?? $term);
        $folded = SearchCatalog::fold($term);
        $rows = DB::table('tbl_destinations')->where('isActive', 1)->get(['destinationId', 'slug', 'name', 'latitude', 'longitude']);
        $fuzzy = self::fuzzy($folded, $rows->map(fn($r) => ['slug' => $r->slug, 'name' => $r->name, 'folded' => SearchCatalog::fold($r->name)])->all());

        $place = null;
        $near = [];
        $external = 'off';
        if ($useExternal && $this->geo->enabled()) {
            $r = $this->geo->search($term);
            if (!$r['ok']) {
                $external = 'error';
            } elseif (!$r['results']) {
                $external = 'none';
            } else {
                $external = 'ok';
                $p = $r['results'][0];
                $place = ['name' => $p['name'], 'address' => $p['address'], 'lat' => $p['lat'], 'lng' => $p['lng']];
                $near = self::nearest($p['lat'], $p['lng'],
                    $rows->map(fn($d) => ['id' => $d->destinationId, 'slug' => $d->slug, 'name' => $d->name,
                        'lat' => $d->latitude !== null ? (float) $d->latitude : null, 'lng' => $d->longitude !== null ? (float) $d->longitude : null])->all(),
                    (int) config('planner.unknown.nearest_n', 3), (float) config('planner.unknown.nearest_km', 150));
            }
        }
        $this->log->record($term, 'destination', $near[0]['id'] ?? null);

        return ['term' => $term, 'fuzzy' => $fuzzy, 'place' => $place, 'nearest' => $near, 'external' => $external];
    }
}
