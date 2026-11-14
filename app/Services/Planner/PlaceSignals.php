<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Học từ hành vi (không cần mô hình ML, giải thích được, tất định):
 *  - Ghi: mỗi lần lưu chuyến, so bản cũ với bản mới -> thêm / xóa / đã ghé / bỏ qua; người dùng áp dụng / bỏ qua đề xuất La bàn.
 *  - Hồ sơ từng người: điểm theo NHÓM loại nơi = (thêm + 2·ghé + áp dụng − xóa − 2·bỏ qua − bỏ qua đề xuất) / (số tín hiệu + 4), trong [-1, 1].
 *    Mẫu số cộng 4 để vài thao tác đầu không làm lệch mạnh (làm mượt kiểu Laplace).
 *  - Thống kê chung từng nơi: tỷ lệ "được giữ / được ghé" so với "bị xóa / bỏ qua" của mọi người, chỉ dùng khi có từ 5 tín hiệu.
 * PlannerEngine cộng điểm nhỏ theo hai số này, kèm lý do ("Bạn hay giữ các nơi Văn hóa", "73% người đi trước đã ghé"), nên vẫn giải thích được.
 */
final class PlaceSignals
{
    public const TYPES = ['place', 'experience', 'food'];
    public const MIN_GLOBAL = 5;

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_place_signals');
    }

    /** Nhóm của một mục: nhóm loại nơi (kind_groups) cho địa điểm; "experience" / "food" cho loại kia. */
    public static function groupOf(string $type, ?string $kind): string
    {
        if ($type !== 'place') {
            return $type;
        }
        foreach ((array) config('planner.kind_groups') as $g => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return $g;
            }
        }

        return 'see';
    }

    /**
     * So bản cũ / mới của một lần lưu.
     * @param list<object> $old dòng tbl_trip_items trước khi lưu
     * @param list<array> $new mục vừa lưu (type, refId, visitedAt, skippedAt)
     */
    public function recordSave(int $userId, int $tripId, array $old, array $new): void
    {
        if (!self::ready()) {
            return;
        }
        $was = [];
        foreach ($old as $o) {
            if (in_array($o->refType, self::TYPES, true) && $o->refId) {
                $was[$o->refType . ':' . $o->refId] = $o;
            }
        }
        $now = [];
        foreach ($new as $n) {
            if (in_array($n['type'] ?? '', self::TYPES, true) && !empty($n['refId'])) {
                $now[$n['type'] . ':' . (int) $n['refId']] = $n;
            }
        }
        $rows = [];
        $put = function (string $key, string $sig) use (&$rows, $userId, $tripId) {
            [$t, $id] = explode(':', $key);
            $rows[] = ['userId' => $userId, 'tripId' => $tripId, 'refType' => $t, 'refId' => (int) $id, 'signal' => $sig, 'createdAt' => now()];
        };
        foreach (array_diff_key($now, $was) as $k => $n) {
            $put($k, 'added');
        }
        foreach (array_diff_key($was, $now) as $k => $o) {
            $put($k, 'removed');
        }
        foreach (array_intersect_key($now, $was) as $k => $n) {
            if (!empty($n['visitedAt']) && empty($was[$k]->visitedAt ?? null)) {
                $put($k, 'visited');
            }
            if (!empty($n['skippedAt']) && empty($was[$k]->skippedAt ?? null)) {
                $put($k, 'skipped');
            }
        }
        if (count($rows) > 60) {
            $rows = array_slice($rows, 0, 60);   // lưu một chuyến mới toanh / nhân bản: không làm phình bảng
        }
        if ($rows) {
            $this->withGroups($rows);
            DB::table('tbl_place_signals')->insert($rows);
        }
    }

    /** Áp dụng / bỏ qua một đề xuất La bàn: tín hiệu cho các nơi được THÊM / THAY VÀO bởi đề xuất. */
    public function recordProposal(?int $userId, int $tripId, array $changes, bool $applied): void
    {
        if (!self::ready() || !$userId) {
            return;
        }
        $rows = [];
        foreach ($changes as $c) {
            $w = $c['with'] ?? null;
            if (is_array($w) && in_array($w['type'] ?? '', self::TYPES, true) && !empty($w['refId'])) {
                $rows[] = ['userId' => $userId, 'tripId' => $tripId, 'refType' => $w['type'], 'refId' => (int) $w['refId'], 'signal' => $applied ? 'applied' : 'ignored', 'createdAt' => now()];
            }
        }
        if ($rows) {
            $this->withGroups($rows);
            DB::table('tbl_place_signals')->insert($rows);
        }
    }

    private function withGroups(array &$rows): void
    {
        $ids = array_unique(array_map(fn($r) => (int) $r['refId'], array_filter($rows, fn($r) => $r['refType'] === 'place')));
        $kinds = $ids ? DB::table('tbl_external_places')->whereIn('placeId', $ids)->pluck('kind', 'placeId')->all() : [];
        foreach ($rows as &$r) {
            $r['grp'] = self::groupOf($r['refType'], $kinds[$r['refId']] ?? null);
        }
    }

    /** @return array<string,float> nhóm => điểm trong [-1, 1] (chỉ nhóm có tín hiệu) */
    public function profile(int $userId): array
    {
        if (!self::ready() || $userId <= 0) {
            return [];
        }
        $w = ['added' => 1, 'visited' => 2, 'applied' => 1, 'removed' => -1, 'skipped' => -2, 'ignored' => -0.5];
        $out = [];
        foreach (DB::table('tbl_place_signals')->where('userId', $userId)->where('createdAt', '>=', now()->subYear())
            ->select('grp', 'signal', DB::raw('count(*) n'))->groupBy('grp', 'signal')->get() as $r) {
            $g = $r->grp ?? 'see';
            $out[$g]['s'] = ($out[$g]['s'] ?? 0) + $w[$r->signal] * (int) $r->n;
            $out[$g]['n'] = ($out[$g]['n'] ?? 0) + (int) $r->n;
        }

        return array_map(fn($x) => round(max(-1, min(1, $x['s'] / ($x['n'] + 4))), 2), $out);
    }

    /**
     * @param list<string> $keys "type:refId"
     * @return array<string,array{keep:float,visit:?float,n:int}> chỉ nơi có >= MIN_GLOBAL tín hiệu
     */
    public function stats(array $keys): array
    {
        if (!self::ready() || !$keys) {
            return [];
        }
        $by = [];
        foreach ($keys as $k) {
            [$t, $id] = array_pad(explode(':', $k, 2), 2, null);
            if (in_array($t, self::TYPES, true) && $id) {
                $by[$t][] = (int) $id;
            }
        }
        $out = [];
        foreach ($by as $t => $ids) {
            foreach (DB::table('tbl_place_signals')->where('refType', $t)->whereIn('refId', array_unique($ids))
                ->select('refId', DB::raw("sum(`signal` in ('added','applied')) a"), DB::raw("sum(`signal` = 'visited') v"),
                    DB::raw("sum(`signal` in ('removed','ignored')) r"), DB::raw("sum(`signal` = 'skipped') s"), DB::raw('count(*) n'))->groupBy('refId')->get() as $r) {
                if ((int) $r->n < self::MIN_GLOBAL) {
                    continue;
                }
                $pos = (int) $r->a + (int) $r->v;
                $neg = (int) $r->r + (int) $r->s;
                $out[$t . ':' . $r->refId] = ['keep' => round($pos / max(1, $pos + $neg), 2),
                    'visit' => ((int) $r->v + (int) $r->s) >= 3 ? round((int) $r->v / ((int) $r->v + (int) $r->s), 2) : null, 'n' => (int) $r->n];
            }
        }

        return $out;
    }

    /**
     * Đánh giá sau chuyến: sao trung bình + thời gian ở thực tế (trung vị) của mỗi nơi, chỉ khi có từ 3 lượt.
     * @param list<string> $keys
     * @return array<string,array{avg:float,n:int,stay:?int}>
     */
    public function reviews(array $keys): array
    {
        if (!Schema::hasTable('tbl_place_reviews') || !$keys) {
            return [];
        }
        $by = [];
        foreach ($keys as $k) {
            [$t, $id] = array_pad(explode(':', $k, 2), 2, null);
            if (in_array($t, self::TYPES, true) && $id) {
                $by[$t][] = (int) $id;
            }
        }
        $out = [];
        foreach ($by as $t => $ids) {
            $rows = DB::table('tbl_place_reviews')->where('refType', $t)->whereIn('refId', array_unique($ids))->get(['refId', 'stars', 'stayMin'])->groupBy('refId');
            foreach ($rows as $id => $g) {
                if ($g->count() < 3) {
                    continue;
                }
                $stays = $g->pluck('stayMin')->filter()->sort()->values();
                $out[$t . ':' . $id] = ['avg' => round((float) $g->avg('stars'), 1), 'n' => $g->count(),
                    'stay' => $stays->count() >= 3 ? (int) $stays[intdiv($stays->count(), 2)] : null];
            }
        }

        return $out;
    }
}
