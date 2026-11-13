<?php

namespace App\Services\Planner;

use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * T-29: cá nhân hóa chuyến vừa nhân bản từ Cộng đồng theo sở thích / điều không thích của NGƯỜI DÙNG MỚI.
 *
 * Theo luật (không LLM, cùng bảng config với PlannerEngine):
 *  1. Mục địa điểm / trải nghiệm trúng "không thích" (config planner.dislikes) bị thay bằng nơi khác CÙNG điểm đến, gần nơi cũ nhất
 *     (≤ replace_km), hợp sở thích (config/interests.php place_groups) và không trúng "không thích". Không có nơi thay thì chuyển
 *     mục đó vào "Đã lưu" (không xóa: người dùng vẫn tự quyết).
 *  2. Ghi sở thích / không thích vào chuyến mới để La bàn và Tour Match dùng tiếp.
 * Trả về danh sách thay đổi kèm lý do để hiện cho người dùng (giải thích được, hoàn tác được bằng lịch sử phiên bản).
 */
final class TripPersonalizer
{
    /** @return list<array{action:string,from:string,to:?string,day:?int,reason:string}> */
    public function apply(int $tripId, array $interests, array $dislikes): array
    {
        $interests = array_values(array_intersect($interests, array_keys((array) config('interests'))));
        $dislikes = array_values(array_intersect($dislikes, array_keys((array) config('planner.dislikes'))));
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
        if (!$trip) {
            return [];
        }
        $upd = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn('tbl_trips', 'interests')) {
            $upd['interests'] = json_encode($interests, JSON_UNESCAPED_UNICODE);
            $upd['dislikes'] = json_encode($dislikes, JSON_UNESCAPED_UNICODE);
        }
        if ($upd) {
            DB::table('tbl_trips')->where('tripId', $tripId)->update($upd);
        }
        if (!$dislikes) {
            return [];
        }

        $rules = (array) config('planner.dislikes');
        $groups = $this->groupsFor($interests);
        $kindGroup = [];
        foreach ((array) config('planner.kind_groups') as $g => $kinds) {
            foreach ($kinds as $k) {
                $kindGroup[$k] = $g;
            }
        }
        $before = DB::table('tbl_trip_items')->where('tripId', $tripId)->orderBy('dayNo')->orderBy('orderNo')->get()->map(fn($r) => (array) $r)->all();
        $items = DB::table('tbl_trip_items')->where('tripId', $tripId)->whereIn('refType', ['place', 'experience'])->orderBy('dayNo')->orderBy('orderNo')->get();
        $have = DB::table('tbl_trip_items')->where('tripId', $tripId)->get(['refType', 'refId'])->map(fn($r) => $r->refType . ':' . $r->refId)->flip()->all();
        $out = [];
        foreach ($items as $it) {
            $src = $it->refType === 'place'
                ? DB::table('tbl_external_places')->where('placeId', $it->refId)->first(['placeId', 'name', 'kind', 'latitude', 'longitude', 'destinationId'])
                : DB::table('tbl_experiences')->where('experienceId', $it->refId)->first(['experienceId', 'name', 'difficulty', 'indoor', 'latitude', 'longitude', 'destinationId']);
            if (!$src) {
                continue;
            }
            $why = $this->dislikeHit($it->refType, $src, $dislikes, $rules);
            if ($why === null) {
                continue;
            }
            $rep = $this->replacement($src, $groups, $dislikes, $rules, $kindGroup, $have);
            if ($rep) {
                $set = ['refType' => 'place', 'refId' => (int) $rep->placeId, 'cost' => null];
                foreach (['startMin', 'durationMin'] as $col) {   // giờ / thời lượng của nơi cũ không còn đúng với nơi mới
                    if (\Illuminate\Support\Facades\Schema::hasColumn('tbl_trip_items', $col)) {
                        $set[$col] = null;
                    }
                }
                DB::table('tbl_trip_items')->where('itemId', $it->itemId)->update($set);
                unset($have[$it->refType . ':' . $it->refId]);
                $have['place:' . $rep->placeId] = true;
                $out[] = ['action' => 'replace', 'from' => (string) $src->name, 'to' => (string) $rep->name, 'day' => $it->dayNo !== null ? (int) $it->dayNo : null,
                    'reason' => $why . '; thay bằng nơi gần đó' . ($groups ? ' hợp sở thích của bạn' : '') . ' (' . round($rep->km, 1) . ' km).'];
            } else {
                DB::table('tbl_trip_items')->where('itemId', $it->itemId)->update(['dayNo' => null]);
                $out[] = ['action' => 'unassign', 'from' => (string) $src->name, 'to' => null, 'day' => $it->dayNo !== null ? (int) $it->dayNo : null,
                    'reason' => $why . '; chưa có nơi thay phù hợp nên chuyển vào "Đã lưu".'];
            }
        }
        // lịch sử phiên bản: bản chép nguyên gốc (rev hiện tại) + bản đã cá nhân hóa (rev + 1) → khôi phục được bản gốc
        if ($out && TripRevisions::ready()) {
            $rev = (int) DB::table('tbl_trips')->where('tripId', $tripId)->value('rev');
            $revs = app(TripRevisions::class);
            $revs->record($tripId, null, (int) $trip->userId, 'external', $before);
            DB::table('tbl_trips')->where('tripId', $tripId)->update(['rev' => $rev + 1]);
            $revs->recordCurrent($tripId, (int) $trip->userId, 'external');
        }

        return $out;
    }

    private function dislikeHit(string $type, object $src, array $dislikes, array $rules): ?string
    {
        foreach ($dislikes as $dk) {
            $r = $rules[$dk] ?? [];
            if ($type === 'place' && in_array($src->kind ?? null, $r['place_kinds'] ?? [], true)) {
                return '“' . $src->name . '” là ' . (\App\Support\PlaceKind::label($src->kind) ?? $src->kind) . ', bạn chọn không thích ' . $this->label($dk);
            }
            if ($type === 'experience') {
                if (in_array($src->difficulty ?? null, $r['experience_difficulty'] ?? [], true)) {
                    return '“' . $src->name . '” có độ khó ' . $src->difficulty . ', bạn chọn không thích ' . $this->label($dk);
                }
                if (array_key_exists('experience_indoor', $r) && $src->indoor !== null && in_array((bool) $src->indoor, $r['experience_indoor'], true)) {
                    return '“' . $src->name . '” ở ngoài trời, bạn chọn không thích ' . $this->label($dk);
                }
            }
        }

        return null;
    }

    private function replacement(object $src, array $groups, array $dislikes, array $rules, array $kindGroup, array $have): ?object
    {
        if ($src->latitude === null || $src->longitude === null) {
            return null;
        }
        $banned = [];
        foreach ($dislikes as $dk) {
            $banned = array_merge($banned, (array) ($rules[$dk]['place_kinds'] ?? []));
        }
        $max = (float) config('planner.personalize.replace_km', 8);
        $best = null;
        $q = DB::table('tbl_external_places')->where('destinationId', $src->destinationId)->where('reviewStatus', 'published')
            ->whereNotNull('latitude')->whereNotNull('longitude')->whereNotIn('kind', array_merge($banned, ['restaurant', 'cafe', 'hotel', 'guest_house', 'hostel']));
        foreach ($q->limit(300)->get(['placeId', 'name', 'kind', 'latitude', 'longitude', 'rank']) as $p) {
            if (isset($have['place:' . $p->placeId])) {
                continue;
            }
            if ($groups && !in_array($kindGroup[$p->kind] ?? 'see', $groups, true)) {
                continue;
            }
            $km = Geo::distanceKm((float) $src->latitude, (float) $src->longitude, (float) $p->latitude, (float) $p->longitude);
            if ($km > $max) {
                continue;
            }
            $p->km = $km;
            if ($best === null || [$km, -$p->rank] < [$best->km, -$best->rank]) {
                $best = $p;
            }
        }

        return $best;
    }

    /** @return list<string> nhóm kind (config planner.kind_groups) hợp các sở thích; rỗng = không giới hạn */
    private function groupsFor(array $interests): array
    {
        $g = [];
        foreach ($interests as $k) {
            $g = array_merge($g, (array) (config("interests.$k.place_groups") ?? []));
        }

        return array_values(array_unique($g));
    }

    private function label(string $dk): string
    {
        return ['trekking' => 'leo núi, đi bộ đường dài', 'crowds' => 'chỗ đông người', 'outdoor' => 'hoạt động ngoài trời', 'long_travel' => 'đi xa'][$dk] ?? $dk;
    }
}
