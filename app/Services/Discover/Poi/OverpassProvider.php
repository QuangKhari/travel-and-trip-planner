<?php

namespace App\Services\Discover\Poi;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenStreetMap qua Overpass API (không cần key). Dữ liệu OSM theo giấy phép ODbL:
 * được lưu/hiển thị nhưng phải ghi "© OpenStreetMap contributors".
 * Không dùng khi mở trang - chỉ gọi từ discover:sync-places, nghỉ giữa các điểm đến.
 */
class OverpassProvider implements PoiProvider
{
    public function name(): string
    {
        return 'overpass';
    }

    public function fetch(float $lat, float $lng, int $radiusM): array
    {
        $a = "(around:{$radiusM},{$lat},{$lng})";
        $ql = "[out:json][timeout:40];("
            . "nwr{$a}[tourism~\"^(attraction|viewpoint|museum|gallery|theme_park|zoo|aquarium)$\"][name];"
            . "nwr{$a}[historic~\"^(monument|memorial|castle|ruins|archaeological_site|temple|fort)$\"][name];"
            . "nwr{$a}[natural~\"^(beach|waterfall|peak|cave_entrance|hot_spring)$\"][name];"
            . "nwr{$a}[amenity~\"^(place_of_worship|restaurant|cafe)$\"][name][~\"^(wikidata|wikipedia|website|cuisine)$\"~\".\"];"
            . ");out center tags 400;";

        $res = Http::withHeaders(['User-Agent' => config('discover.user_agent')])
            ->timeout(60)
            ->retry(2, 4000, throw: false)
            ->asForm()
            ->post(config('discover.places.overpass_url'), ['data' => $ql]);

        if (!$res->ok()) {
            throw new RuntimeException('Overpass HTTP ' . $res->status());
        }

        return self::parse($res->json() ?? []);
    }

    /** @internal tách ra để test không cần mạng */
    public static function parse(array $json): array
    {
        $out = [];
        foreach ($json['elements'] ?? [] as $el) {
            $t = $el['tags'] ?? [];
            $name = trim((string) ($t['name:vi'] ?? $t['name'] ?? ''));
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($name === '' || $lat === null || $lng === null || !isset($el['type'], $el['id'])) {
                continue;
            }
            [$category, $kind] = self::classify($t);
            if ($category === null) {
                continue;
            }

            $rank = (isset($t['wikidata']) ? 3 : 0) + (isset($t['wikipedia']) ? 2 : 0)
                + (isset($t['website']) || isset($t['contact:website']) ? 1 : 0)
                + (isset($t['name:en']) ? 1 : 0) + (isset($t['heritage']) ? 2 : 0);

            $addr = trim(implode(', ', array_filter([
                trim(($t['addr:housenumber'] ?? '') . ' ' . ($t['addr:street'] ?? '')),
                $t['addr:suburb'] ?? $t['addr:district'] ?? null,
                $t['addr:city'] ?? $t['addr:province'] ?? null,
            ])));

            $out[] = [
                'externalId' => $el['type'] . '/' . $el['id'],
                'name' => mb_substr($name, 0, 160),
                'category' => $category,
                'kind' => $kind,
                'address' => $addr !== '' ? mb_substr($addr, 0, 255) : null,
                'lat' => round((float) $lat, 6),
                'lng' => round((float) $lng, 6),
                'rank' => $rank,
                'website' => isset($t['website']) ? mb_substr($t['website'], 0, 255) : (isset($t['contact:website']) ? mb_substr($t['contact:website'], 0, 255) : null),
            ];
        }

        return $out;
    }

    private static function classify(array $t): array
    {
        $am = $t['amenity'] ?? null;
        if ($am === 'restaurant' || $am === 'cafe') {
            return ['eat', $am];
        }
        if ($am === 'place_of_worship') {
            return ['culture', 'place_of_worship'];
        }
        if (isset($t['tourism']) && in_array($t['tourism'], ['museum', 'gallery'], true)) {
            return ['culture', $t['tourism']];
        }
        if (isset($t['historic'])) {
            return ['culture', 'historic'];
        }
        if (isset($t['tourism'])) {
            return ['see', $t['tourism']];
        }
        if (isset($t['natural'])) {
            return ['see', $t['natural']];
        }

        return [null, null];
    }
}
