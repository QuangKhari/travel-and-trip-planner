<?php

namespace App\Services\Planner;

use App\Support\DiscoverImage;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\PlannerRules;
use App\Support\PlaceKind;
use Illuminate\Support\Facades\DB;

/**
 * "Khám phá quanh tuyến" (tham khảo Roadtrippers: tìm điểm dừng trong một dải quanh tuyến đường).
 *
 * Đầu vào là tuyến của MỘT ngày: danh sách tọa độ các điểm theo thứ tự (lấy từ trình soạn, kể cả thay đổi chưa lưu).
 * Với mỗi địa điểm/trải nghiệm đã xuất bản trong kho Travela:
 *  - offKm   : khoảng cách ngắn nhất từ địa điểm tới tuyến (tới đoạn thẳng giữa hai điểm liên tiếp);
 *  - detourKm: quãng đường PHẢI ĐI THÊM nếu ghé vào giữa hai điểm đó = d(A,P) + d(P,B) - d(A,B), nhân hệ số đường bộ;
 *  - after   : chỉ số điểm A trong tuyến (ghé sau điểm này), để giao diện chèn đúng chỗ;
 *  - detourMin / detourLevel: phút phải đi thêm (RouteService, theo phương tiện) và nhãn ngưỡng (config planner.detour_levels).
 * Chỉ giữ địa điểm có offKm <= bán kính người dùng chọn, xếp theo quãng vòng thêm (ít trước).
 * Tuyến chỉ có một điểm: dùng bán kính quanh điểm đó, quãng vòng thêm = đi và về.
 *
 * Phép đo nằm ở RouteService::detour() (đường chim bay × hệ số đường bộ, cùng công thức planner-geo.js).
 */
class RouteExplorer
{
    /** Nhóm lọc trên giao diện => nhóm kind của config('planner.kind_groups'); 'experience' là trải nghiệm Travela. */
    public const GROUPS = ['all', 'eat', 'cafe', 'sight', 'nature', 'fun', 'experience'];
    public const MAX_POINTS = 30;
    public const LIMIT = 40;

    /**
     * @param array<int,array{0:float,1:float}> $pts tuyến theo thứ tự [lat, lng]
     * @param array<int,string> $exclude các "type:refId" đã có trong chuyến
     * @return array{results:array<int,array>, total:int, km:float}
     */
    public function around(array $pts, float $km, string $group = 'all', array $exclude = []): array
    {
        $pts = array_values(array_slice($pts, 0, self::MAX_POINTS));
        if (!$pts) {
            return ['results' => [], 'total' => 0, 'km' => $km];
        }
        $km = max(0.3, min(10.0, $km));
        $group = in_array($group, self::GROUPS, true) ? $group : 'all';

        // khung chữ nhật bao tuyến, nới thêm bán kính (1 độ vĩ ~ 111 km)
        $lats = array_column($pts, 0);
        $lngs = array_column($pts, 1);
        $dLat = $km / 111.0;
        $dLng = $km / (111.0 * max(0.2, cos(deg2rad(array_sum($lats) / count($lats)))));
        $box = [min($lats) - $dLat, max($lats) + $dLat, min($lngs) - $dLng, max($lngs) + $dLng];

        $cands = [];
        if ($group !== 'experience') {
            $q = DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
                ->where('p.reviewStatus', 'published')->where('d.isActive', 1)
                ->whereBetween('p.latitude', [$box[0], $box[1]])->whereBetween('p.longitude', [$box[2], $box[3]]);
            $kinds = $this->kindsFor($group);
            if ($kinds !== null) {
                $q->whereIn('p.kind', $kinds);
            }
            foreach ($q->limit(600)->get(['p.placeId', 'p.name', 'p.kind', 'p.address', 'p.latitude', 'p.longitude', 'p.rank', 'p.isMustTry', 'd.name as destName', 'd.slug as destSlug']) as $p) {
                $cands[] = ['type' => 'place', 'refId' => (int) $p->placeId, 'name' => (string) $p->name, 'kind' => PlaceKind::label($p->kind) ?? '',
                    'group' => $this->groupOf((string) $p->kind), 'lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'address' => $p->address,
                    'image' => null, 'durationMin' => TripPlanner::defaultDuration(['type' => 'place', 'kind' => $p->kind]), 'priceText' => null,
                    'mustTry' => !empty($p->isMustTry), 'rank' => (int) $p->rank, 'destName' => $p->destName,
                    'url' => route('destination.show', ['slug' => $p->destSlug]) . '#gan-day'];
            }
        }
        if ($group === 'all' || $group === 'experience') {
            $q = DB::table('tbl_experiences')->where('reviewStatus', 'published')->whereNotNull('latitude')->whereNotNull('longitude')
                ->whereBetween('latitude', [$box[0], $box[1]])->whereBetween('longitude', [$box[2], $box[3]]);
            foreach ($q->limit(200)->get() as $x) {
                $price = null;
                if (!empty($x->priceMin)) {
                    $price = number_format((float) $x->priceMin, 0, ',', '.') . 'đ' . (!empty($x->priceMax) && (float) $x->priceMax > (float) $x->priceMin ? ' – ' . number_format((float) $x->priceMax, 0, ',', '.') . 'đ' : '');
                }
                $cands[] = ['type' => 'experience', 'refId' => (int) $x->experienceId, 'name' => (string) $x->name, 'kind' => 'Trải nghiệm',
                    'group' => 'experience', 'lat' => (float) $x->latitude, 'lng' => (float) $x->longitude, 'address' => null,
                    'image' => !empty($x->coverImage) ? DiscoverImage::url((string) $x->coverImage) : null,
                    'durationMin' => !empty($x->durationMinutes) ? (int) $x->durationMinutes : TripPlanner::defaultDuration(['type' => 'experience']),
                    'priceText' => $price, 'mustTry' => !empty($x->isMustTry), 'rank' => 5, 'destName' => null,
                    'url' => route('experiences.show', ['slug' => $x->slug])];
            }
        }

        $skip = array_flip($exclude);
        $route = app(RouteService::class);
        $rules = PlannerRules::fromConfig();
        $out = [];
        foreach ($cands as $c) {
            if (isset($skip[$c['type'] . ':' . $c['refId']])) {
                continue;
            }
            $m = self::measure($pts, $c['lat'], $c['lng']);
            if ($m['offKm'] > $km) {
                continue;
            }
            $min = $route->minutesFor($m['detourKm']);   // người dùng cần "mất thêm bao lâu", không chỉ km
            $out[] = $c + $m + ['detourMin' => $min, 'detourLevel' => $rules->detourLevel($min)];
        }
        usort($out, fn($a, $b) => [$a['detourKm'], -$a['mustTry'], -$a['rank']] <=> [$b['detourKm'], -$b['mustTry'], -$b['rank']]);

        return ['results' => array_slice($out, 0, self::LIMIT), 'total' => count($out), 'km' => $km];
    }

    /**
     * T-03: gợi ý lấp khoảng trống giữa hai mục A → B. Chỉ lấy nơi mà (thời gian ở + phút đi vòng thêm) vừa khoảng trống,
     * và (nếu biết giờ mở cửa) đang mở trong khoảng đó. Xếp theo: vừa khít nhất (ít phút thừa) rồi ít đi vòng.
     *
     * @param array{0:float,1:float} $a  @param array{0:float,1:float} $b
     * @return list<array<string,mixed>>
     */
    public function fillGap(array $a, array $b, int $gapMin, int $fromMin, ?int $dow, array $exclude = [], int $limit = 5): array
    {
        if ($gapMin < 20) {
            return [];
        }
        $found = $this->around([$a, $b], (float) config('planner.gap_fill.km', 3), 'all', $exclude)['results'] ?? [];
        $hours = app(OpeningHours::class)->forItems(array_map(fn($c) => ['type' => $c['type'], 'refId' => $c['refId']], $found));
        $out = [];
        foreach ($found as $c) {
            $dur = max(15, (int) ($c['durationMin'] ?? 45));
            $need = $dur + (int) $c['detourMin'];
            if ($need > $gapMin) {
                // ở ngắn hơn bình thường vẫn ổn nếu còn ≥ 2/3 thời lượng gợi ý
                $dur = $gapMin - (int) $c['detourMin'];
                if ($dur < max(20, (int) round(($c['durationMin'] ?? 45) * 2 / 3))) {
                    continue;
                }
                $need = $gapMin;
            }
            $h = $hours[$c['type'] . ':' . $c['refId']] ?? null;
            $start = $fromMin + intdiv((int) $c['detourMin'], 2);
            $status = OpeningHours::check($h, $dow, $start, $start + $dur);
            if ($status !== null) {
                continue;   // biết giờ và đang đóng / chưa mở / sắp đóng trong khoảng trống
            }
            $out[] = $c + ['stayMin' => $dur, 'spareMin' => $gapMin - $need, 'hoursKnown' => $h !== null && $dow !== null,
                'fitText' => 'Ở ' . $dur . '′ + đi thêm ' . (int) $c['detourMin'] . '′, vừa khoảng trống ' . $gapMin . '′' . ($h !== null && $dow !== null ? ' · đang mở cửa' : '')];
        }
        usort($out, fn($x, $y) => [$x['spareMin'], $x['detourKm'], -$x['rank']] <=> [$y['spareMin'], $y['detourKm'], -$y['rank']]);

        return array_slice($out, 0, $limit);
    }

    /**
     * Đo một điểm P so với tuyến: offKm (khoảng cách tới tuyến), detourKm (đi thêm khi ghé), after (ghé sau điểm thứ mấy, tính từ 0).
     * Hàm thuần, dùng chung cho kiểm thử.
     *
     * @param array<int,array{0:float,1:float}> $pts
     * @return array{offKm:float,detourKm:float,after:int}
     */
    public static function measure(array $pts, float $lat, float $lng): array
    {
        return app(RouteService::class)->detour($pts, $lat, $lng);   // một nơi tính tuyến cho cả Planner
    }

    private function kindsFor(string $group): ?array
    {
        $g = (array) config('planner.kind_groups', []);

        return match ($group) {
            'eat' => array_values(array_diff((array) ($g['eat'] ?? []), ['cafe'])),
            'cafe' => ['cafe'],
            'sight' => array_values(array_merge((array) ($g['see'] ?? []), (array) ($g['culture'] ?? []))),
            'nature' => (array) ($g['nature'] ?? []),
            'fun' => (array) ($g['fun'] ?? []),
            default => null,
        };
    }

    private function groupOf(string $kind): string
    {
        if ($kind === 'cafe') {
            return 'cafe';
        }
        foreach ((array) config('planner.kind_groups', []) as $g => $kinds) {
            if (in_array($kind, (array) $kinds, true)) {
                return ['eat' => 'eat', 'see' => 'sight', 'culture' => 'sight', 'nature' => 'nature', 'fun' => 'fun'][$g] ?? 'sight';
            }
        }

        return 'sight';
    }
}
