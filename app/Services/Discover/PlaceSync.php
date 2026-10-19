<?php

namespace App\Services\Discover;

use App\Services\Discover\Poi\GeoapifyProvider;
use App\Services\Discover\Poi\OverpassProvider;
use App\Services\Discover\Poi\PoiProvider;
use App\Services\Content\ImportRaw;
use Illuminate\Support\Facades\DB;

/** Chọn provider, lọc top-N mỗi nhóm, ghi tbl_external_places (upsert + dọn bản ghi không còn). */
class PlaceSync
{
    public function provider(?string $name = null): PoiProvider
    {
        return match ($name ?: config('discover.places.provider')) {
            'geoapify' => new GeoapifyProvider(),
            default => new OverpassProvider(),
        };
    }

    /** Giữ tối đa N địa điểm mỗi nhóm, ưu tiên rank cao rồi gần tâm; bỏ trùng tên. */
    public static function select(array $places, float $lat, float $lng, array $caps): array
    {
        $by = [];
        foreach ($places as $p) {
            $p['_d'] = ($p['lat'] - $lat) ** 2 + ($p['lng'] - $lng) ** 2;
            $by[$p['category']][] = $p;
        }

        $out = [];
        foreach ($by as $cat => $list) {
            usort($list, fn($a, $b) => [$b['rank'], $a['_d']] <=> [$a['rank'], $b['_d']]);
            $seen = [];
            foreach ($list as $p) {
                $key = mb_strtolower($p['name']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                unset($p['_d']);
                $out[] = $p;
                if (count($seen) >= ($caps[$cat] ?? 8)) {
                    break;
                }
            }
        }

        return $out;
    }

    /** @return int số địa điểm đã lưu. Bản ghi mới có reviewStatus = draft (mặc định DB); bản đã duyệt không bị đổi. */
    public function syncDestination(object $d, PoiProvider $provider, bool $prune = true, bool $fromRaw = false): int
    {
        // A4: phản hồi nguồn ngoài (OSM) được đệm vào tbl_import_raw; --from-raw dựng lại không gọi mạng.
        $rawId = $provider->name() . ':' . $d->slug;
        if ($fromRaw) {
            $stored = ImportRaw::get('osm', $rawId);
            if (!$stored) {
                throw new \RuntimeException('chưa có vùng đệm, hãy chạy một lần không kèm --from-raw');
            }
            $raw = $stored['places'];
        } else {
            $raw = $provider->fetch((float) $d->latitude, (float) $d->longitude, (int) config('discover.places.radius_m'));
            if ($raw) {
                ImportRaw::put('osm', $rawId, ['slug' => $d->slug, 'provider' => $provider->name(), 'places' => $raw]);
            }
        }
        $keep = self::select($raw, (float) $d->latitude, (float) $d->longitude, config('discover.places.per_category'));

        $now = now();
        $ids = [];
        foreach ($keep as $p) {
            $ids[] = $p['externalId'];
            DB::table('tbl_external_places')->updateOrInsert(
                ['source' => $provider->name(), 'externalId' => $p['externalId']],
                [
                    'destinationId' => $d->destinationId,
                    'name' => $p['name'],
                    'category' => $p['category'],
                    'kind' => $p['kind'],
                    'address' => $p['address'],
                    'latitude' => $p['lat'],
                    'longitude' => $p['lng'],
                    'rank' => $p['rank'],
                    'website' => $p['website'],
                    'syncedAt' => $now,
                ]
            );
        }

        // Chỉ dọn khi lần này thực sự lấy được dữ liệu (tránh xoá sạch vì lỗi mạng trả rỗng)
        if ($prune && $ids) {
            DB::table('tbl_external_places')
                ->where('destinationId', $d->destinationId)
                ->where('source', $provider->name())
                ->whereNotIn('externalId', $ids)
                ->delete();
        }

        return count($keep);
    }
}
