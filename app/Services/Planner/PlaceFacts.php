<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quy trình dữ liệu cho dữ kiện địa điểm (giờ mở cửa, giá, trong / ngoài, thời lượng):
 *   nhập tự động (OSM / Geoapify)  ->  hàng chờ "cần kiểm tra"  ->  admin xác nhận / sửa (ghi nguồn + ngày kiểm tra)  ->  quá 180 ngày thì quay lại hàng chờ.
 * Người dùng báo sai (PlaceReports) cũng đẩy nơi đó lên đầu hàng chờ. Thẻ địa điểm hiện "nguồn · kiểm tra ngày …" hoặc "chưa xác minh".
 */
final class PlaceFacts
{
    public const STALE_DAYS = 180;
    /** nguồn giờ do con người nhập: không bị dữ liệu bản đồ ghi đè */
    public const HUMAN = ['manual', 'report', 'demo', 'admin'];

    public static function ready(): bool
    {
        return Schema::hasColumn('tbl_external_places', 'factsCheckedAt');
    }

    /** @param list<array{externalId:string,hours?:?string}> $places */
    public function importHours(string $source, array $places): int
    {
        if (!Schema::hasTable('tbl_opening_hours')) {
            return 0;
        }
        $svc = app(OpeningHours::class);
        $n = 0;
        foreach ($places as $p) {
            $h = trim((string) ($p['hours'] ?? ''));
            if ($h === '' || !OpeningHours::parseOsm($h)) {
                continue;
            }
            $id = (int) DB::table('tbl_external_places')->where('source', $source)->where('externalId', $p['externalId'])->value('placeId');
            if (!$id || DB::table('tbl_opening_hours')->where('refType', 'place')->where('refId', $id)->whereIn('source', self::HUMAN)->exists()) {
                continue;
            }
            $svc->store('place', $id, $h, $source === 'overpass' ? 'osm' : $source);
            if (self::ready()) {
                DB::table('tbl_external_places')->where('placeId', $id)->whereNull('factsSource')->update(['factsSource' => $source === 'overpass' ? 'OpenStreetMap' : ucfirst($source),
                    'factsUrl' => $source === 'overpass' && str_contains($p['externalId'], '/') ? 'https://www.openstreetmap.org/' . $p['externalId'] : null]);
            }
            $n++;
        }

        return $n;
    }

    /**
     * Hàng chờ "cần kiểm tra": nơi đã xuất bản thiếu giờ, chưa xác minh, quá hạn kiểm tra hoặc có người báo sai; nơi nằm trong nhiều chuyến lên trước.
     * @return list<object>
     */
    public function queue(?int $destId = null, int $limit = 60): array
    {
        if (!self::ready()) {
            return [];
        }
        $stale = now()->subDays(self::STALE_DAYS);
        $hasHours = DB::table('tbl_opening_hours')->select('refId')->where('refType', 'place');
        $q = DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
            ->where('p.reviewStatus', 'published')
            ->when($destId, fn($x) => $x->where('p.destinationId', $destId))
            ->where(fn($w) => $w->whereNull('p.factsCheckedAt')->orWhere('p.factsCheckedAt', '<', $stale)->orWhereNotIn('p.placeId', $hasHours))
            ->selectRaw('p.placeId, p.name, p.kind, p.priceMin, p.priceMax, p.factsSource, p.factsUrl, p.factsCheckedAt, d.name as destName, p.destinationId,
                (select count(*) from tbl_trip_items i where i.refType = "place" and i.refId = p.placeId) as used')
            ->orderByDesc('used')->orderByDesc('p.rank')->limit($limit);
        $rows = $q->get()->all();
        $reports = Schema::hasTable('tbl_place_reports') ? DB::table('tbl_place_reports')->where('refType', 'place')->where('status', 'open')
            ->whereIn('refId', array_column($rows, 'placeId'))->select('refId', DB::raw('count(*) n'))->groupBy('refId')->pluck('n', 'refId')->all() : [];
        $hours = app(OpeningHours::class)->forItems(array_map(fn($r) => ['type' => 'place', 'refId' => (int) $r->placeId], $rows));
        foreach ($rows as $r) {
            $r->reports = (int) ($reports[$r->placeId] ?? 0);
            $r->hours = $hours['place:' . $r->placeId] ?? null;
            $r->why = !$r->hours ? 'chưa có giờ mở cửa' : (!$r->factsCheckedAt ? 'chưa xác minh' . ($r->factsSource ? ' (' . $r->factsSource . ')' : '') : 'kiểm tra quá ' . self::STALE_DAYS . ' ngày');
        }
        usort($rows, fn($a, $b) => [$b->reports, $b->used] <=> [$a->reports, $a->used]);

        return $rows;
    }

    public function count(): int
    {
        if (!self::ready()) {
            return 0;
        }
        $hasHours = DB::table('tbl_opening_hours')->select('refId')->where('refType', 'place');

        return DB::table('tbl_external_places')->where('reviewStatus', 'published')
            ->where(fn($w) => $w->whereNull('factsCheckedAt')->orWhere('factsCheckedAt', '<', now()->subDays(self::STALE_DAYS))->orWhereNotIn('placeId', $hasHours))->count();
    }

    /** Admin xác nhận / sửa dữ kiện một nơi. */
    public function verify(int $placeId, ?string $hours, ?int $priceMin, ?int $priceMax, ?string $source, ?string $url, int $adminId): void
    {
        if ($hours !== null && $hours !== '') {
            app(OpeningHours::class)->store('place', $placeId, $hours, 'admin');
        }
        $upd = ['factsCheckedAt' => now(), 'factsSource' => $source ?: DB::raw('factsSource'), 'factsUrl' => $url ?: DB::raw('factsUrl')];
        if ($priceMin !== null && Schema::hasColumn('tbl_external_places', 'priceMin')) {
            $upd['priceMin'] = $priceMin;
            $upd['priceMax'] = max($priceMin, (int) ($priceMax ?? $priceMin));
        }
        DB::table('tbl_external_places')->where('placeId', $placeId)->update($upd);
        if (Schema::hasTable('tbl_place_reports')) {
            DB::table('tbl_place_reports')->where('refType', 'place')->where('refId', $placeId)->where('status', 'open')
                ->update(['status' => 'accepted', 'reviewedBy' => $adminId, 'reviewedAt' => now(), 'reviewNote' => 'đã kiểm tra lại toàn bộ dữ kiện']);
        }
    }

    /**
     * Dữ kiện hiển thị trên thẻ địa điểm.
     * @param list<int> $placeIds
     * @return array<int,array{src:?string,url:?string,checked:?string,stale:bool}>
     */
    public function forPlaces(array $placeIds): array
    {
        if (!self::ready() || !$placeIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbl_external_places')->whereIn('placeId', array_unique($placeIds))->get(['placeId', 'factsSource', 'factsUrl', 'factsCheckedAt']) as $r) {
            $out[(int) $r->placeId] = ['src' => $r->factsSource, 'url' => $r->factsUrl, 'checked' => $r->factsCheckedAt ? substr((string) $r->factsCheckedAt, 0, 10) : null,
                'stale' => !$r->factsCheckedAt || $r->factsCheckedAt < now()->subDays(self::STALE_DAYS)];
        }

        return $out;
    }
}
