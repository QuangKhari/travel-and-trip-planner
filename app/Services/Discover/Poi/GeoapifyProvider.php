<?php

namespace App\Services\Discover\Poi;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Geoapify Places (cần GEOAPIFY_KEY). Trước khi dùng cho dữ liệu LƯU lâu dài hãy đọc điều khoản
 * gói bạn đang dùng về việc lưu trữ kết quả (gói miễn phí thường chỉ cho hiển thị, không cho lưu).
 * Mặc định dự án dùng Overpass/OSM; chỉ bật Geoapify khi bạn đã kiểm tra điều khoản.
 */
class GeoapifyProvider implements PoiProvider
{
    private const GROUPS = [
        'see' => 'tourism.attraction,tourism.sights,natural.beach,leisure.park',
        'culture' => 'entertainment.museum,heritage,religion.place_of_worship',
        'eat' => 'catering.restaurant,catering.cafe',
    ];

    public function name(): string
    {
        return 'geoapify';
    }

    public function fetch(float $lat, float $lng, int $radiusM): array
    {
        $key = config('discover.places.geoapify_key');
        if (!$key) {
            throw new RuntimeException('Thiếu GEOAPIFY_KEY trong .env');
        }

        $out = [];
        foreach (self::GROUPS as $category => $cats) {
            $res = Http::timeout(config('discover.http_timeout'))->retry(2, 1000, throw: false)
                ->get('https://api.geoapify.com/v2/places', [
                    'categories' => $cats,
                    'filter' => "circle:{$lng},{$lat},{$radiusM}",
                    'bias' => "proximity:{$lng},{$lat}",
                    'limit' => 30,
                    'lang' => 'vi',
                    'apiKey' => $key,
                ]);
            if (!$res->ok()) {
                throw new RuntimeException('Geoapify HTTP ' . $res->status());
            }
            array_push($out, ...self::parse($res->json() ?? [], $category));
        }

        return $out;
    }

    /** @internal */
    public static function parse(array $json, string $category): array
    {
        $out = [];
        foreach ($json['features'] ?? [] as $f) {
            $p = $f['properties'] ?? [];
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '' || !isset($p['place_id'], $p['lat'], $p['lon'])) {
                continue;
            }
            $out[] = [
                'externalId' => (string) $p['place_id'],
                'name' => mb_substr($name, 0, 160),
                'category' => $category,
                'kind' => isset($p['categories'][0]) ? mb_substr((string) $p['categories'][0], 0, 60) : null,
                'address' => isset($p['formatted']) ? mb_substr((string) $p['formatted'], 0, 255) : null,
                'lat' => round((float) $p['lat'], 6),
                'lng' => round((float) $p['lon'], 6),
                'rank' => isset($p['datasource']['raw']['wikidata']) ? 3 : 0,
                'website' => isset($p['website']) ? mb_substr((string) $p['website'], 0, 255) : null,
            ];
        }

        return $out;
    }
}
