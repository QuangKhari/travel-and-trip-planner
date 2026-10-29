<?php

namespace App\Services\Planner;

use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\TravelAdvice;
use App\Services\EventDateService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Vì sao hợp" cho TỪNG mục của chuyến đi, kể cả mục người dùng tự thêm (không chỉ mục Planner tự chọn).
 * Mỗi câu suy ra từ dữ liệu thật của mục: mùa (monthFrom/monthTo), trong nhà/ngoài trời, mức tháng của điểm đến,
 * khí hậu tháng đó, ngày diễn ra của lễ hội. Không có dữ liệu thì không nói gì (không bịa lý do).
 *
 * classify() là hàm thuần để kiểm thử; forTrip() chỉ nạp dữ liệu rồi gọi classify().
 *
 * Kết quả mỗi mục: ['fit' => list<{k:'good'|'warn'|'info', x:string}>, 'outdoor' => ?bool, 'src' => ?string].
 */
final class ItemInsights
{
    /**
     * @param string $type  destination|place|food|event|experience|tour
     * @param array<string,mixed> $f   dữ liệu của mục: indoor, monthFrom, monthTo, difficulty, kind, level, levelNote, eventRanges (list<array{start,end}>), license
     * @param array<string,mixed> $ctx month (tháng chính), months (list<int>), rainy (bool), rainyDays (?float), destName (?string), indoorKinds (list<string>), outdoorKinds (list<string>), tripStart (?string Y-m-d), tripEnd (?string)
     * @return array{fit:list<array{k:string,x:string}>,outdoor:?bool,src:?string}
     */
    public static function classify(string $type, array $f, array $ctx): array
    {
        $fit = [];
        $outdoor = null;
        $months = array_values(array_filter((array) ($ctx['months'] ?? []), 'is_int'));
        $month = $ctx['month'] ?? ($months[0] ?? null);
        $where = !empty($ctx['destName']) ? ' ở ' . $ctx['destName'] : '';
        $rainy = !empty($ctx['rainy']);
        $rd = isset($ctx['rainyDays']) && $ctx['rainyDays'] !== null ? (int) round((float) $ctx['rainyDays']) : null;

        if ($type === 'experience') {
            if (array_key_exists('indoor', $f) && $f['indoor'] !== null) {
                $outdoor = !$f['indoor'];
            }
        } elseif ($type === 'place') {
            $kind = (string) ($f['kind'] ?? '');
            if (in_array($kind, (array) ($ctx['indoorKinds'] ?? []), true)) {
                $outdoor = false;
            } elseif (in_array($kind, (array) ($ctx['outdoorKinds'] ?? []), true)) {
                $outdoor = true;
            }
        }

        if (in_array($type, ['experience', 'place'], true) && $outdoor !== null) {
            if ($outdoor === false) {
                $fit[] = ['k' => $rainy ? 'good' : 'info', 'x' => $rainy && $month ? "Trong nhà, hợp tháng {$month}{$where} mưa nhiều" : 'Trong nhà'];
            } else {
                $fit[] = ['k' => $rainy ? 'warn' : 'info', 'x' => $rainy && $month
                    ? "Ngoài trời, tháng {$month}{$where} mưa nhiều" . ($rd ? " (khoảng {$rd} ngày mưa)" : '') . ', nên có phương án dự phòng'
                    : 'Ngoài trời'];
            }
        }

        if (in_array($type, ['experience', 'food'], true) && $month) {
            $in = DestinationMonthBrief::inSeason($f['monthFrom'] ?? null, $f['monthTo'] ?? null, (int) $month);
            if ($in !== null) {
                $range = (int) $f['monthFrom'] === (int) $f['monthTo'] ? 'Tháng ' . (int) $f['monthFrom'] : 'Tháng ' . (int) $f['monthFrom'] . '–' . (int) $f['monthTo'];
                $fit[] = $in
                    ? ['k' => 'good', 'x' => ($type === 'food' ? 'Đang vào mùa ngon' : 'Đúng mùa') . " ({$range})"]
                    : ['k' => 'warn', 'x' => ($type === 'food' ? 'Ngoài mùa ngon' : 'Ngoài mùa') . " ({$range}), tháng {$month} có thể không có hoặc không ngon bằng"];
            }
        }

        if ($type === 'experience' && ($f['difficulty'] ?? null) === 'hard') {
            $fit[] = ['k' => 'info', 'x' => 'Độ khó cao, cân nhắc sức khỏe cả nhóm'];
        }

        if ($type === 'destination' && !empty($f['level']) && $month) {
            if ($f['level'] === 'best') {
                $fit[] = ['k' => 'good', 'x' => "Tháng {$month} là mùa đẹp nhất" . (!empty($f['levelNote']) ? ': ' . rtrim((string) $f['levelNote'], '.') : '')];
            } elseif ($f['level'] === 'avoid') {
                $fit[] = ['k' => 'warn', 'x' => "Tháng {$month} thuộc mức nên cân nhắc" . (!empty($f['levelNote']) ? ': ' . rtrim((string) $f['levelNote'], '.') : '')];
            }
        }

        if ($type === 'event' && !empty($ctx['tripStart']) && !empty($ctx['tripEnd'])) {
            $hit = null;
            foreach ((array) ($f['eventRanges'] ?? []) as $r) {
                if ($r['start'] <= $ctx['tripEnd'] && $r['end'] >= $ctx['tripStart']) {
                    $hit = $r;
                    break;
                }
            }
            if ($hit) {
                $fit[] = ['k' => 'good', 'x' => 'Diễn ra đúng ngày bạn đi (' . self::dm($hit['start']) . ($hit['end'] !== $hit['start'] ? '–' . self::dm($hit['end']) : '') . ')'];
            }
        }

        return ['fit' => $fit, 'outdoor' => $outdoor, 'src' => self::src($f['license'] ?? null)];
    }

    /** "Tự viết" = do Travela biên tập; giấy phép khác thì ghi nguyên tên giấy phép. */
    public static function src(?string $license): ?string
    {
        $l = trim((string) $license);
        if ($l === '') {
            return null;
        }

        return $l === 'Tự viết' ? 'Biên tập Travela' : 'Nguồn: ' . $l;
    }

    private static function dm(string $ymd): string
    {
        return date('d/m', strtotime($ymd));
    }

    // ------------------------------------------------------------------ nạp dữ liệu

    /**
     * Ngữ cảnh thời gian của chuyến: các tháng đi, tháng chính, ngày đầu/cuối. Rỗng nếu chuyến chưa có ngày/tháng.
     *
     * @return array{months:list<int>,month:?int,tripStart:?string,tripEnd:?string}
     */
    public static function timeContext(object $trip): array
    {
        $days = max(1, (int) ($trip->days ?: 1));
        if (!empty($trip->startDate)) {
            $s = new DateTimeImmutable($trip->startDate);
            $e = $s->modify('+' . ($days - 1) . ' days');
            $months = [];
            for ($c = $s; $c <= $e; $c = $c->modify('+1 day')) {
                $months[(int) $c->format('n')] = true;
            }
            $months = array_keys($months);

            return ['months' => $months, 'month' => (int) $s->format('n'), 'tripStart' => $s->format('Y-m-d'), 'tripEnd' => $e->format('Y-m-d')];
        }
        $m = DestinationMonthBrief::validMonth($trip->month ?? null);

        return ['months' => $m ? [$m] : [], 'month' => $m, 'tripStart' => null, 'tripEnd' => null];
    }

    /**
     * @param list<array<string,mixed>> $items  mục của chuyến (từ TripPlanner::tripItems): type, refId, destinationId, destName
     * @return array<string,array{fit:list<array{k:string,x:string}>,outdoor:?bool,src:?string}>  khóa "type:refId"
     */
    public function forTrip(object $trip, array $items, ?EventDateService $dates = null): array
    {
        $time = self::timeContext($trip);
        $by = [];
        foreach ($items as $it) {
            if (!empty($it['refId']) && in_array($it['type'], ['destination', 'place', 'food', 'event', 'experience'], true)) {
                $by[$it['type']][(int) $it['refId']] = $it;
            }
        }
        if (!$by) {
            return [];
        }

        $rainy = $this->rainByDestination(array_values(array_unique(array_filter(array_map(fn($i) => $i['destinationId'] ?? null, $items)))), $time['month']);
        $indoor = (array) config('planner.indoor_place_kinds', []);
        $outdoorKinds = array_values(array_unique(array_merge(
            (array) config('planner.dislikes.outdoor.place_kinds', []),
            ['peak', 'beach', 'viewpoint', 'cave_entrance', 'hot_spring']
        )));
        $out = [];
        $mk = function (string $type, int $id, array $facts, ?int $destId) use (&$out, $time, $rainy, $indoor, $outdoorKinds, $by) {
            $it = $by[$type][$id] ?? [];
            $r = $destId !== null ? ($rainy[$destId] ?? null) : null;
            $ctx = $time + [
                'rainy' => $r['rainy'] ?? false, 'rainyDays' => $r['rainyDays'] ?? null,
                'destName' => $it['destName'] ?? null, 'indoorKinds' => $indoor, 'outdoorKinds' => $outdoorKinds,
            ];
            $out[$type . ':' . $id] = self::classify($type, $facts, $ctx);
        };

        if (!empty($by['experience'])) {
            foreach (DB::table('tbl_experiences')->whereIn('experienceId', array_keys($by['experience']))->get() as $x) {
                $mk('experience', (int) $x->experienceId, ['indoor' => $x->indoor === null ? null : (bool) $x->indoor, 'monthFrom' => $x->monthFrom, 'monthTo' => $x->monthTo,
                    'difficulty' => $x->difficulty, 'license' => $x->license], $x->destinationId ? (int) $x->destinationId : null);
            }
        }
        if (!empty($by['place'])) {
            foreach (DB::table('tbl_external_places')->whereIn('placeId', array_keys($by['place']))->get(['placeId', 'kind', 'destinationId']) as $p) {
                $mk('place', (int) $p->placeId, ['kind' => $p->kind], (int) $p->destinationId);
            }
        }
        if (!empty($by['food'])) {
            foreach (DB::table('tbl_foods')->whereIn('foodId', array_keys($by['food']))->get(['foodId', 'monthFrom', 'monthTo', 'license', 'destinationId']) as $f) {
                $mk('food', (int) $f->foodId, ['monthFrom' => $f->monthFrom, 'monthTo' => $f->monthTo, 'license' => $f->license], $f->destinationId ? (int) $f->destinationId : null);
            }
        }
        if (!empty($by['event'])) {
            $dates ??= new EventDateService();
            $from = $time['tripStart'] ? new DateTimeImmutable($time['tripStart']) : null;
            foreach (DB::table('tbl_events')->whereIn('eventId', array_keys($by['event']))->get() as $e) {
                $ranges = $from ? $dates->upcoming($e, $from, 4) : [];
                $mk('event', (int) $e->eventId, ['eventRanges' => $ranges, 'license' => $e->license], $e->destinationId ? (int) $e->destinationId : null);
            }
        }
        if (!empty($by['destination']) && $time['month']) {
            $levels = (new TravelAdvice())->verdicts(array_keys($by['destination']), (int) $time['month']);
            foreach (array_keys($by['destination']) as $id) {
                $mk('destination', $id, ['level' => $levels[$id]['level'] ?? null, 'levelNote' => $levels[$id]['note'] ?? null], $id);
            }
        }

        return $out;
    }

    /**
     * Khí hậu tháng chính theo điểm đến: mưa nhiều khi số ngày mưa / số ngày trong tháng >= config('planner.rainy_share').
     *
     * @param list<int> $destIds
     * @return array<int,array{rainy:bool,rainyDays:float}>
     */
    public function rainByDestination(array $destIds, ?int $month): array
    {
        if (!$destIds || !$month) {
            return [];
        }
        $dim = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31][$month];
        $share = (float) config('planner.rainy_share', 0.4);
        $out = [];
        foreach (DB::table('tbl_destination_climate')->whereIn('destinationId', $destIds)->where('month', $month)->get(['destinationId', 'rainyDays']) as $c) {
            if ($c->rainyDays === null) {
                continue;
            }
            $out[(int) $c->destinationId] = ['rainy' => ((float) $c->rainyDays / $dim) >= $share, 'rainyDays' => (float) $c->rainyDays];
        }

        return $out;
    }
}
