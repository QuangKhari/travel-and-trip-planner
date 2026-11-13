<?php

namespace App\Services\Planner;

use App\Services\DepartureService;
use Illuminate\Support\Facades\DB;

/**
 * A-01 AI Tour Match + A-05 banner "có tour giống lịch trình này".
 *
 * Mỗi tour được chấm 4 điểm thành phần trong [0, 1] rồi cộng có trọng số (config planner.tour_match.weights):
 *
 *   Match = 0.35 × Ngân sách + 0.30 × Sở thích + 0.20 × Di chuyển (thời gian) + 0.15 × Nhóm
 *
 *   Ngân sách  : giá cả nhóm (người lớn × giá NL + trẻ em × giá TE) so với ngân sách. ≤ ngân sách = 1, giảm tuyến tính về 0 khi vượt 50 %.
 *   Sở thích   : 60 % tỷ lệ sở thích của chuyến có từ khóa trong tên / mô tả / lộ trình tour (config/interests.php)
 *                + 40 % tỷ lệ địa điểm đã xếp trong chuyến được lộ trình tour nhắc tới. Chỉ có một phần thì dùng phần đó.
 *   Di chuyển  : thời gian chuyến đi khớp tới đâu: 50 % độ lệch ngày khởi hành (0 ngày = 1, ≥ max_day_gap = 0)
 *                + 50 % độ lệch số ngày (|tour − chuyến| / số ngày chuyến). Chỉ biết tháng: đúng tháng = 1, khác tháng = 0.
 *   Nhóm       : ngày khởi hành tốt nhất còn đủ chỗ = 1, thiếu thì = số chỗ còn / số người.
 *
 * Thành phần chưa đủ dữ liệu (không nhập ngân sách, không có sở thích lẫn địa điểm...) nhận giá trị TRUNG TÍNH 0.5 và
 * được đánh dấu `known = false` để giao diện ghi "chưa đủ dữ liệu" thay vì giả vờ chắc chắn.
 * Không gọi LLM: mọi con số đều tính lại được từ dữ liệu tour và chuyến (giải thích được trong báo cáo).
 */
final class TourMatch
{
    public const COMPONENTS = ['budget', 'interest', 'travel', 'group'];

    public const LABELS = ['budget' => 'Ngân sách', 'interest' => 'Sở thích & lịch trình', 'travel' => 'Thời gian di chuyển', 'group' => 'Nhóm & chỗ trống'];

    /** @return array{budget:float,interest:float,travel:float,group:float} */
    public static function weights(): array
    {
        $w = (array) config('planner.tour_match.weights', []);

        return [
            'budget' => (float) ($w['budget'] ?? 0.35), 'interest' => (float) ($w['interest'] ?? 0.30),
            'travel' => (float) ($w['travel'] ?? 0.20), 'group' => (float) ($w['group'] ?? 0.15),
        ];
    }

    public static function threshold(): float
    {
        return (float) config('planner.tour_match.banner_min', 0.6);
    }

    /**
     * Hàm thuần: chấm một tour cho một nhu cầu.
     *
     * @param array{startDate:?string,month:?int,days:int,adults:int,children:int,budget:?float,interests:list<string>,placeNames:list<string>} $want
     * @param array{startDate:string,days:?int,remaining:int,priceAdult:float,priceChild:?float,text:string} $tour  text = tên + mô tả + lộ trình
     * @return array{score:float,pct:int,components:array<string,array{value:float,weight:float,known:bool,label:string,note:string}>,matchedInterests:list<string>,matchedPlaces:list<string>,forGroup:float}
     */
    public static function score(array $want, array $tour, ?array $interestDefs = null, ?int $maxGap = null): array
    {
        $w = self::weights();
        $maxGap ??= (int) config('planner.tour_match.max_day_gap', 14);
        $interestDefs ??= (array) config('interests');
        $adults = max(1, (int) $want['adults']);
        $children = max(0, (int) ($want['children'] ?? 0));
        $people = $adults + $children;
        $c = [];

        // 1. Ngân sách
        $forGroup = (float) $tour['priceAdult'] * $adults + (float) ($tour['priceChild'] ?? $tour['priceAdult']) * $children;
        if (!empty($want['budget']) && (float) $want['budget'] > 0) {
            $ratio = $forGroup / (float) $want['budget'];
            $v = $ratio <= 1 ? 1.0 : max(0.0, 1 - ($ratio - 1) / 0.5);
            $note = $ratio <= 1 ? 'Giá cả nhóm ' . self::money($forGroup) . ', trong ngân sách'
                : 'Giá cả nhóm ' . self::money($forGroup) . ', vượt ngân sách ' . self::money($forGroup - (float) $want['budget']);
            $c['budget'] = ['value' => $v, 'known' => true, 'note' => $note];
        } else {
            $c['budget'] = ['value' => 0.5, 'known' => false, 'note' => 'Chưa nhập ngân sách · giá cả nhóm ' . self::money($forGroup)];
        }

        // 2. Sở thích + địa điểm trùng với lộ trình tour
        $hay = PlannerEngine::normalize((string) $tour['text']);
        $hitI = [];
        $asked = array_values(array_filter(array_unique((array) ($want['interests'] ?? [])), fn($k) => isset($interestDefs[$k])));
        foreach ($asked as $k) {
            $def = $interestDefs[$k];
            foreach (array_merge((array) ($def['keywords'] ?? []), (array) ($def['aliases'] ?? [])) as $kw) {
                $kw = trim((string) $kw);
                if ($kw !== '' && (str_contains($hay, ' ' . $kw . ' ') || (strlen($kw) >= 4 && str_contains($hay, $kw)))) {
                    $hitI[] = (string) ($def['label'] ?? $k);
                    break;
                }
            }
        }
        $names = [];
        foreach ((array) ($want['placeNames'] ?? []) as $n) {
            $n = trim(PlannerEngine::normalize((string) $n));
            if (mb_strlen($n) >= 4) {
                $names[$n] = (string) $n;
            }
        }
        $hitP = [];
        foreach ((array) ($want['placeNames'] ?? []) as $orig) {
            $n = trim(PlannerEngine::normalize((string) $orig));
            if (mb_strlen($n) >= 4 && str_contains($hay, ' ' . $n . ' ') && !in_array((string) $orig, $hitP, true)) {
                $hitP[] = (string) $orig;
            }
        }
        $parts = [];
        if ($asked) {
            $parts[] = [0.6, count($hitI) / count($asked)];
        }
        if ($names) {
            $parts[] = [0.4, min(1.0, count($hitP) / count($names))];
        }
        if ($parts) {
            $sumW = array_sum(array_column($parts, 0));
            $v = array_sum(array_map(fn($p) => $p[0] * $p[1], $parts)) / $sumW;
            $bits = [];
            if ($asked) {
                $bits[] = count($hitI) . '/' . count($asked) . ' sở thích' . ($hitI ? ' (' . implode(', ', $hitI) . ')' : '');
            }
            if ($names) {
                $bits[] = count($hitP) . '/' . count($names) . ' địa điểm của bạn có trong lộ trình tour';
            }
            $c['interest'] = ['value' => $v, 'known' => true, 'note' => implode(' · ', $bits)];
        } else {
            $c['interest'] = ['value' => 0.5, 'known' => false, 'note' => 'Chuyến chưa có sở thích hay địa điểm để so với lộ trình tour'];
        }

        // 3. Thời gian di chuyển: ngày khởi hành + số ngày
        $td = $tour['days'] ?? null;
        $daysV = null;
        if ($td !== null && (int) $want['days'] > 0) {
            $daysV = max(0.0, 1 - abs((int) $td - (int) $want['days']) / max(1, (int) $want['days']));
        }
        $dateV = null;
        $dateNote = null;
        if (!empty($want['startDate'])) {
            $gap = (int) round((strtotime($tour['startDate']) - strtotime($want['startDate'])) / 86400);
            $dateV = max(0.0, 1 - abs($gap) / max(1, $maxGap));
            $dateNote = $gap === 0 ? 'khởi hành đúng ngày bạn chọn' : 'khởi hành ' . ($gap > 0 ? 'sau' : 'trước') . ' ' . abs($gap) . ' ngày';
        } elseif (!empty($want['month'])) {
            $same = (int) date('n', strtotime($tour['startDate'])) === (int) $want['month'];
            $dateV = $same ? 1.0 : 0.0;
            $dateNote = $same ? 'khởi hành trong tháng ' . (int) $want['month'] : 'khởi hành tháng ' . (int) date('n', strtotime($tour['startDate']));
        }
        $tParts = array_values(array_filter([$dateV, $daysV], fn($x) => $x !== null));
        if ($tParts) {
            $note = trim(implode(' · ', array_filter([$dateNote ? ucfirst($dateNote) : null,
                $td !== null ? "tour {$td} ngày / chuyến {$want['days']} ngày" : null])));
            $c['travel'] = ['value' => array_sum($tParts) / count($tParts), 'known' => true, 'note' => $note];
        } else {
            $c['travel'] = ['value' => 0.5, 'known' => false, 'note' => 'Chưa rõ ngày đi và số ngày của tour'];
        }

        // 4. Nhóm
        $rem = max(0, (int) $tour['remaining']);
        $c['group'] = ['value' => $rem >= $people ? 1.0 : $rem / $people, 'known' => true,
            'note' => $rem >= $people ? "Còn {$rem} chỗ, đủ cho {$people} người" : "Chỉ còn {$rem} chỗ cho {$people} người"];

        $score = 0.0;
        foreach (self::COMPONENTS as $k) {
            $c[$k]['value'] = round($c[$k]['value'], 3);
            $c[$k]['weight'] = $w[$k];
            $c[$k]['label'] = self::LABELS[$k];
            $score += $w[$k] * $c[$k]['value'];
        }
        $score = round($score / max(1e-9, array_sum($w)), 3);

        return ['score' => $score, 'pct' => (int) round($score * 100), 'components' => $c, 'matchedInterests' => $hitI, 'matchedPlaces' => $hitP, 'forGroup' => $forGroup,
            'dateFit' => $dateV, 'hasPlaces' => (bool) $names];
    }

    /**
     * Nhu cầu suy ra từ chuyến đi (dùng cho trang sửa chuyến và banner A-05).
     *
     * @param list<array<string,mixed>> $items mọi mục của chuyến (đã xếp ngày + "Đã lưu")
     */
    public static function wantFromTrip(object $trip, array $items = []): array
    {
        $names = [];
        $inTrip = [];
        foreach ($items as $it) {
            $type = (string) ($it['type'] ?? $it['refType'] ?? '');
            // "Địa điểm của tôi" (ghi chú có vị trí trên bản đồ) cũng là một điểm trong lịch
            $mine = $type === 'note' && isset($it['lat']) && $it['lat'] !== null;
            if ((in_array($type, ['place', 'food', 'experience', 'event'], true) || $mine) && !empty($it['name'])) {
                $names[] = (string) $it['name'];
            }
            if ($type === 'tour' && !empty($it['refId'])) {
                $inTrip[] = (int) $it['refId'];
            }
        }
        $interests = array_values(array_filter((array) json_decode((string) ($trip->interests ?? ''), true), 'is_string'));

        return [
            'startDate' => $trip->startDate ?: null, 'month' => !empty($trip->month) ? (int) $trip->month : null,
            'days' => max(1, (int) ($trip->days ?? 1)), 'adults' => max(1, (int) ($trip->groupSize ?? 1)), 'children' => 0,
            'budget' => $trip->budget !== null ? (float) $trip->budget : null,
            'interests' => $interests, 'placeNames' => array_values(array_unique($names)), 'tripId' => (int) $trip->tripId,
            'tourIdsInTrip' => array_values(array_unique($inTrip)),
        ];
    }

    /**
     * Top-N tour cho nhu cầu $want ở các điểm đến $destIds. Tour phải đang bán, còn ít nhất một ngày khởi hành mở.
     * Mỗi kết quả kèm ngày khởi hành tốt nhất (gần ngày đi nhất và còn đủ chỗ) và đường dẫn đặt tour điền sẵn.
     *
     * @param list<int> $destIds
     * @return list<array<string,mixed>>
     */
    public function rank(array $want, array $destIds, ?int $limit = null): array
    {
        $destIds = array_values(array_unique(array_filter(array_map('intval', $destIds))));
        if (!$destIds) {
            return [];
        }
        $limit ??= (int) config('planner.tour_match.limit', 3);
        $people = max(1, (int) $want['adults']) + max(0, (int) ($want['children'] ?? 0));
        $tours = DB::table('tbl_tours as t')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->whereIn('t.destinationId', $destIds)->where('t.availability', 1)->whereDate('t.endDate', '>=', date('Y-m-d'))
            ->orderBy('t.startDate')->limit((int) config('planner.tour_match.scan', 40))
            ->get(['t.tourId', 't.title', 't.time', 't.description', 't.startDate', 't.endDate', 't.quantity', 't.priceAdult', 't.priceChild', 'd.name as destName']);
        if ($tours->isEmpty()) {
            return [];
        }
        $timeline = DB::table('tbl_timeline')->whereIn('tourId', $tours->pluck('tourId'))->get(['tourId', 'title', 'description'])->groupBy('tourId');
        $deps = app(DepartureService::class);
        $out = [];
        foreach ($tours as $t) {
            $dep = $this->bestDeparture($deps, $t, $want, $people);
            if ($dep === null) {
                continue;
            }
            $text = $t->title . ' ' . strip_tags((string) $t->description);
            foreach ($timeline->get($t->tourId, collect()) as $tl) {
                $text .= ' ' . $tl->title . ' ' . strip_tags((string) $tl->description);
            }
            $days = TourFit::tourDays($t->time, $t->startDate, $t->endDate);
            $s = self::score($want, ['startDate' => $dep['date'], 'days' => $days, 'remaining' => $dep['remaining'],
                'priceAdult' => (float) $t->priceAdult, 'priceChild' => $t->priceChild !== null ? (float) $t->priceChild : null, 'text' => $text]);
            $q = array_filter(['availabilityId' => $dep['availabilityId'], 'adults' => max(1, (int) $want['adults']),
                'children' => (int) ($want['children'] ?? 0) ?: null, 'tripId' => !empty($want['tripId']) ? (int) $want['tripId'] : null]);
            $out[] = $s + [
                'tourId' => (int) $t->tourId, 'title' => (string) $t->title, 'destName' => (string) $t->destName, 'time' => (string) $t->time,
                'days' => $days, 'departureDate' => $dep['date'], 'start' => date('d/m/Y', strtotime($dep['date'])), 'availabilityId' => $dep['availabilityId'],
                'remaining' => $dep['remaining'], 'enoughSeats' => $dep['remaining'] >= $people,
                'priceAdult' => (float) $t->priceAdult, 'inTrip' => in_array((int) $t->tourId, (array) ($want['tourIdsInTrip'] ?? []), true),
                'url' => route('tour-detail', ['id' => $t->tourId]),
                'bookUrl' => route('booking', ['id' => $t->tourId] + $q),
            ];
        }
        usort($out, fn($a, $b) => [$b['score'], $a['departureDate'], $a['tourId']] <=> [$a['score'], $b['departureDate'], $b['tourId']]);

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Banner A-05 "có tour giống lịch trình này": tour tốt nhất thỏa CẢ BỐN điều kiện
     *  1. Match ≥ ngưỡng (0.6);
     *  2. còn đủ chỗ cho cả nhóm (không thì bấm vào cũng không đặt được);
     *  3. chuyến đã có ngày đi thì tour khởi hành trong ± max_day_gap ngày (điểm ngày > 0);
     *  4. chuyến đã xếp địa điểm thì lộ trình tour phải nhắc ít nhất một địa điểm đó, hoặc điểm sở thích ≥ 0.5.
     * Điều kiện 3–4 giữ cho chữ "giống lịch trình" đúng nghĩa: điểm cao nhờ giá rẻ mà đi khác hẳn thì không hiện banner.
     */
    public static function banner(array $ranked): ?array
    {
        foreach ($ranked as $r) {
            $similar = !($r['hasPlaces'] ?? false) || $r['matchedPlaces'] || ($r['components']['interest']['value'] ?? 0) >= 0.5;
            if ($r['score'] >= self::threshold() && $r['enoughSeats'] && ($r['dateFit'] === null || $r['dateFit'] > 0) && $similar) {
                return $r;
            }
        }

        return null;
    }

    /** @return array{availabilityId:?int,date:string,remaining:int}|null */
    private function bestDeparture(DepartureService $deps, object $t, array $want, int $people): ?array
    {
        $list = DepartureService::enabled() ? $deps->forTour((int) $t->tourId, true) : [];
        $cands = [];
        foreach ($list as $d) {
            if ($d->bookable) {
                $cands[] = ['availabilityId' => (int) $d->availabilityId, 'date' => (string) $d->departureDate, 'remaining' => (int) $d->remaining];
            }
        }
        if (!$list && (int) $t->quantity > 0 && $t->startDate > date('Y-m-d')) {
            $cands[] = ['availabilityId' => null, 'date' => (string) $t->startDate, 'remaining' => (int) $t->quantity];
        }
        if (!$cands) {
            return null;
        }
        $ref = $want['startDate'] ?? null;
        usort($cands, function ($a, $b) use ($ref, $people) {
            $ea = $a['remaining'] >= $people ? 0 : 1;
            $eb = $b['remaining'] >= $people ? 0 : 1;
            $ga = $ref ? abs(strtotime($a['date']) - strtotime($ref)) : 0;
            $gb = $ref ? abs(strtotime($b['date']) - strtotime($ref)) : 0;

            return [$ea, $ga, $a['date']] <=> [$eb, $gb, $b['date']];
        });

        return $cands[0];
    }

    private static function money(float $n): string
    {
        return number_format($n, 0, ',', '.') . 'đ';
    }
}
