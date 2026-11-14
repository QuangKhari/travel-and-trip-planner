<?php

namespace App\Services\Planner\Health;

use App\Services\Planner\PlannerRules;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;

/**
 * Sức khỏe chuyến đi = tổng hợp 5 nhóm ràng buộc trên lịch đã xếp giờ của từng ngày:
 *   TimeConstraint · OpeningHoursConstraint · RouteConstraint · CapacityConstraint · BudgetConstraint · ContextConstraint · WeatherConstraint
 * Không dùng AI: mọi kết luận đều có công thức và nguyên nhân cụ thể, kèm mã thao tác gợi ý (action) để giao diện gắn nút xử lý.
 *
 * RÀNG BUỘC CỨNG (severity = hard, luôn level "bad" = lỗi): lịch không thực hiện được như đã đặt
 *   trùng giờ, không kịp di chuyển tới giờ cố định, đóng cửa / chưa mở / quá giờ mở cửa.
 * RÀNG BUỘC MỀM (severity = soft, "warn" = cảnh báo, "info" = thông tin): vẫn đi được nhưng nên xem lại
 *   ngày dày, đi lại nhiều, kết thúc muộn, vượt ngân sách, mưa, nhiều mục...
 * Cả hai loại đều KHÔNG chặn việc lưu (tự lưu không được làm mất dữ liệu đang sửa); lỗi cứng được tô đỏ và đưa lên đầu.
 *
 * Kết quả trả về ưu tiên CHẨN ĐOÁN + HÀNH ĐỘNG (summary, recommended); điểm (0-100) chỉ là thông tin phụ:
 *   điểm = 100 - 15 × số lỗi - 5 × số cảnh báo (tối thiểu 0).
 */
class TripHealth
{
    /** @var list<Constraint> */
    private array $constraints;

    public function __construct(private TripPlanner $planner, private RouteService $route)
    {
        $this->constraints = [new TimeConstraint(), new OpeningHoursConstraint(), new RouteConstraint(), new CapacityConstraint(), new BudgetConstraint(), new ContextConstraint(), new WeatherConstraint()];
    }

    /** @return array{score:int, level:string, days:list<array>, checks:list<array>, provider:string} */
    public function forTrip(object $trip): array
    {
        $payload = self::withWeather($trip, $this->planner->payload($trip));
        $days = [];
        foreach ($payload['days'] as $d) {
            $days[(int) $d['day']] = new DaySchedule((int) $d['day'], $d['items'], $this->route, null, $d['dow'] ?? null, $d['ctx'] ?? null);
        }

        return $this->evaluate(new TripContext($trip, $days, $payload['unassigned'], $payload));
    }

    /** Gắn dự báo theo giờ (T-15, T-22) vào payload; tắt / lỗi mạng thì payload['weather'] cho biết lý do, không ném lỗi. */
    public static function withWeather(object $trip, array $payload): array
    {
        try {
            $payload['weather'] = app(\App\Services\Planner\TripWeather::class)->forTrip($trip, $payload);
        } catch (\Throwable $e) {
            report($e);
            $payload['weather'] = ['source' => 'none', 'reason' => 'error', 'days' => [], 'fetchedAt' => null, 'stale' => false];
        }

        return $payload;
    }

    /** Mã vấn đề là ràng buộc cứng (lịch không thực hiện được như đã đặt). */
    public const HARD = ['time.overlap', 'time.travel', 'time.arrival', 'time.departure', 'hours.closed', 'hours.early', 'hours.late', 'route.unreachable'];

    public static function severity(string $code): string
    {
        return in_array($code, self::HARD, true) ? 'hard' : 'soft';
    }

    public function evaluate(TripContext $ctx): array
    {
        $checks = [];
        foreach ($this->constraints as $c) {
            foreach ($c->check($ctx) as $x) {
                $sev = self::severity($x['code']);
                // cứng -> luôn là lỗi; mềm -> tối đa là cảnh báo (ngày quá tải, vượt ngân sách vẫn đi được)
                $lvl = $x['level'] === 'ok' || $x['level'] === 'info' ? $x['level'] : ($sev === 'hard' ? 'bad' : 'warn');
                $checks[] = ['group' => $c->group(), 'level' => $lvl, 'severity' => $sev,
                    'code' => $x['code'], 'text' => $x['text'], 'day' => $x['day'] ?? null, 'index' => $x['index'] ?? null, 'action' => $x['action'] ?? null];
            }
        }
        // lỗi cứng lên đầu, rồi cảnh báo, thông tin, cuối cùng là "ổn"
        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($checks, fn($a, $b) => [$rank[$a['level']] ?? 9, $a['day'] ?? 99] <=> [$rank[$b['level']] ?? 9, $b['day'] ?? 99]);
        $bad = count(array_filter($checks, fn($x) => $x['level'] === 'bad'));
        $warn = count(array_filter($checks, fn($x) => $x['level'] === 'warn'));
        $info = count(array_filter($checks, fn($x) => $x['level'] === 'info'));
        $score = max(0, 100 - 15 * $bad - 5 * $warn);

        // Kết luận khả thi: chỉ "verified" khi không có lỗi cứng VÀ mọi chặng đều có tuyến thật.
        $q = \App\Services\Planner\Route\RouteService::emptyCounts();
        foreach ($ctx->days as $d) {
            foreach ($d->routeQuality as $k => $n) {
                $q[$k] = ($q[$k] ?? 0) + $n;
            }
        }
        $conf = \App\Services\Planner\Route\RouteService::confidence($q);
        $feasibility = $bad ? 'needs_review' : ($conf === 'verified' ? 'feasible_verified' : 'feasible_with_uncertainty');

        return [
            'score' => $score,
            'level' => $bad ? 'bad' : ($warn ? 'warn' : 'ok'),
            'summary' => [
                'label' => $bad ? 'Cần sửa ' . $bad . ' lỗi lịch' . ($warn ? ', ' . $warn . ' điểm nên xem lại' : '')
                    : ($warn ? 'Chuyến đi khá ổn, ' . $warn . ' điểm nên xem lại' : 'Chuyến đi hợp lý'),
                'errors' => $bad, 'warnings' => $warn, 'infos' => $info,
            ],
            'recommended' => self::recommend($checks),
            'feasibility' => $feasibility,
            'routeQuality' => ['legs' => $q, 'confidence' => $conf],
            'provider' => $this->route->status()['actual'],
            'route' => $this->route->status(),
            'days' => array_values(array_map(fn(DaySchedule $d) => [
                'day' => $d->day, 'count' => $d->count(), 'loadPct' => (int) round($d->load() * 100), 'level' => $d->count() ? $d->level() : 'ok',
                'loadLabel' => PlannerRules::fromConfig()->loadLabel($d->count() ? $d->load() : 0.0),
                'activityMin' => $d->activityMin, 'travelMin' => $d->travelMin, 'travelKm' => round($d->travelKm, 1), 'mealMin' => $d->mealMin,
                'bufferMin' => $d->bufferMin, 'freeMin' => $d->freeMin, 'availableMin' => $d->availableMin, 'occupiedMin' => $d->occupiedMin(),
                'start' => DaySchedule::hhmm($d->start), 'end' => DaySchedule::hhmm($d->end), 'cost' => round($d->cost),
                'routeConfidence' => $d->routeConfidence,
                // chỗ ở / đi về: chặng từ và về khách sạn, giờ tới nơi, giờ phải rời đi
                'startLeg' => $d->startLeg, 'endLeg' => $d->endLeg,
                'arrive' => isset($d->ctx['arrive']) ? DaySchedule::hhmm((int) $d->ctx['arrive']) : null,
                'leave' => isset($d->ctx['leave']) ? DaySchedule::hhmm((int) $d->ctx['leave']) : null,
                'rows' => array_map(fn($r) => ['type' => $r['item']['type'], 'refId' => $r['item']['refId'] ?? null, 'name' => $r['item']['name'],
                    'start' => DaySchedule::hhmm($r['start']), 'end' => DaySchedule::hhmm($r['end']), 'durationMin' => $r['dur'], 'fixed' => $r['fixed'], 'hours' => $r['hours'],
                    'travelMin' => $r['travelMin'], 'travelKm' => round($r['travelKm'], 1), 'routeStatus' => $r['routeStatus'] ?? null, 'waitMin' => $r['waitMin'], 'conflict' => $r['conflict'],
                    'estimatedDuration' => empty($r['item']['durationMin'])], $d->rows),
            ], $ctx->days)),
            'checks' => $checks,
            'weather' => WeatherConstraint::summary($ctx),
        ];
    }

    /**
     * Tối đa 3 hành động nên làm trước, mỗi hành động gắn với một vấn đề có cách xử lý (TripResolver).
     * @return list<array{label:string,code:string,day:?int,index:?int}>
     */
    private static function recommend(array $checks): array
    {
        $label = [
            'capacity.overload' => fn($c) => 'Cân bằng ngày ' . $c['day'],
            'capacity.tight' => fn($c) => 'Cân bằng ngày ' . $c['day'],
            'time.late' => fn($c) => 'Cân bằng ngày ' . $c['day'],
            'time.overlap' => fn($c) => 'Sửa trùng giờ ngày ' . $c['day'],
            'time.travel' => fn($c) => 'Sửa giờ đi ngày ' . $c['day'],
            'hours.closed' => fn($c) => 'Đổi ngày cho nơi đóng cửa (ngày ' . $c['day'] . ')',
            'hours.early' => fn($c) => 'Sửa giờ mở cửa ngày ' . $c['day'],
            'hours.late' => fn($c) => 'Sửa giờ mở cửa ngày ' . $c['day'],
            'route.long_day' => fn($c) => 'Tối ưu tuyến ngày ' . $c['day'],
            'context.rain' => fn($c) => 'Chuẩn bị phương án mưa ngày ' . $c['day'],
            'weather.rain' => fn($c) => 'Chuẩn bị phương án mưa ngày ' . $c['day'],
            'weather.storm' => fn($c) => 'Chuẩn bị phương án mưa ngày ' . $c['day'],
        ];
        $out = [];
        $seen = [];
        foreach ($checks as $c) {
            if (!isset($label[$c['code']]) || !in_array($c['level'], ['bad', 'warn'], true) || $c['day'] === null) {
                continue;
            }
            $l = $label[$c['code']]($c);
            if (isset($seen[$l])) {
                continue;
            }
            $seen[$l] = true;
            $out[] = ['label' => $l, 'code' => $c['code'], 'day' => $c['day'], 'index' => $c['index']];
            if (count($out) === 3) {
                break;
            }
        }

        return $out;
    }
}
