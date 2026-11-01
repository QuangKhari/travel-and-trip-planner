<?php

namespace App\Services\Planner;

use App\Services\Content\TravelAdvice;
use App\Services\EventDateService;
use App\Services\Planner\Route\RouteService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read model của một chuyến đi (trước đây là TripPlanner::payload()).
 *
 * Chỉ ĐỌC và tổng hợp, không ghi gì. Các bước:
 *   items()      mục đã giải nghĩa + ngày lễ hội + "vì sao hợp" (ItemInsights) + giờ mở cửa (OpeningHours)
 *   rainContext() ngữ cảnh mưa của tháng đi + phương án dự phòng đã lưu
 *   days()       chia theo ngày; tuyến mỗi ngày qua RouteService (cùng số với TripHealth / tối ưu tuyến); thứ trong tuần
 *   warnings     cảnh báo cấu trúc (ngưỡng ở PlannerRules) + cảnh báo nội dung
 *   advice()     mức "nên đi / cân nhắc" của từng điểm đến theo tháng
 *
 * Dữ liệu suy ra (tuyến, độ dày, sức khỏe) KHÔNG lưu vào CSDL: luôn tính lại từ mục đã lưu.
 */
class TripPayloadBuilder
{
    public function __construct(
        private TripPlanner $planner,
        private EventDateService $dates,
        private TravelAdvice $advice,
        private RouteService $route,
        private OpeningHours $hours,
    ) {}

    public function build(object $trip): array
    {
        $rules = PlannerRules::fromConfig();
        $days = max(1, (int) ($trip->days ?: 1));
        $start = $trip->startDate ? new DateTimeImmutable($trip->startDate) : null;
        $end = $start ? $start->modify('+' . ($days - 1) . ' days') : null;

        $warnings = [];
        $contentWarnings = [];   // cảnh báo về nội dung (lễ hội ngoài ngày đi, tháng nên cân nhắc): giao diện không tự tính được
        $items = $this->items($trip, $start, $end, $warnings, $contentWarnings);
        $time = ItemInsights::timeContext($trip);
        [$rainCtx, $rainPlan] = $this->rainContext($trip, $items, $time);

        $byDay = [];
        for ($d = 1; $d <= $days; $d++) {
            $byDay[$d] = ['day' => $d, 'date' => $start?->modify('+' . ($d - 1) . ' days')->format('Y-m-d'), 'dow' => OpeningHours::dowOf($trip->startDate, $d),
                'items' => [], 'legs' => [], 'km' => 0, 'minutes' => 0, 'cost' => 0.0];
        }
        $unassigned = [];
        foreach ($items as $it) {
            if ($it['day'] !== null && isset($byDay[$it['day']])) {
                $byDay[$it['day']]['items'][] = $it;
            } else {
                $unassigned[] = $it;
            }
        }

        foreach ($byDay as $d => $day) {
            // tuyến của ngày: MỘT nguồn tính (RouteService), cùng con số với TripHealth và tối ưu tuyến
            $pts = array_values(array_filter($day['items'], fn($x) => $x['lat'] !== null && $x['lng'] !== null));
            $tt = $this->route->travelTime(array_map(fn($x) => [(float) $x['lat'], (float) $x['lng']], $pts));
            foreach ($tt['legs'] as $l) {
                $byDay[$d]['legs'][] = ['from' => $pts[$l['from']]['name'], 'to' => $pts[$l['to']]['name'], 'km' => (int) round($l['km']), 'min' => $l['min']];
            }
            $byDay[$d]['km'] = (int) round($tt['km']);
            $byDay[$d]['minutes'] = $tt['min'];
            $byDay[$d]['cost'] = array_sum(array_map(fn($x) => $x['cost'] ?? 0, $day['items']));

            $outdoorN = count(array_filter($day['items'], fn($x) => ($x['outdoor'] ?? null) === true));
            $byDay[$d]['outdoor'] = $outdoorN;
            if ($rainCtx && $rainCtx['rainy'] && $outdoorN >= 2 && empty($rainPlan[$d])) {
                $w = 'Ngày ' . $d . ' có ' . $outdoorN . ' mục ngoài trời, tháng ' . $rainCtx['month'] . ' ở ' . $rainCtx['dest'] . ' mưa nhiều (khoảng ' . $rainCtx['rainyDays'] . ' ngày mưa). Hãy chọn phương án dự phòng khi mưa.';
                $warnings[] = $w;
                $contentWarnings[] = $w;
            }
            if (count($day['items']) > $rules->itemsWarn()) {
                $warnings[] = 'Ngày ' . $d . ' có ' . count($day['items']) . ' mục, hơi dày.';
            }
            if ($tt['min'] > $rules->travelWarnMin()) {
                $warnings[] = 'Ngày ' . $d . ' di chuyển khoảng ' . round($tt['min'] / 60, 1) . ' giờ, nên bớt điểm hoặc chia sang ngày khác.';
            }
        }
        if ($unassigned) {
            $warnings[] = 'Còn ' . count($unassigned) . ' mục chưa xếp vào ngày nào.';
        }

        $cost = array_sum(array_map(fn($x) => $x['cost'] ?? 0, $items));
        $budget = $trip->budget !== null ? (float) $trip->budget : null;
        if ($budget !== null && $cost > $budget) {
            $warnings[] = 'Chi phí dự kiến ' . number_format($cost, 0, ',', '.') . 'đ vượt ngân sách ' . number_format($budget, 0, ',', '.') . 'đ.';
        }
        $adviceRows = $this->advice($items, $start, $end, $warnings, $contentWarnings);

        return [
            'trip' => [
                'id' => (int) $trip->tripId,
                'title' => $trip->title,
                'startDate' => $trip->startDate,
                'endDate' => $end?->format('Y-m-d'),
                'days' => $days,
                'groupSize' => (int) $trip->groupSize,
                'budget' => $budget,
                'status' => $trip->status,
                'destinationId' => !empty($trip->destinationId) ? (int) $trip->destinationId : null,
                'shared' => !empty($trip->shareToken),
                'shareToken' => $trip->shareToken ?? null,
                'month' => $time['month'],
            ],
            'rain' => ['context' => $rainCtx, 'plan' => $rainPlan],
            'days' => array_values($byDay),
            'unassigned' => $unassigned,
            'totals' => ['cost' => $cost, 'budget' => $budget, 'left' => $budget !== null ? $budget - $cost : null],
            'warnings' => array_values(array_unique($warnings)),
            'contentWarnings' => array_values(array_unique($contentWarnings)),
            'advice' => $adviceRows,
            // nguồn số liệu tuyến THỰC SỰ đã dùng (OSRM lỗi thì ghi rõ đang là ước tính)
            'route' => $this->route->status(),
            'estimateNote' => $this->route->status()['fallback']
                ? 'Chưa lấy được đường bộ thật từ OSRM (' . $this->route->status()['reason'] . '), quãng đường và thời gian đang là ước tính.'
                : $rules->estimateNote(),
        ];
    }

    /** Mục của chuyến: giải nghĩa + lễ hội (ngày diễn ra) + insights + giờ mở cửa. */
    private function items(object $trip, ?DateTimeImmutable $start, ?DateTimeImmutable $end, array &$warnings, array &$contentWarnings): array
    {
        $items = [];
        foreach ($this->planner->tripItems((int) $trip->tripId) as $it) {
            [$when, $warn] = $this->eventInfo($it, $start, $end);
            $it['when'] = $when;
            unset($it['raw']);
            if ($warn) {
                $warnings[] = $warn;
                $contentWarnings[] = $warn;
            }
            $items[] = $it;
        }

        // Tuần 12: "vì sao hợp" cho từng mục
        $ins = app(ItemInsights::class)->forTrip($trip, $items);
        $hours = $this->hours->forItems($items);
        foreach ($items as &$itx) {
            $k = $itx['type'] . ':' . ($itx['refId'] ?? 0);
            $x = $ins[$k] ?? null;
            $itx['fit'] = $x['fit'] ?? [];
            $itx['outdoor'] = $x['outdoor'] ?? null;
            $itx['src'] = $x['src'] ?? null;
            $itx['hours'] = $hours[$k] ?? null;   // giờ mở cửa theo thứ (hợp đồng ở OpeningHours)
        }
        unset($itx);

        return $items;
    }

    /** @return array{0:?array,1:array} [ngữ cảnh mưa của tháng đi, phương án dự phòng đã lưu theo ngày] */
    private function rainContext(object $trip, array $items, array $time): array
    {
        $primaryDest = !empty($trip->destinationId) ? (int) $trip->destinationId : null;
        if ($primaryDest === null) {
            foreach ($items as $itx) {
                if (!empty($itx['destinationId'])) {
                    $primaryDest = (int) $itx['destinationId'];
                    break;
                }
            }
        }
        $rainCtx = null;
        if ($time['month'] && $primaryDest) {
            $rr = app(ItemInsights::class)->rainByDestination([$primaryDest], (int) $time['month'])[$primaryDest] ?? null;
            if ($rr) {
                $rainCtx = ['dest' => (string) DB::table('tbl_destinations')->where('destinationId', $primaryDest)->value('name'), 'month' => (int) $time['month'],
                    'rainy' => $rr['rainy'], 'rainyDays' => (int) round($rr['rainyDays'])];
            }
        }

        return [$rainCtx, app(RainPlan::class)->resolve($trip->rainPlan ?? null, $this->planner)];
    }

    /** Thời điểm đi: mức tháng của từng điểm đến trong khoảng ngày của chuyến. */
    private function advice(array $items, ?DateTimeImmutable $start, ?DateTimeImmutable $end, array &$warnings, array &$contentWarnings): array
    {
        $rows = [];
        if (!$start || !$end) {
            return $rows;
        }
        $destNames = [];
        foreach ($items as $it) {
            if ($it['destinationId']) {
                $destNames[$it['destinationId']] = $it['destName'] ?: $it['name'];
            }
        }
        $months = [];
        for ($c = $start; $c <= $end; $c = $c->modify('+1 day')) {
            $months[(int) $c->format('n')] = true;
        }
        foreach (array_keys($months) as $mo) {
            foreach ($this->advice->verdicts(array_keys($destNames), $mo) as $destId => $v) {
                $rows[] = ['dest' => $destNames[$destId], 'month' => $mo, 'level' => $v['level'], 'label' => TravelAdvice::LABELS[$v['level']] ?? '', 'note' => $v['note']];
                if ($v['level'] === 'avoid') {
                    $w = 'Tháng ' . $mo . ' ở ' . $destNames[$destId] . ' thuộc mức "nên cân nhắc"' . ($v['note'] ? ' (' . $v['note'] . ')' : '') . '.';
                    $warnings[] = $w;
                    $contentWarnings[] = $w;
                }
            }
        }

        return $rows;
    }

    private static function fmtRange(array $d): string
    {
        $s = new DateTimeImmutable($d['start']);
        $e = new DateTimeImmutable($d['end']);
        if (!empty($d['approx'])) {
            return 'khoảng tháng ' . $s->format('n/Y');
        }
        if ($d['start'] === $d['end']) {
            return $s->format('d/m/Y');
        }

        return $s->format('Y') === $e->format('Y') ? $s->format('d/m') . '–' . $e->format('d/m/Y') : $s->format('d/m/Y') . '–' . $e->format('d/m/Y');
    }

    /** @return array{0:?string,1:?string} [ngày diễn ra tiếp theo, cảnh báo nếu ngoài thời gian chuyến đi] */
    private function eventInfo(array $it, ?DateTimeImmutable $start, ?DateTimeImmutable $end): array
    {
        if ($it['type'] !== 'event' || empty($it['raw'])) {
            return [null, null];
        }
        $next = $this->dates->upcoming($it['raw'], $start ?? new DateTimeImmutable('today'), 1)[0] ?? null;
        if (!$next) {
            return [null, null];
        }
        $text = self::fmtRange($next);
        $warn = null;
        if ($start && $end) {
            $s = new DateTimeImmutable($next['start']);
            $e = new DateTimeImmutable($next['end']);
            if ($s > $end || $e < $start) {
                $warn = 'Lễ hội "' . $it['name'] . '" diễn ra ' . $text . ', ngoài thời gian chuyến đi.';
            }
        }

        return [$text, $warn];
    }
}
