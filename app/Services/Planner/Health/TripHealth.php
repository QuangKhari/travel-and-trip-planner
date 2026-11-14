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
    public const HARD = ['time.overlap', 'time.travel', 'time.arrival', 'time.departure', 'time.transfer', 'hours.closed', 'hours.early', 'hours.late', 'route.unreachable'];

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
            'weather' => $wx = WeatherConstraint::summary($ctx),
            'quality' => self::quality($ctx, $checks, $wx),
        ];
    }

    /** Từ khóa nhận ra mục hợp từng sở thích (config/interests.php), trên tên / loại mục. */
    private const INTEREST_PATTERNS = [
        'nature' => '/đồi|thác|hồ |hồ$|núi|biển|bãi |vườn|rừng|đèo|thung lũng|vịnh|suối|đảo|ruộng|đồng|hang|sông|cánh đồng|langbiang|fansipan/u',
        'culture' => '/chùa|đền|đình|bảo tàng|nhà thờ|di tích|phố cổ|văn miếu|lăng|thành cổ|cung|nhà cổ|hội quán|thiền viện|làng|tháp|dinh|nhà hát|bảo tàng/u',
        'food' => '/quán|chợ|ăn|phở|bún|bánh|lẩu|cơm|cà phê|cafe|nhà hàng|ẩm thực|hải sản|đặc sản|mì|nem|chè/u',
        'adventure' => '/trek|leo|zipline|lặn|chèo|kayak|dù lượn|cáp treo|mạo hiểm|vượt|đạp xe|xe đạp|cano|thuyền/u',
        'relax' => '/spa|tắm|biển|bãi|resort|cà phê|cafe|hồ|massage|suối khoáng|ngắm hoàng hôn|vườn/u',
        'family' => '/công viên|vườn thú|khu vui chơi|thủy cung|vườn dâu|thung lũng|quảng trường|khu du lịch|trẻ|dã ngoại/u',
    ];

    /**
     * Chỉ số chất lượng của chuyến (hiển thị đầu trang): hệ thống ĐÁNH GIÁ được kế hoạch, không chỉ lưu nó.
     *  - feasible: % mục xếp được đúng như đặt (không xung đột giờ, không đóng cửa, không trước giờ tới / sau giờ đi);
     *  - weather: % phút ngoài trời rơi vào giờ thời tiết tốt (WeatherFit, chỉ khi có dự báo);
     *  - interest: % sở thích của chuyến có ít nhất một mục phù hợp;
     *  - budget: chi phí dự kiến / ngân sách (≤ 100% là trong ngân sách);
     *  - travel: % thời gian trong ngày dành cho đi lại.
     * Thiếu dữ liệu thì để null và nói lý do, không đoán.
     */
    public static function quality(TripContext $ctx, array $checks, ?array $wx): array
    {
        $rows = 0;
        $bad = 0;
        $act = 0;
        $travel = 0;
        $cost = 0.0;
        $names = [];
        foreach ($ctx->days as $d) {
            foreach ($d->rows as $r) {
                $rows++;
                if ($r['conflict'] || in_array($r['hours'], ['closed', 'late'], true)) {
                    $bad++;
                }
                $names[] = mb_strtolower(($r['item']['name'] ?? '') . ' ' . ($r['item']['type'] ?? '') . ' ' . ($r['item']['kind'] ?? ''));
            }
            $act += $d->activityMin;
            $travel += $d->travelMin;
            $cost += $d->cost;
        }
        $interests = array_values(array_filter((array) json_decode((string) ($ctx->trip->interests ?? ''), true), fn($k) => isset(self::INTEREST_PATTERNS[$k])));
        $hit = [];
        foreach ($interests as $k) {
            foreach ($names as $n) {
                if (preg_match(self::INTEREST_PATTERNS[$k], $n)) {
                    $hit[] = $k;
                    break;
                }
            }
        }
        $cost += array_sum(array_map(fn($x) => (float) ($x['cost'] ?? 0), $ctx->unassigned));   // cùng cách tính với BudgetConstraint
        $budget = (int) ($ctx->budget() ?? 0);
        $labels = (array) config('interests', []);

        return [
            'feasible' => $rows ? ['pct' => (int) round(($rows - $bad) / $rows * 100), 'note' => $bad ? $bad . ' mục chưa thực hiện được như đặt' : 'mọi mục làm được đúng giờ'] : null,
            'weather' => isset($wx['fitPct']) && $wx['fitPct'] !== null ? ['pct' => (int) $wx['fitPct'], 'note' => 'theo dự báo theo giờ'] : ['pct' => null, 'note' => match ($wx['reason'] ?? null) {
                'out_of_range' => 'chưa tới tầm dự báo (16 ngày)', 'no_date' => 'chuyến chưa có ngày đi', 'off' => 'dự báo đang tắt',
                default => isset($wx['days']) && $wx['days'] && !array_filter(array_column($wx['days'], 'fitPct'), fn($v) => $v !== null) ? 'không có hoạt động ngoài trời' : 'chưa lấy được dự báo'}],
            'interest' => $interests ? ['pct' => (int) round(count($hit) / count($interests) * 100),
                'note' => 'có ' . implode(', ', array_map(fn($k) => mb_strtolower($labels[$k]['label'] ?? $k), $hit ?: [])) . (array_diff($interests, $hit) ? ($hit ? '; ' : '') . 'thiếu ' . implode(', ', array_map(fn($k) => mb_strtolower($labels[$k]['label'] ?? $k), array_diff($interests, $hit))) : '')]
                : ['pct' => null, 'note' => 'chuyến chưa chọn sở thích'],
            'budget' => $budget > 0 && $cost > 0 ? ['pct' => (int) round($cost / $budget * 100), 'note' => $cost <= $budget ? 'trong ngân sách' : 'vượt ngân sách'] : ['pct' => null, 'note' => $budget ? 'chưa có chi phí dự kiến' : 'chưa đặt ngân sách'],
            'travel' => $act + $travel > 0 ? ['pct' => (int) round($travel / ($act + $travel) * 100), 'note' => 'thời gian đi lại / (đi lại + tham quan)'] : null,
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
