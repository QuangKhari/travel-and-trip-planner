<?php

namespace App\Services\Planner;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giờ mở cửa của địa điểm / trải nghiệm (bảng tbl_opening_hours).
 *
 * Dạng gửi cho Planner (cả máy chủ lẫn trình duyệt, hợp đồng chung):
 *   hours = {"1": [[480, 1020]], "2": [], ...}   khóa = thứ ISO (1 = Thứ Hai ... 7 = Chủ nhật)
 *     - mảng khoảng [mở, đóng] (phút kể từ 00:00), có thể nhiều khoảng (nghỉ trưa);
 *     - mảng rỗng = đóng cửa cả ngày;
 *     - thiếu khóa = chưa biết, không cảnh báo.
 *
 * Kiểm tra một mục [start, end) trong ngày có thứ $dow (check()):
 *   closed (đóng cả ngày) | early (tới trước giờ mở) | late (còn ở lại sau giờ đóng) | null (ổn hoặc chưa biết)
 * Xếp giờ tự động (fit()): mục không cố định giờ tới sớm thì chờ tới giờ mở của khoảng đầu tiên còn đủ thời gian.
 *
 * Phạm vi hỗ trợ (giới hạn có chủ đích của phiên bản đồ án):
 *   A. một khoảng "08:00-17:00"     B. nhiều khoảng (nghỉ trưa) "08:00-12:00,13:30-18:00"
 *   C. "24/7"                       D. đóng cả ngày "Mo off"
 *   E. qua nửa đêm "18:00-02:00": CHƯA hỗ trợ — chỉ tính phần trong ngày (18:00-24:00), phần sau 00:00 bị bỏ.
 *   Chưa xử lý ngày lễ (PH), giờ theo mùa, đóng cửa tạm thời.
 */
final class OpeningHours
{
    public const TYPES = ['place', 'experience'];
    private const DAYS = ['mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4, 'fr' => 5, 'sa' => 6, 'su' => 7];

    /**
     * Giờ mở cửa cho nhiều mục một lần.
     * @param list<array{type:string,refId:?int}> $items
     * @return array<string,array<string,list<array{0:int,1:int}>>> khóa "type:refId"
     */
    public function forItems(array $items): array
    {
        if (!Schema::hasTable('tbl_opening_hours')) {
            return [];
        }
        $by = [];
        foreach ($items as $it) {
            if (in_array($it['type'] ?? '', self::TYPES, true) && !empty($it['refId'])) {
                $by[$it['type']][] = (int) $it['refId'];
            }
        }
        $out = [];
        foreach ($by as $type => $ids) {
            $rows = DB::table('tbl_opening_hours')->where('refType', $type)->whereIn('refId', array_unique($ids))
                ->orderBy('dayOfWeek')->orderBy('openMin')->get();
            foreach ($rows as $r) {
                $k = $type . ':' . $r->refId;
                $d = (string) $r->dayOfWeek;
                $out[$k][$d] = $out[$k][$d] ?? [];
                if (!$r->isClosed && $r->openMin !== null && $r->closeMin !== null) {
                    $out[$k][$d][] = [(int) $r->openMin, (int) $r->closeMin];
                }
            }
        }

        return $out;
    }

    /**
     * Quy tắc theo ngày (đóng tạm thời, giờ theo mùa) cho các mục và điểm đến trong khoảng [from, to].
     * @param list<array{type:string,refId:?int,destinationId?:?int}> $items
     * @return list<object>
     */
    public function rulesFor(array $items, string $from, string $to): array
    {
        if (!Schema::hasTable('tbl_hours_rules')) {
            return [];
        }
        $refs = [];
        $dests = [];
        foreach ($items as $it) {
            if (in_array($it['type'] ?? '', self::TYPES, true) && !empty($it['refId'])) {
                $refs[$it['type']][] = (int) $it['refId'];
            }
            if (!empty($it['destinationId'])) {
                $dests[] = (int) $it['destinationId'];
            }
        }
        if (!$refs && !$dests) {
            return [];
        }

        return DB::table('tbl_hours_rules')->where(function ($q) use ($refs, $dests) {
            foreach ($refs as $type => $ids) {
                $q->orWhere(fn($w) => $w->where('refType', $type)->whereIn('refId', array_unique($ids)));
            }
            if ($dests) {
                $q->orWhere(fn($w) => $w->whereNull('refType')->whereIn('destinationId', array_unique($dests)));
            }
        })->where(fn($q) => $q->where('yearly', 1)->orWhere(fn($w) => $w->where('dateFrom', '<=', $to)->where('dateTo', '>=', $from)))
            ->orderBy('ruleId')->get()->all();
    }

    /**
     * Giờ mở cửa THỰC của một mục vào một ngày cụ thể: giờ theo thứ, rồi ngày lễ (khóa "8"), rồi quy tắc của điểm đến,
     * rồi quy tắc riêng của nơi đó (cụ thể thắng chung). Chỉ thay giờ của đúng thứ của ngày đó.
     * @param ?array<string,list<array{0:int,1:int}>> $hours
     * @param list<object> $rules  từ rulesFor()
     * @return array{0:?array,1:?string} [giờ đã áp dụng, lý do khác thường (null = như mọi tuần)]
     */
    public static function effective(?array $hours, string $type, ?int $refId, ?int $destId, string $date, array $rules): array
    {
        $dow = (string) (int) date('N', strtotime($date));
        $why = null;
        if ($hours !== null && array_key_exists('8', $hours) && ($h = Holidays::name($date))) {
            $hours[$dow] = $hours['8'];
            $why = $h . ($hours['8'] ? ': giờ ngày lễ' : ': nghỉ lễ');
        }
        $md = substr($date, 5, 5);
        $hit = function (object $r) use ($date, $md): bool {
            if (!empty($r->yearly)) {
                $a = substr((string) $r->dateFrom, 5, 5);
                $b = substr((string) $r->dateTo, 5, 5);

                return $a <= $b ? ($md >= $a && $md <= $b) : ($md >= $a || $md <= $b);   // khoảng vắt qua năm mới
            }

            return $date >= substr((string) $r->dateFrom, 0, 10) && $date <= substr((string) $r->dateTo, 0, 10);
        };
        $apply = function (object $r) use (&$hours, &$why, $dow): void {
            $hours ??= [];
            $hours[$dow] = $r->kind === 'closed' ? [] : [[(int) $r->openMin, (int) $r->closeMin]];
            $why = ($r->kind === 'closed' ? 'Đóng cửa tạm thời' : 'Giờ theo mùa ' . sprintf('%02d:%02d–%02d:%02d', intdiv((int) $r->openMin, 60), (int) $r->openMin % 60, intdiv((int) $r->closeMin, 60), (int) $r->closeMin % 60))
                . ($r->note ? ': ' . $r->note : '');
        };
        foreach ($rules as $r) {
            if ($r->refType === null && $destId !== null && (int) $r->destinationId === $destId && $hit($r)) {
                $apply($r);
            }
        }
        foreach ($rules as $r) {
            if ($r->refType === $type && $refId !== null && (int) $r->refId === $refId && $hit($r)) {
                $apply($r);
            }
        }

        return [$hours, $why];
    }

    /** Ghi đè giờ mở cửa của một mục từ chuỗi kiểu OpenStreetMap. @return int số dòng đã ghi */
    public function store(string $type, int $refId, string $osm, string $source = 'manual'): int
    {
        $parsed = self::parseOsm($osm);
        DB::table('tbl_opening_hours')->where('refType', $type)->where('refId', $refId)->delete();
        $rows = [];
        foreach ($parsed as $dow => $ranges) {
            if (!$ranges) {
                $rows[] = ['refType' => $type, 'refId' => $refId, 'dayOfWeek' => (int) $dow, 'openMin' => null, 'closeMin' => null, 'isClosed' => true, 'source' => $source, 'updatedAt' => now()];
            }
            foreach ($ranges as [$o, $c]) {
                $rows[] = ['refType' => $type, 'refId' => $refId, 'dayOfWeek' => (int) $dow, 'openMin' => $o, 'closeMin' => $c, 'isClosed' => false, 'source' => $source, 'updatedAt' => now()];
            }
        }
        if ($rows) {
            DB::table('tbl_opening_hours')->insert($rows);
        }

        return count($rows);
    }

    /**
     * Đọc phần phổ biến của cú pháp opening_hours (OpenStreetMap):
     *   "24/7" · "Mo-Fr 08:00-17:00" · "Tu-Su 08:00-11:30,13:30-17:00; Mo off" · "Mo,We,Fr 09:00-18:00" · "Sa-Su 07:00-22:00"
     * Phần không hiểu (PH, tháng, tuần...) bị bỏ qua. Khoảng qua nửa đêm (18:00-02:00) cắt tại 24:00.
     * @return array<string,list<array{0:int,1:int}>>
     */
    public static function parseOsm(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        if (strtolower($s) === '24/7') {
            return array_fill_keys(['1', '2', '3', '4', '5', '6', '7'], [[0, 1440]]);
        }
        $out = [];
        foreach (preg_split('/\s*;\s*/', $s) as $rule) {
            if (!preg_match('/^((?:[A-Za-z]{2}(?:-[A-Za-z]{2})?)(?:\s*,\s*[A-Za-z]{2}(?:-[A-Za-z]{2})?)*)?\s*(.*)$/', $rule, $m)) {
                continue;
            }
            $days = [];
            $spec = trim($m[1] ?? '');
            if ($spec === '') {
                $days = [1, 2, 3, 4, 5, 6, 7];
            } else {
                foreach (preg_split('/\s*,\s*/', strtolower($spec)) as $part) {
                    if ($part === 'ph') {   // ngày lễ: lưu ở dayOfWeek = 8, áp dụng theo Holidays khi biết ngày cụ thể
                        $days[] = 8;
                        continue;
                    }
                    $ab = explode('-', $part);
                    $a = self::DAYS[$ab[0]] ?? null;
                    $b = isset($ab[1]) ? (self::DAYS[$ab[1]] ?? null) : $a;
                    if ($a === null || $b === null) {
                        continue 2;   // "PH", "Jan"...: bỏ cả quy tắc
                    }
                    for ($d = $a; ; $d = $d % 7 + 1) {
                        $days[] = $d;
                        if ($d === $b) {
                            break;
                        }
                    }
                }
            }
            $times = strtolower(trim($m[2]));
            $ranges = [];
            if ($times !== 'off' && $times !== 'closed') {
                if (!preg_match_all('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $times, $mm, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($mm as $t) {
                    $o = (int) $t[1] * 60 + (int) $t[2];
                    $c = (int) $t[3] * 60 + (int) $t[4];
                    if ($c <= $o) {
                        $c = 1440;
                    }
                    $ranges[] = [$o, min(1440, $c)];
                }
            }
            foreach ($days as $d) {
                $out[(string) $d] = $ranges;   // quy tắc sau ghi đè quy tắc trước (như OSM)
            }
        }
        ksort($out);

        return $out;
    }

    /** Thứ ISO (1-7) của ngày thứ $day trong chuyến; null khi chuyến chưa có ngày đi. */
    public static function dowOf(?string $startDate, int $day): ?int
    {
        if (!$startDate) {
            return null;
        }

        return (int) (new DateTimeImmutable($startDate))->modify('+' . ($day - 1) . ' days')->format('N');
    }

    /** closed | early | late | null. */
    public static function check(?array $hours, ?int $dow, int $start, int $end): ?string
    {
        if (!$hours || $dow === null || !array_key_exists((string) $dow, $hours)) {
            return null;
        }
        $ranges = $hours[(string) $dow];
        if (!$ranges) {
            return 'closed';
        }
        foreach ($ranges as [$o, $c]) {
            if ($start >= $o && $end <= $c) {
                return null;
            }
        }
        foreach ($ranges as [$o, $c]) {
            if ($start < $o) {
                return 'early';
            }
            if ($start < $c) {
                return 'late';
            }
        }

        return 'late';
    }

    /** Giờ bắt đầu sớm nhất >= $start để ở trọn $dur phút trong một khoảng mở; null nếu không còn khoảng nào đủ. */
    public static function fit(?array $hours, ?int $dow, int $start, int $dur): ?int
    {
        if (!$hours || $dow === null || empty($hours[(string) $dow])) {
            return null;
        }
        foreach ($hours[(string) $dow] as [$o, $c]) {
            $s = max($start, $o);
            if ($s + $dur <= $c) {
                return $s;
            }
        }

        return null;
    }

    /** Mô tả giờ trong một thứ, ví dụ "08:00–11:30, 13:30–17:00" hoặc "đóng cửa". */
    public static function describe(?array $hours, ?int $dow): ?string
    {
        if (!$hours || $dow === null || !array_key_exists((string) $dow, $hours)) {
            return null;
        }
        $r = $hours[(string) $dow];
        if (!$r) {
            return 'đóng cửa';
        }

        return implode(', ', array_map(fn($x) => sprintf('%02d:%02d–%02d:%02d', intdiv($x[0], 60), $x[0] % 60, intdiv($x[1], 60), $x[1] % 60), $r));
    }
}
