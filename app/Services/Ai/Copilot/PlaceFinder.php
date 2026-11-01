<?php

namespace App\Services\Ai\Copilot;

use App\Services\Planner\OpeningHours;
use App\Services\Planner\Route\HaversineProvider;
use App\Services\Planner\TripPlanner;
use Illuminate\Support\Facades\DB;

/**
 * Công cụ search_places của La bàn: tìm nơi THẬT trong kho Travela (đã duyệt) quanh chuyến đi.
 * LLM chỉ được thêm / thay bằng những gì công cụ này trả về (khóa c:type:id), nên không thể "bịa" địa điểm.
 * Mỗi kết quả kèm khoảng cách tới mục mốc và giờ mở cửa ĐÚNG thứ của ngày đang xét (nếu biết).
 */
final class PlaceFinder
{
    public const GROUPS = ['eat', 'nature', 'culture', 'fun', 'see', 'indoor', 'cafe'];
    private const LIMIT = 6;

    public function __construct(private TripPlanner $planner, private OpeningHours $hours) {}

    /**
     * @param array{text?:?string,group?:?string,nearItem?:?string,day?:?int} $q
     * @param array $ctx CopilotContext::build()
     * @return array{cands:array<string,array>,lines:list<string>}
     */
    public function search(object $trip, array $q, array $ctx): array
    {
        $keys = $ctx['keys'];
        $near = isset($q['nearItem'], $keys[$q['nearItem']]) && $keys[$q['nearItem']]['lat'] !== null ? $keys[$q['nearItem']] : null;
        $day = isset($q['day']) && is_int($q['day']) ? $q['day'] : ($near['day'] ?? null);
        $dow = $day !== null ? ($ctx['dows'][$day] ?? null) : null;
        $group = in_array($q['group'] ?? null, self::GROUPS, true) ? $q['group'] : null;
        $text = trim(mb_substr((string) ($q['text'] ?? ''), 0, 60));

        $have = [];
        $destIds = [(int) ($trip->destinationId ?? 0)];
        foreach ($keys as $k) {
            if ($k['refId'] !== null) {
                $have[$k['type'] . ':' . $k['refId']] = true;
            }
            if ($k['destinationId']) {
                $destIds[] = $k['destinationId'];
            }
        }
        $destIds = array_values(array_unique(array_filter($destIds)));

        $kinds = null;
        if ($group === 'indoor') {
            $kinds = (array) config('planner.indoor_place_kinds', ['museum', 'gallery', 'aquarium', 'cafe', 'restaurant']);
        } elseif ($group === 'cafe') {
            $kinds = ['cafe'];
        } elseif ($group) {
            $kinds = (array) (config('planner.kind_groups.' . $group) ?? []);
        }

        $qb = DB::table('tbl_external_places')->where('reviewStatus', 'published')
            ->when($destIds, fn($x) => $x->whereIn('destinationId', $destIds))
            ->when($kinds, fn($x) => $x->whereIn('kind', $kinds))
            ->when($text !== '', fn($x) => $x->where('name', 'like', '%' . addcslashes($text, '%_\\') . '%'));
        if ($near) {
            $d = 4 / 111.0;
            $qb->whereBetween('latitude', [$near['lat'] - $d, $near['lat'] + $d])
                ->whereBetween('longitude', [$near['lng'] - $d / max(0.2, cos(deg2rad($near['lat']))), $near['lng'] + $d / max(0.2, cos(deg2rad($near['lat'])))]);
        }
        $rows = $qb->orderByDesc('isMustTry')->orderBy('rank')->limit(60)->get(['placeId', 'name', 'kind', 'latitude', 'longitude']);

        $list = [];
        foreach ($rows as $r) {
            if (isset($have['place:' . $r->placeId])) {
                continue;
            }
            $km = $near && $r->latitude !== null ? HaversineProvider::straightKm([$near['lat'], $near['lng']], [(float) $r->latitude, (float) $r->longitude]) : null;
            $list[] = ['type' => 'place', 'refId' => (int) $r->placeId, 'name' => $r->name, 'sub' => (string) (\App\Support\PlaceKind::label($r->kind) ?? ''),
                'kind' => $r->kind, 'km' => $km, 'lat' => $r->latitude !== null ? (float) $r->latitude : null, 'lng' => $r->longitude !== null ? (float) $r->longitude : null];
        }
        // trải nghiệm theo tên (không có nhóm loại địa điểm)
        if ($text !== '' && !$kinds) {
            foreach ($this->planner->search($trip, $text, 'experience', $destIds[0] ?? null, 3) as $x) {
                if (!isset($have['experience:' . $x['refId']])) {
                    $list[] = ['type' => 'experience', 'refId' => (int) $x['refId'], 'name' => $x['name'], 'sub' => (string) $x['sub'], 'kind' => null, 'km' => null,
                        'lat' => $x['lat'], 'lng' => $x['lng']];
                }
            }
        }

        $hours = $this->hours->forItems($list);
        foreach ($list as &$x) {
            $h = $hours[$x['type'] . ':' . $x['refId']] ?? null;
            $x['hoursText'] = OpeningHours::describe($h, $dow);
            $x['closed'] = $x['hoursText'] === 'đóng cửa';
        }
        unset($x);
        // còn mở trước, gần trước
        usort($list, fn($a, $b) => [$a['closed'], $a['km'] ?? 99] <=> [$b['closed'], $b['km'] ?? 99]);
        $list = array_slice($list, 0, self::LIMIT);

        $cands = [];
        $lines = [];
        foreach ($list as $x) {
            $k = 'c:' . $x['type'] . ':' . $x['refId'];
            $cands[$k] = $x;
            $lines[] = $k . ' | ' . \App\Services\Ai\Explain\ExplainFacts::clean($x['name']) . ' | ' . ($x['sub'] ?: $x['type'])
                . ($x['km'] !== null ? ' | cách ' . $q['nearItem'] . ' ~' . str_replace('.', ',', (string) round($x['km'], 1)) . ' km' : '')
                . ($day !== null ? ' | ngày ' . $day . ': ' . ($x['hoursText'] ?? 'chưa rõ giờ mở cửa') : '');
        }

        return ['cands' => $cands, 'lines' => $lines];
    }
}
