<?php

namespace App\Services\Planner;

use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * Phương án khi mưa: với mỗi ngày của chuyến, người dùng chọn vài mục TRONG NHÀ để thay thế khi trời mưa.
 * Lưu trong tbl_trips.rainPlan (JSON {"<ngày>": [{"type","refId"}]}); không đụng vào lịch chính, không tính vào chi phí chính.
 *
 * Gợi ý chỉ lấy mục đã xuất bản, được xác nhận trong nhà (trải nghiệm indoor = 1, địa điểm thuộc config('planner.indoor_place_kinds')),
 * cùng điểm đến với chuyến, chưa có trong lịch. rank() và sanitize() là hàm thuần để kiểm thử.
 */
final class RainPlan
{
    public const TYPES = ['experience', 'place'];

    /**
     * Chuẩn hóa dữ liệu rainPlan gửi lên: chỉ ngày 1..$days, chỉ loại cho phép, bỏ trùng, tối đa $max mục mỗi ngày.
     *
     * @param mixed $raw
     * @return array<int,list<array{type:string,refId:int}>>
     */
    public static function sanitize(mixed $raw, int $days, int $max): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $day => $list) {
            $d = (int) $day;
            if ($d < 1 || $d > $days || !is_array($list)) {
                continue;
            }
            $seen = [];
            foreach ($list as $it) {
                $type = is_array($it) ? (string) ($it['type'] ?? '') : '';
                $id = is_array($it) ? (int) ($it['refId'] ?? 0) : 0;
                $k = $type . ':' . $id;
                if (!in_array($type, self::TYPES, true) || $id < 1 || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[$d][] = ['type' => $type, 'refId' => $id];
                if (count($out[$d]) >= $max) {
                    break;
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Xếp hạng ứng viên trong nhà. Điểm chỉ để xếp thứ tự; lý do viết từ dữ kiện có thật.
     *
     * @param list<array<string,mixed>> $cands  type, refId, name, mustTry, rank (địa điểm), lat, lng, minutes, kind
     * @param ?array{0:float,1:float} $anchor   tâm các điểm trong ngày (null nếu không có tọa độ)
     * @return list<array<string,mixed>> thêm score và reasons
     */
    public static function rank(array $cands, ?array $anchor, float $nearKm = 8.0): array
    {
        $maxRank = 1;
        foreach ($cands as $c) {
            $maxRank = max($maxRank, (int) ($c['rank'] ?? 0));
        }
        $out = [];
        foreach ($cands as $c) {
            $score = $c['type'] === 'experience' ? 15 : 0;
            $reasons = ['Trong nhà, dùng được khi trời mưa'];
            if (!empty($c['mustTry'])) {
                $score += 30;
                $reasons[] = 'Biên tập viên đánh dấu nên thử';
            }
            if ($c['type'] === 'place') {
                $score += (int) round(10 * ((int) ($c['rank'] ?? 0)) / $maxRank);
            }
            $km = null;
            if ($anchor && ($c['lat'] ?? null) !== null && ($c['lng'] ?? null) !== null) {
                $km = Geo::distanceKm($anchor[0], $anchor[1], (float) $c['lat'], (float) $c['lng']);
                if ($km <= $nearKm) {
                    $score += $km <= $nearKm / 2 ? 12 : 6;
                    $reasons[] = 'Cách các điểm trong ngày khoảng ' . ($km < 1 ? 'dưới 1' : (string) round($km)) . ' km';
                }
            }
            $out[] = $c + ['score' => $score, 'km' => $km, 'reasons' => $reasons];
        }
        usort($out, fn($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return $out;
    }

    // ------------------------------------------------------------------ nạp dữ liệu

    /**
     * Gợi ý cho một ngày. $items = mọi mục của chuyến (để loại trùng); $dayItems = mục của ngày (lấy tâm).
     *
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $dayItems
     * @param list<int> $destIds
     * @param list<string> $exclude  khóa "type:refId" đã chọn dự phòng cho ngày này
     * @return list<array<string,mixed>>
     */
    public function suggest(array $destIds, array $items, array $dayItems, array $exclude = [], ?int $limit = null): array
    {
        $destIds = array_values(array_unique(array_filter(array_map('intval', $destIds))));
        if (!$destIds) {
            return [];
        }
        $have = array_fill_keys($exclude, true);
        foreach ($items as $it) {
            if (!empty($it['refId'])) {
                $have[$it['type'] . ':' . $it['refId']] = true;
            }
        }
        $limit ??= (int) config('planner.rain_plan.suggest_limit', 8);
        $cands = [];

        foreach (DB::table('tbl_experiences')->where('reviewStatus', 'published')->where('indoor', 1)->whereIn('destinationId', $destIds)->get() as $x) {
            if (isset($have['experience:' . $x->experienceId])) {
                continue;
            }
            $cands[] = ['type' => 'experience', 'refId' => (int) $x->experienceId, 'name' => $x->name, 'sub' => (string) ($x->duration ?? 'Trải nghiệm'),
                'url' => route('experiences.show', ['slug' => $x->slug]), 'mustTry' => !empty($x->isMustTry), 'rank' => 0, 'minutes' => $x->durationMinutes ? (int) $x->durationMinutes : null,
                'lat' => ($x->latitude ?? null) !== null ? (float) $x->latitude : null, 'lng' => ($x->longitude ?? null) !== null ? (float) $x->longitude : null, 'destinationId' => (int) $x->destinationId];
        }
        $kinds = (array) config('planner.indoor_place_kinds', []);
        if ($kinds) {
            foreach (DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
                ->where('p.reviewStatus', 'published')->where('d.isActive', 1)->whereIn('p.destinationId', $destIds)->whereIn('p.kind', $kinds)
                ->orderByDesc('p.rank')->limit(80)->get(['p.placeId', 'p.name', 'p.kind', 'p.rank', 'p.isMustTry', 'p.latitude', 'p.longitude', 'p.destinationId', 'd.slug as destSlug']) as $p) {
                if (isset($have['place:' . $p->placeId])) {
                    continue;
                }
                $cands[] = ['type' => 'place', 'refId' => (int) $p->placeId, 'name' => $p->name, 'sub' => (string) (\App\Support\PlaceKind::label($p->kind) ?? ''),
                    'url' => route('destination.show', ['slug' => $p->destSlug]) . '#gan-day', 'mustTry' => !empty($p->isMustTry), 'rank' => (int) $p->rank, 'minutes' => null,
                    'lat' => $p->latitude !== null ? (float) $p->latitude : null, 'lng' => $p->longitude !== null ? (float) $p->longitude : null, 'destinationId' => (int) $p->destinationId];
            }
        }

        $pts = array_values(array_filter($dayItems, fn($x) => ($x['lat'] ?? null) !== null && ($x['lng'] ?? null) !== null));
        $anchor = $pts ? [array_sum(array_column($pts, 'lat')) / count($pts), array_sum(array_column($pts, 'lng')) / count($pts)] : null;
        $ranked = self::rank($cands, $anchor, (float) config('planner.rain_plan.near_km', 8));

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Đọc rainPlan đã lưu và đổi thành mục hiển thị được (bỏ mục đã bị gỡ khỏi kho).
     *
     * @return array<int,list<array{type:string,refId:int,name:string,sub:string,url:?string,lat:?float,lng:?float}>>
     */
    public function resolve(?string $json, TripPlanner $planner): array
    {
        $stored = $json ? json_decode($json, true) : null;
        if (!is_array($stored) || !$stored) {
            return [];
        }
        $refs = [];
        foreach ($stored as $list) {
            foreach ((array) $list as $it) {
                $refs[] = ['type' => (string) ($it['type'] ?? ''), 'refId' => (int) ($it['refId'] ?? 0)];
            }
        }
        $res = $planner->resolve($refs);
        $out = [];
        foreach ($stored as $day => $list) {
            foreach ((array) $list as $it) {
                $r = $res[($it['type'] ?? '') . ':' . ($it['refId'] ?? 0)] ?? null;
                if ($r) {
                    $out[(int) $day][] = ['type' => $r['type'], 'refId' => (int) $r['refId'], 'name' => $r['name'], 'sub' => (string) $r['sub'],
                        'url' => $r['url'] ?? null, 'lat' => $r['lat'] ?? null, 'lng' => $r['lng'] ?? null];
                }
            }
        }

        return $out;
    }
}
