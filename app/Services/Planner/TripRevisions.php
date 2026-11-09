<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch sử phiên bản chuyến đi (tbl_trip_revisions).
 *  - record(): gọi TRONG transaction lưu chuyến, sau khi đã khóa dòng tbl_trips và tăng rev.
 *  - list(): các bản gần nhất (không kèm payload).
 *  - restore(): đưa chuyến về đúng nội dung một bản cũ; kiểm tra rev như lưu thường (409 nếu ai đó vừa lưu),
 *    tạo phiên bản MỚI loại "restore" nên luôn hoàn tác được thao tác khôi phục.
 * Giữ tối đa config('planner.revision_history_keep') bản / chuyến để bảng không phình.
 */
class TripRevisions
{
    /** Cột của tbl_trips được chụp lại / khôi phục. */
    public const TRIP_FIELDS = ['title', 'startDate', 'days', 'budget', 'groupSize', 'destinationId', 'rainPlan'];

    public static function ready(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasTable('tbl_trip_revisions');
    }

    public static function keep(): int
    {
        return max(5, (int) config('planner.revision_history_keep', 30));
    }

    /** @param list<array<string,mixed>> $rows dòng tbl_trip_items đúng như đã ghi */
    public function record(int $tripId, ?int $baseRev, ?int $actorId, string $type, array $rows): void
    {
        if (!self::ready()) {
            return;
        }
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
        $snap = [];
        foreach (self::TRIP_FIELDS as $c) {
            if (property_exists($trip, $c)) {
                $snap[$c] = $trip->$c;
            }
        }
        $items = array_map(function (array $r) {
            unset($r['tripId'], $r['itemId']);

            return $r;
        }, $rows);
        DB::table('tbl_trip_revisions')->insert([
            'tripId' => $tripId, 'revisionNo' => (int) $trip->rev, 'baseRevision' => $baseRev, 'actorId' => $actorId, 'changeType' => $type,
            'itemCount' => count($items), 'payload' => json_encode(['trip' => $snap, 'items' => $items], JSON_UNESCAPED_UNICODE), 'createdAt' => now(),
        ]);
        $cut = DB::table('tbl_trip_revisions')->where('tripId', $tripId)->orderByDesc('revisionNo')->skip(self::keep())->value('revisionNo');
        if ($cut !== null) {
            DB::table('tbl_trip_revisions')->where('tripId', $tripId)->where('revisionNo', '<=', $cut)->delete();
        }
    }

    /** Chụp lại trạng thái hiện có trong DB (cho các thao tác sửa mục ngoài luồng lưu chính, ví dụ thêm chuyến bay / khách sạn). */
    public function recordCurrent(int $tripId, ?int $actorId, string $type): void
    {
        if (!self::ready()) {
            return;
        }
        $rows = DB::table('tbl_trip_items')->where('tripId', $tripId)->orderBy('dayNo')->orderBy('orderNo')->get()
            ->map(fn($r) => (array) $r)->all();
        $this->record($tripId, null, $actorId, $type, $rows);
    }

    /** @return list<array{rev:int, type:string, by:?string, items:int, at:string}> */
    public function list(int $tripId, int $limit = 30): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('tbl_trip_revisions as r')->leftJoin('tbl_users as u', 'u.userId', '=', 'r.actorId')
            ->where('r.tripId', $tripId)->orderByDesc('r.revisionNo')->limit($limit)
            ->get(['r.revisionNo', 'r.changeType', 'r.itemCount', 'r.createdAt', 'u.fullName', 'u.username'])
            ->map(fn($r) => ['rev' => (int) $r->revisionNo, 'type' => $r->changeType, 'by' => $r->fullName ?: $r->username, 'items' => (int) $r->itemCount,
                'at' => (string) $r->createdAt])->all();
    }

    /**
     * Nội dung một bản và khác biệt so với chuyến hiện tại, để người dùng xem trước khi khôi phục.
     * @return array{rev:int, type:string, by:?string, at:string, trip:array, days:list<array{day:?int, items:list<string>}>,
     *   diff:array{add:list<string>, remove:list<string>, moved:int, meta:list<string>, same:bool}}|null
     */
    public function show(int $tripId, int $revisionNo): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $row = DB::table('tbl_trip_revisions as r')->leftJoin('tbl_users as u', 'u.userId', '=', 'r.actorId')
            ->where('r.tripId', $tripId)->where('r.revisionNo', $revisionNo)->first(['r.*', 'u.fullName', 'u.username']);
        if (!$row) {
            return null;
        }
        $p = json_decode((string) $row->payload, true) ?: [];
        $snapItems = array_values((array) ($p['items'] ?? []));
        $curItems = DB::table('tbl_trip_items')->where('tripId', $tripId)->orderBy('dayNo')->orderBy('orderNo')->get()->map(fn($r) => (array) $r)->all();
        $names = $this->names(array_merge($snapItems, $curItems));

        $days = [];
        foreach ($snapItems as $it) {
            $d = isset($it['dayNo']) && $it['dayNo'] !== null ? (int) $it['dayNo'] : null;
            $days[$d === null ? 'x' : $d][] = $names[self::key($it)] ?? 'Mục không rõ';
        }
        uksort($days, fn($a, $b) => ($a === 'x' ? 999 : (int) $a) <=> ($b === 'x' ? 999 : (int) $b));
        $dayList = [];
        foreach ($days as $d => $list) {
            $dayList[] = ['day' => $d === 'x' ? null : (int) $d, 'items' => $list];
        }

        // khác biệt: theo "danh tính" mục (loại + mã, hoặc loại + tên với mục tự nhập), tính cả số lần lặp
        $count = fn(array $items) => array_count_values(array_map(fn($it) => self::key($it), $items));
        $dayOf = function (array $items) {
            $m = [];
            foreach ($items as $it) {
                $m[self::key($it)][] = $it['dayNo'] ?? null;
            }

            return $m;
        };
        $a = $count($snapItems);
        $b = $count($curItems);
        $add = $remove = [];
        foreach ($a as $k => $n) {
            for ($i = 0; $i < $n - ($b[$k] ?? 0); $i++) {
                $add[] = $names[$k] ?? 'Mục không rõ';
            }
        }
        foreach ($b as $k => $n) {
            for ($i = 0; $i < $n - ($a[$k] ?? 0); $i++) {
                $remove[] = $names[$k] ?? 'Mục không rõ';
            }
        }
        $moved = 0;
        $da = $dayOf($snapItems);
        $db = $dayOf($curItems);
        foreach ($da as $k => $list) {
            if (isset($db[$k]) && count($list) === count($db[$k])) {
                $x = $list;
                $y = $db[$k];
                sort($x);
                sort($y);
                $moved += $x === $y ? 0 : 1;
            }
        }
        $cur = DB::table('tbl_trips')->where('tripId', $tripId)->first();
        $trip = (array) ($p['trip'] ?? []);
        $meta = [];
        $labels = ['title' => 'Tên chuyến', 'startDate' => 'Ngày đi', 'days' => 'Số ngày', 'groupSize' => 'Số người', 'budget' => 'Ngân sách'];
        foreach ($labels as $c => $l) {
            if (array_key_exists($c, $trip) && $cur && property_exists($cur, $c) && (string) $trip[$c] !== (string) $cur->$c
                && !(is_numeric($trip[$c]) && is_numeric($cur->$c) && (float) $trip[$c] === (float) $cur->$c)) {
                $meta[] = $l . ': ' . self::fmt($c, $cur->$c) . ' → ' . self::fmt($c, $trip[$c]);
            }
        }

        return [
            'rev' => (int) $row->revisionNo, 'type' => $row->changeType, 'by' => $row->fullName ?: $row->username, 'at' => (string) $row->createdAt,
            'trip' => array_intersect_key($trip, $labels), 'days' => $dayList,
            'diff' => ['add' => $add, 'remove' => $remove, 'moved' => $moved, 'meta' => $meta, 'same' => !$add && !$remove && !$moved && !$meta],
        ];
    }

    private static function key(array $it): string
    {
        $t = (string) ($it['refType'] ?? '');

        return in_array($t, TripPlanner::CUSTOM_TYPES, true) || empty($it['refId'])
            ? $t . '#' . mb_strtolower(trim((string) ($it['label'] ?? '')))
            : $t . ':' . (int) $it['refId'];
    }

    private static function fmt(string $c, $v): string
    {
        if ($v === null || $v === '') {
            return 'trống';
        }
        if ($c === 'budget') {
            return number_format((float) $v, 0, ',', '.') . ' đ';
        }
        if ($c === 'startDate') {
            return date('d/m/Y', strtotime((string) $v));
        }

        return (string) $v;
    }

    /** Tên hiển thị cho mọi mục (kho Travela: tên hiện tại; tự nhập: tên người dùng ghi). @return array<string,string> */
    private function names(array $items): array
    {
        $refs = [];
        foreach ($items as $it) {
            if (in_array($it['refType'] ?? '', TripPlanner::TYPES, true) && !empty($it['refId'])) {
                $refs[] = ['type' => $it['refType'], 'refId' => (int) $it['refId']];
            }
        }
        $res = $refs ? app(TripPlanner::class)->resolve($refs) : [];
        $out = [];
        foreach ($items as $it) {
            $k = self::key($it);
            if (isset($out[$k])) {
                continue;
            }
            $r = $res[($it['refType'] ?? '') . ':' . (int) ($it['refId'] ?? 0)] ?? null;
            $out[$k] = $r['name'] ?? (trim((string) ($it['label'] ?? '')) !== '' ? trim((string) $it['label']) : 'Mục không còn trên Travela');
        }

        return $out;
    }

    /**
     * @return int rev mới sau khi khôi phục
     * @throws TripConflict khi $baseRev khác rev hiện tại
     */
    public function restore(int $userId, int $tripId, int $revisionNo, int $baseRev): ?int
    {
        if (!self::ready()) {
            return null;
        }

        return DB::transaction(function () use ($userId, $tripId, $revisionNo, $baseRev) {
            $cur = DB::table('tbl_trips')->where('tripId', $tripId)->lockForUpdate()->first(['rev']);
            if (!$cur) {
                return null;
            }
            if ((int) $cur->rev !== $baseRev) {
                throw new TripConflict((int) $cur->rev, app(TripSharing::class)->revision($tripId)['by']);
            }
            $row = DB::table('tbl_trip_revisions')->where('tripId', $tripId)->where('revisionNo', $revisionNo)->first();
            if (!$row) {
                return null;
            }
            $p = json_decode((string) $row->payload, true) ?: [];
            $upd = array_intersect_key((array) ($p['trip'] ?? []), array_flip(self::TRIP_FIELDS));
            foreach (array_keys($upd) as $c) {
                if (!Schema::hasColumn('tbl_trips', $c)) {
                    unset($upd[$c]);
                }
            }
            DB::table('tbl_trips')->where('tripId', $tripId)->update($upd + ['rev' => DB::raw('rev + 1'), 'lastEditorId' => $userId, 'updatedAt' => now()]);
            DB::table('tbl_trip_items')->where('tripId', $tripId)->delete();
            $cols = array_flip(Schema::getColumnListing('tbl_trip_items'));
            $rows = [];
            foreach ((array) ($p['items'] ?? []) as $it) {
                $rows[] = array_intersect_key(['tripId' => $tripId] + (array) $it, $cols);   // cột đã bỏ ở migration sau thì bỏ qua
            }
            if ($rows) {
                DB::table('tbl_trip_items')->insert($rows);
            }
            $this->record($tripId, $baseRev, $userId, 'restore', $rows);

            return (int) DB::table('tbl_trips')->where('tripId', $tripId)->value('rev');
        });
    }
}
