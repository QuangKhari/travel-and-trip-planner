<?php

namespace App\Services\Planner\Health;

use App\Services\Planner\OpeningHours;
use App\Services\Planner\PlannerRules;
use App\Services\Planner\Route\HaversineProvider;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;

/**
 * Resolution Engine: Phát hiện -> Giải thích -> ĐỀ XUẤT -> XEM TRƯỚC -> Áp dụng.
 *
 * Với một vấn đề (code của TripHealth + ngày + vị trí mục), dựng vài phương án. Mỗi phương án:
 *   - changes : thao tác trên BẢN ĐÃ LƯU, mục chỉ bằng (ngày, vị trí trong ngày)
 *        {op: move,    day, index, toDay|null}    chuyển ngày (null = về "Đã lưu"); giờ cố định bị bỏ (cả xem trước lẫn áp dụng)
 *        {op: time,    day, index, startMin|null} đặt / bỏ giờ cố định
 *        {op: reorder, day, order: [index...]}    sắp lại thứ tự trong ngày
 *        {op: replace, day, index, with: {...}}   thay bằng nơi tương tự (giữ giờ / buổi của mục cũ); mục cũ về "Đã lưu"
 *        {op: rainplan, day, items: [...]}        lưu phương án dự phòng khi mưa cho ngày (không đổi lịch)
 *   - reasons : vì sao chọn (ngày nhận còn bao nhiêu phút trống, gần bao nhiêu km, giờ mở cửa...)
 *   - effects : từng ngày bị ảnh hưởng: độ dày, phút đã dùng, số mục, km đi lại trước / sau
 *   - after   : sức khỏe CẢ CHUYẾN sau khi áp dụng (điểm, mức) — tính bằng đúng TripHealth trên bản sao
 *   - resolves: vấn đề gốc không còn VÀ không sinh lỗi cứng (bad) mới
 *   - newIssues: vấn đề mới xuất hiện sau khi áp dụng (để người dùng thấy cái giá phải trả)
 *
 * Bất biến (tests/Feature/PlannerInvariantTest): áp dụng phương án rồi lưu thật, sức khỏe máy chủ trả về trùng với "after" đã xem trước.
 * Không bao giờ đề xuất di chuyển mục có giờ cố định / đã khóa / có buổi / lưu trú / ăn uống khi cân bằng, và không đổi chỗ chúng khi sắp tuyến.
 */
final class TripResolver
{
    private const BALANCE_TARGET = 0.9;

    private PlannerRules $rules;
    private object $trip;
    private array $payload;
    /** @var array<int,list<array>> */
    private array $days = [];
    /** @var array<int,?int> */
    private array $dows = [];
    /** @var array<int,?array> ngữ cảnh chỗ ở / đi về của từng ngày (TripLogistics::dayContext) */
    private array $ctxs = [];
    private array $unassigned = [];
    private array $before;

    public function __construct(private TripPlanner $planner, private RouteService $route, private TripHealth $health)
    {
        $this->rules = PlannerRules::fromConfig();
    }

    /** @return array{code:string,day:?int,index:?int,problem:?string,options:list<array>} */
    public function forTrip(object $trip, string $code, ?int $day, ?int $index): array
    {
        $this->trip = $trip;
        $this->payload = TripHealth::withWeather($trip, $this->planner->payload($trip));
        $this->days = [];
        foreach ($this->payload['days'] as $d) {
            $this->days[(int) $d['day']] = array_values($d['items']);
            $this->dows[(int) $d['day']] = $d['dow'] ?? null;
            $this->ctxs[(int) $d['day']] = $d['ctx'] ?? null;
        }
        $this->unassigned = $this->payload['unassigned'];
        $this->before = $this->healthOf($this->days, $this->unassigned);

        $target = null;
        foreach ($this->before['checks'] as $c) {
            if ($c['code'] === $code && ($day === null || $c['day'] === $day) && ($index === null || $c['index'] === $index)) {
                $target = $c;
                break;
            }
        }
        if ($target === null) {
            return ['code' => $code, 'day' => $day, 'index' => $index, 'problem' => null, 'options' => []];
        }
        $options = match (true) {
            in_array($code, ['capacity.overload', 'capacity.tight', 'time.late'], true) && $day !== null => $this->balanceOptions($day, $target),
            in_array($code, ['time.overlap', 'time.travel'], true) && $day !== null && $index !== null => $this->timeOptions($day, $index, $target),
            str_starts_with($code, 'hours.') && $day !== null && $index !== null => $this->hoursOptions($day, $index, $target),
            in_array($code, ['time.arrival', 'time.departure'], true) && $day !== null && $index !== null => $this->transferOptions($day, $index, $target),
            $code === 'route.long_day' && $day !== null => $this->routeOptions($day, $target),
            in_array($code, ['context.rain', 'weather.rain', 'weather.storm'], true) && $day !== null => $this->rainOptions($day, $target),
            default => [],
        };
        // phương án giải quyết được lên trước, rồi theo điểm sau khi áp dụng
        usort($options, fn($a, $b) => [$b['resolves'], $b['after']['score']] <=> [$a['resolves'], $a['after']['score']]);

        return ['code' => $code, 'day' => $day, 'index' => $index, 'problem' => $target['text'], 'severity' => $target['severity'] ?? null,
            'before' => ['score' => $this->before['score'], 'level' => $this->before['level']], 'options' => array_values($options)];
    }

    /**
     * Mô phỏng một danh sách thao tác (dạng ngày/vị trí, đã biên dịch) trên bản sao của chuyến, chấm lại bằng CHÍNH TripHealth.
     * Dùng cho La bàn Copilot. So vấn đề trước / sau theo DANH TÍNH (mã + mục, hoặc mã + ngày), không theo vị trí.
     * @return array{before:array,after:array,effects:list<array>,newIssues:list<array>,resolvedIssues:list<array>,newHard:int,days:array,unassigned:list<array>}
     * @throws \InvalidArgumentException khi thao tác trỏ tới vị trí không còn
     */
    public function simulate(object $trip, array $changes): array
    {
        $this->trip = $trip;
        $this->payload = $this->planner->payload($trip);
        $this->days = [];
        foreach ($this->payload['days'] as $d) {
            $this->days[(int) $d['day']] = array_values($d['items']);
            $this->dows[(int) $d['day']] = $d['dow'] ?? null;
            $this->ctxs[(int) $d['day']] = $d['ctx'] ?? null;
        }
        $this->unassigned = $this->payload['unassigned'];
        $this->before = $this->healthOf($this->days, $this->unassigned);
        [$days, $unassigned, $payload] = $this->apply($changes);
        $after = $this->healthOf($days, $unassigned, $payload);
        $relevant = fn($c) => in_array($c['level'], ['bad', 'warn'], true);
        $bk = [];
        foreach ($this->before['checks'] as $c) {
            if ($relevant($c)) {
                $bk[self::checkKey($c, $this->days)] = $c;
            }
        }
        $ak = [];
        foreach ($after['checks'] as $c) {
            if ($relevant($c)) {
                $ak[self::checkKey($c, $days)] = $c;
            }
        }
        $pick = fn($c) => ['code' => $c['code'], 'level' => $c['level'], 'severity' => $c['severity'], 'day' => $c['day'], 'text' => $c['text']];
        $new = array_values(array_map($pick, array_diff_key($ak, $bk)));
        $gone = array_values(array_map($pick, array_diff_key($bk, $ak)));

        return [
            'before' => ['score' => $this->before['score'], 'level' => $this->before['level'], 'summary' => $this->before['summary']['label']],
            'after' => ['score' => $after['score'], 'level' => $after['level'], 'summary' => $after['summary']['label']],
            'effects' => $this->effects($changes, $days),
            'newIssues' => $new,
            'resolvedIssues' => $gone,
            'newHard' => count(array_filter($new, fn($x) => $x['severity'] === 'hard')),
            'days' => $days,
            'unassigned' => $unassigned,
            'afterDays' => $after['days'],
        ];
    }

    /**
     * Mục ứng viên dạng trình duyệt (itemForClient) dựng ĐÚNG như resolvedItem, cho thao tác replace / add của La bàn.
     * null nếu nội dung không còn (bị ẩn / xóa).
     */
    public function candidate(object $trip, string $type, int $refId, array $old = []): ?array
    {
        $this->trip = $trip;
        if (!$this->planner->resolve([['type' => $type, 'refId' => $refId]])) {
            return null;
        }

        return TripPlanner::itemForClient($this->resolvedItem($type, $refId, $old));
    }

    // ---------------------------------------------------------------- mô phỏng (dùng CHÍNH TripHealth)

    private function schedule(int $day, ?array $items = null): DaySchedule
    {
        return new DaySchedule($day, $items ?? ($this->days[$day] ?? []), $this->route, $this->rules, $this->dows[$day] ?? null, $this->ctxs[$day] ?? null);
    }

    private function healthOf(array $days, array $unassigned, ?array $payload = null): array
    {
        $sch = [];
        foreach ($days as $d => $list) {
            $sch[$d] = $this->schedule($d, array_values($list));
        }

        return $this->health->evaluate(new TripContext($this->trip, $sch, $unassigned, $payload ?? $this->payload));
    }

    /**
     * Áp các thao tác lên một bản sao — CÙNG ngữ nghĩa với applyResolution() ở trình duyệt:
     * move = bỏ giờ cố định, đưa xuống cuối ngày nhận (hoặc vào "Đã lưu"); time = đổi startMin; reorder = sắp lại.
     * @return array{0:array<int,list<array>>,1:list<array>,2:array} [các ngày, danh sách "Đã lưu", payload (phương án mưa)]
     */
    private function apply(array $changes): array
    {
        // Một nguồn ngữ nghĩa thao tác cho Resolution Engine và La bàn Copilot (đã kiểm tra tương đương với bản cũ)
        return ChangeSimulator::apply($this->days, $this->unassigned, $this->payload, $changes,
            fn(string $type, int $refId, ?array $old) => $this->resolvedItem($type, $refId, $old ?? []));
    }

    /** Khóa nhận diện một kiểm tra theo MỤC (không theo vị trí, vì vị trí đổi sau khi áp dụng). */
    private static function itemKey(?array $it): string
    {
        return $it ? $it['type'] . ':' . ($it['refId'] ?? '') . ':' . $it['name'] : '';
    }

    private static function checkKey(array $c, array $days): string
    {
        $it = $c['index'] !== null && $c['day'] !== null ? ($days[$c['day']][$c['index']] ?? null) : null;

        return $c['code'] . '|' . ($it ? self::itemKey($it) : 'd' . ($c['day'] ?? ''));
    }

    /** @param array $target kiểm tra gốc */
    private function option(string $id, string $label, array $reasons, array $changes, array $target): array
    {
        [$days, $unassigned, $payload] = $this->apply($changes);
        $after = $this->healthOf($days, $unassigned, $payload);
        $relevant = fn($c) => in_array($c['level'], ['bad', 'warn'], true);
        $beforeKeys = [];
        foreach ($this->before['checks'] as $c) {
            if ($relevant($c)) {
                $beforeKeys[self::checkKey($c, $this->days)] = true;
            }
        }
        // vấn đề gốc: theo mục (vấn đề giờ) hoặc theo ngày (vấn đề sức chứa / tuyến); mục đã rời ngày thì tìm ở mọi ngày
        $tItem = $target['index'] !== null ? ($this->days[$target['day']][$target['index']] ?? null) : null;
        $stillThere = false;
        $new = [];
        foreach ($after['checks'] as $c) {
            if (!$relevant($c)) {
                continue;
            }
            $k = self::checkKey($c, $days);
            if ($tItem ? $k === $target['code'] . '|' . self::itemKey($tItem) : ($c['code'] === $target['code'] && $c['day'] === $target['day'])) {
                $stillThere = true;
            } elseif (!isset($beforeKeys[$k])) {
                $new[] = ['level' => $c['level'], 'severity' => $c['severity'], 'text' => $c['text']];
            }
        }
        $hardNew = array_filter($new, fn($x) => $x['severity'] === 'hard');

        return [
            'id' => $id, 'label' => $label, 'reasons' => array_values(array_filter($reasons)), 'reason' => implode(' ', array_filter($reasons)),
            'changes' => $changes, 'effects' => $this->effects($changes, $days),
            'after' => ['score' => $after['score'], 'level' => $after['level'], 'summary' => $after['summary']['label']],
            'resolves' => !$stillThere && !$hardNew, 'newIssues' => $new,
        ];
    }

    /** @return list<array{day:int,beforePct:int,afterPct:int,beforeMin:int,afterMin:int,beforeCount:int,afterCount:int,beforeKm:float,afterKm:float}> */
    private function effects(array $changes, array $after): array
    {
        $out = [];
        foreach (ChangeSimulator::touchedDays($changes) as $d) {
            $b = $this->schedule($d);
            $a = $this->schedule($d, $after[$d] ?? []);
            $out[] = ['day' => $d, 'beforePct' => (int) round($b->load() * 100), 'afterPct' => (int) round($a->load() * 100),
                'beforeMin' => $b->occupiedMin(), 'afterMin' => $a->occupiedMin(), 'beforeCount' => $b->count(), 'afterCount' => $a->count(),
                'beforeKm' => round($b->travelKm, 1), 'afterKm' => round($a->travelKm, 1)];
        }

        return $out;
    }

    /** Mục cân bằng / sắp tuyến được phép đụng tới: không giờ cố định, không khóa, không buổi, không lưu trú / ăn uống / di chuyển, không phải mục đầu ngày. */
    private function isFree(array $it, int $index): bool
    {
        $k = $this->rules->kindOf($it);

        return $index > 0 && ($it['startMin'] ?? null) === null && empty($it['locked']) && empty($it['slot'])
            && !in_array($k, ['lodging', 'meal', 'transport'], true);
    }

    private static function h(int $min): string
    {
        return $min >= 60 ? intdiv($min, 60) . 'h' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) : $min . ' phút';
    }

    /** Ngày nhận tốt nhất: còn chỗ (< 90% sau khi nhận), mở cửa hôm đó nếu biết giờ, gần các điểm của ngày đó. */
    private function bestDayFor(array $it, int $from, array $days): ?array
    {
        $best = null;
        foreach ($days as $d => $list) {
            if ($d === $from) {
                continue;
            }
            $it2 = $it;
            $it2['startMin'] = null;
            $s = $this->schedule($d, array_merge($list, [$it2]));
            if ($s->load() >= self::BALANCE_TARGET) {
                continue;
            }
            $dow = $this->dows[$d] ?? null;
            if (!empty($it['hours']) && $dow !== null && array_key_exists((string) $dow, $it['hours']) && OpeningHours::fit($it['hours'], $dow, $this->rules->dayStart(), $s->durationOf($it)) === null) {
                continue;   // hôm đó đóng cửa / không đủ giờ mở
            }
            $near = null;
            if ($it['lat'] !== null) {
                foreach ($list as $x) {
                    if ($x['lat'] !== null) {
                        $k = HaversineProvider::straightKm([$x['lat'], $x['lng']], [$it['lat'], $it['lng']]);
                        $near = $near === null ? $k : min($near, $k);
                    }
                }
            }
            $score = ($near ?? 30) + $s->load() * 5;
            if ($best === null || $score < $best['score']) {
                $free = max(0, $this->schedule($d, $list)->availableMin - $this->schedule($d, $list)->occupiedMin());
                $best = ['day' => $d, 'score' => $score, 'load' => $s->load(), 'near' => $near, 'freeMin' => $free];
            }
        }

        return $best;
    }

    private function dayReason(array $b): array
    {
        return [
            'Ngày ' . $b['day'] . ' còn ' . self::h($b['freeMin']) . ' trống.',
            $b['near'] !== null && $b['near'] < 30 ? 'Gần điểm khác của ngày ' . $b['day'] . ' (~' . round($b['near'], 1) . ' km).' : null,
        ];
    }

    // ---------------------------------------------------------------- các loại vấn đề

    private function balanceOptions(int $day, array $target): array
    {
        $list = $this->days[$day] ?? [];
        $cands = [];
        foreach ($list as $i => $it) {
            if ($this->isFree($it, $i)) {
                $cands[] = ['i' => $i, 'dur' => $this->schedule($day)->durationOf($it)];
            }
        }
        usort($cands, fn($a, $b) => [$b['dur'], $b['i']] <=> [$a['dur'], $a['i']]);
        $opts = [];
        $load0 = (int) round($this->schedule($day)->load() * 100);

        // 1) cân bằng nhiều bước tới dưới 90%
        $changes = [];
        $names = [];
        foreach ($cands as $c) {
            [$after] = $this->apply($changes);
            if ($this->schedule($day, $after[$day])->load() < self::BALANCE_TARGET) {
                break;
            }
            $it = $list[$c['i']];
            $b = $this->bestDayFor($it, $day, $after);
            $changes[] = ['op' => 'move', 'day' => $day, 'index' => $c['i'], 'toDay' => $b['day'] ?? null];
            $names[] = '“' . $it['name'] . '” → ' . ($b ? 'ngày ' . $b['day'] : 'Đã lưu');
        }
        if ($changes) {
            $opts[] = $this->option('balance', 'Cân bằng ngày ' . $day, [
                'Ngày ' . $day . ' đang dùng ' . $load0 . '% thời gian.',
                'Chuyển ' . implode(', ', $names) . '.',
                'Giữ nguyên mục có giờ cố định, đã khóa, có buổi, lưu trú và ăn uống.',
            ], $changes, $target);
        }
        // 2) chỉ chuyển một mục dài nhất / đưa về "Đã lưu"
        if ($cands) {
            $it = $list[$cands[0]['i']];
            $b = $this->bestDayFor($it, $day, $this->days);
            if ($b) {
                $opts[] = $this->option('move-one', 'Chuyển “' . $it['name'] . '” sang ngày ' . $b['day'],
                    array_merge(['Mục dài nhất có thể dời (' . self::h($cands[0]['dur']) . ').'], $this->dayReason($b)),
                    [['op' => 'move', 'day' => $day, 'index' => $cands[0]['i'], 'toDay' => $b['day']]], $target);
            }
            $opts[] = $this->option('inbox-one', 'Đưa “' . $it['name'] . '” về Đã lưu', ['Bớt ' . self::h($cands[0]['dur']) . ' cho ngày ' . $day . '; mục không mất, kéo lại vào ngày khác khi muốn.'],
                [['op' => 'move', 'day' => $day, 'index' => $cands[0]['i'], 'toDay' => null]], $target);
        }

        return $opts;
    }

    private function timeOptions(int $day, int $index, array $target): array
    {
        $s = $this->schedule($day);
        if ($index < 1 || !isset($s->rows[$index])) {
            return [];
        }
        $prev = $s->rows[$index - 1];
        $it = $s->rows[$index]['item'];
        $earliest = (int) (ceil(($prev['end'] + $prev['travelMin']) / 5) * 5);
        $why = '“' . $prev['item']['name'] . '” xong lúc ' . DaySchedule::hhmm($prev['end']) . ($prev['travelMin'] ? ', cần thêm ' . $prev['travelMin'] . ' phút di chuyển.' : '.');
        $opts = [];
        $opts[] = $this->option('shift', 'Dời “' . $it['name'] . '” sang ' . DaySchedule::hhmm($earliest), [$why, 'Giữ giờ cố định, chỉ dời cho kịp.'],
            [['op' => 'time', 'day' => $day, 'index' => $index, 'startMin' => $earliest]], $target);
        $opts[] = $this->option('unfix', 'Bỏ giờ cố định của “' . $it['name'] . '”', [$why, 'Planner tự xếp nối tiếp mục trước.'],
            [['op' => 'time', 'day' => $day, 'index' => $index, 'startMin' => null]], $target);

        return $opts;
    }

    /** Ngày tới nơi / ngày về: hoạt động rơi vào trước giờ tới hoặc sau lúc phải ra ga / sân bay. */
    private function transferOptions(int $day, int $index, array $target): array
    {
        $s = $this->schedule($day);
        if (!isset($s->rows[$index])) {
            return [];
        }
        $r = $s->rows[$index];
        $it = $r['item'];
        $why = $target['text'];
        $opts = [];
        if ($r['fixed'] && $r['conflict'] === 'arrival') {
            $opts[] = $this->option('unfix', 'Bỏ giờ cố định của “' . $it['name'] . '”', [$why, 'Planner tự xếp sau giờ tới nơi.'],
                [['op' => 'time', 'day' => $day, 'index' => $index, 'startMin' => null]], $target);
        }
        // ngày khác còn chỗ: ngày giữa chuyến (không phải ngày tới / ngày về) nhẹ nhất
        $best = null;
        foreach ($this->days as $d => $list) {
            if ($d === $day || !empty($this->ctxs[$d]['arrive']) || !empty($this->ctxs[$d]['leave'])) {
                continue;
            }
            $it2 = $it;
            $it2['startMin'] = null;
            $load = $this->schedule($d, array_merge($list, [$it2]))->load();
            if ($load < 1 && ($best === null || $load < $best[1])) {
                $best = [$d, $load];
            }
        }
        if ($best) {
            $opts[] = $this->option('move', 'Chuyển sang ngày ' . $best[0], [$why, 'Ngày ' . $best[0] . ' sau khi thêm dùng khoảng ' . (int) round($best[1] * 100) . '% thời gian.'],
                [['op' => 'move', 'day' => $day, 'index' => $index, 'toDay' => $best[0]]], $target);
        }
        $opts[] = $this->option('park', 'Đưa “' . $it['name'] . '” vào “Đã lưu”', [$why, 'Giữ lại để xếp sau, không mất mục.'],
            [['op' => 'move', 'day' => $day, 'index' => $index, 'toDay' => null]], $target);

        return $opts;
    }

    private function hoursOptions(int $day, int $index, array $target): array
    {
        $s = $this->schedule($day);
        if (!isset($s->rows[$index])) {
            return [];
        }
        $r = $s->rows[$index];
        $it = $r['item'];
        $dow = $this->dows[$day] ?? null;
        $todayText = 'Giờ mở cửa hôm đó: ' . (OpeningHours::describe($it['hours'] ?? null, $dow) ?? 'chưa rõ') . '.';
        $opts = [];
        // Đóng cửa cả ngày: KHÔNG đề xuất bỏ giờ cố định / dời giờ (vô nghĩa) — chỉ đổi ngày hoặc đưa về "Đã lưu".
        if ($r['hours'] !== 'closed') {
            if ($r['fixed']) {
                $from = $r['hours'] === 'early' ? $r['start'] : $this->rules->dayStart();
                $fit = OpeningHours::fit($it['hours'], $dow, $from, $r['dur']);
                if ($fit !== null) {
                    $opts[] = $this->option('shift', ($fit < $r['start'] ? 'Dời sớm hơn: ' : 'Dời sang ') . DaySchedule::hhmm($fit), [$todayText, 'Vẫn giữ giờ cố định.'],
                        [['op' => 'time', 'day' => $day, 'index' => $index, 'startMin' => $fit]], $target);
                }
                $opts[] = $this->option('unfix', 'Bỏ giờ cố định', [$todayText, 'Planner tự chờ tới giờ mở cửa khi xếp lịch.'],
                    [['op' => 'time', 'day' => $day, 'index' => $index, 'startMin' => null]], $target);
            }
        }
        foreach ($this->days as $d => $list) {
            $w = $this->dows[$d] ?? null;
            if ($d === $day || $w === null || OpeningHours::fit($it['hours'], $w, $this->rules->dayStart(), $r['dur']) === null) {
                continue;
            }
            $it2 = $it;
            $it2['startMin'] = null;
            if ($this->schedule($d, array_merge($list, [$it2]))->load() < 1) {
                $free = max(0, $this->schedule($d)->availableMin - $this->schedule($d)->occupiedMin());
                $opts[] = $this->option('move', 'Chuyển sang ngày ' . $d, ['Ngày ' . $d . ' mở cửa ' . OpeningHours::describe($it['hours'], $w) . '.', 'Ngày ' . $d . ' còn ' . self::h($free) . ' trống.'],
                    [['op' => 'move', 'day' => $day, 'index' => $index, 'toDay' => $d]], $target);
                break;
            }
        }
        foreach ($this->similarPlaces($it, $day) as $cand) {
            $new = $this->resolvedItem('place', $cand['id'], $it);
            $opts[] = $this->option('replace', 'Thay bằng “' . $new['name'] . '”', array_filter([
                'Cùng loại' . ($new['sub'] ? ' (' . mb_strtolower($new['sub']) . ')' : '') . ', cách ~' . round($cand['km'], 1) . ' km.',
                $cand['open'] ? 'Mở cửa hôm đó: ' . OpeningHours::describe($new['hours'], $dow) . '.' : 'Chưa có dữ liệu giờ mở cửa của nơi này.',
                '“' . $it['name'] . '” về Đã lưu, không mất.',
            ]), [['op' => 'replace', 'day' => $day, 'index' => $index, 'with' => TripPlanner::itemForClient($new)]], $target);
        }
        $opts[] = $this->option('inbox', 'Đưa về Đã lưu', [$todayText, 'Giữ mục lại để chọn ngày khác hoặc thay bằng nơi tương tự.'],
            [['op' => 'move', 'day' => $day, 'index' => $index, 'toDay' => null]], $target);

        return $opts;
    }

    /**
     * Mục chuyến đi dựng ĐÚNG như sau khi lưu rồi tải lại (resolve + insights + giờ mở cửa), để xem trước khớp áp dụng.
     * Giữ giờ cố định và buổi của mục cũ (cùng ngữ nghĩa với trình duyệt).
     */
    private function resolvedItem(string $type, int $refId, array $old): array
    {
        $it = $this->planner->resolve([['type' => $type, 'refId' => $refId]])[$type . ':' . $refId];
        unset($it['raw']);
        $it += ['day' => $old['day'] ?? null, 'order' => 0, 'note' => null, 'cost' => null, 'slot' => $old['slot'] ?? null, 'reasons' => [],
            'startMin' => $old['startMin'] ?? null, 'durationMin' => null, 'costType' => null, 'locked' => false, 'when' => null];
        $x = app(\App\Services\Planner\ItemInsights::class)->forTrip($this->trip, [$it])[$type . ':' . $refId] ?? null;
        $it['fit'] = $x['fit'] ?? [];
        $it['outdoor'] = $x['outdoor'] ?? null;
        $it['src'] = $x['src'] ?? null;
        $it['hours'] = app(OpeningHours::class)->forItems([$it])[$type . ':' . $refId] ?? null;

        return $it;
    }

    /**
     * Nơi tương tự cho một địa điểm: cùng nhóm loại (config planner.kind_groups), đã duyệt, trong ~5 km, chưa có trong chuyến,
     * KHÔNG đóng cửa hôm đó. Ưu tiên nơi biết chắc đang mở, rồi gần hơn. Tối đa 2 nơi.
     * @return list<array{id:int,km:float,open:bool}>
     */
    private function similarPlaces(array $it, int $day): array
    {
        if (($it['type'] ?? '') !== 'place' || $it['lat'] === null || empty($it['kind'])) {
            return [];
        }
        $kinds = [$it['kind']];
        foreach ((array) config('planner.kind_groups', []) as $group) {
            if (in_array($it['kind'], (array) $group, true)) {
                $kinds = array_values(array_unique(array_merge($kinds, (array) $group)));
            }
        }
        $have = [];
        foreach (array_merge($this->unassigned, ...array_values($this->days)) as $x) {
            if (($x['type'] ?? '') === 'place') {
                $have[(int) $x['refId']] = true;
            }
        }
        $dLat = 5 / 111.0;
        $dLng = 5 / (111.0 * max(0.2, cos(deg2rad((float) $it['lat']))));
        $rows = \Illuminate\Support\Facades\DB::table('tbl_external_places')->where('reviewStatus', 'published')->whereIn('kind', $kinds)
            ->whereBetween('latitude', [$it['lat'] - $dLat, $it['lat'] + $dLat])->whereBetween('longitude', [$it['lng'] - $dLng, $it['lng'] + $dLng])
            ->limit(60)->get(['placeId', 'latitude', 'longitude']);
        $hours = app(OpeningHours::class)->forItems($rows->map(fn($r) => ['type' => 'place', 'refId' => (int) $r->placeId])->all());
        $dow = $this->dows[$day] ?? null;
        $dur = $this->schedule($day)->durationOf($it);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->placeId;
            if (isset($have[$id])) {
                continue;
            }
            $h = $hours['place:' . $id] ?? null;
            $known = $h !== null && $dow !== null && array_key_exists((string) $dow, $h);
            $open = $known && OpeningHours::fit($h, $dow, $this->rules->dayStart(), $dur) !== null;
            if ($known && !$open) {
                continue;   // cũng đóng cửa / không đủ giờ hôm đó
            }
            $km = HaversineProvider::straightKm([(float) $it['lat'], (float) $it['lng']], [(float) $r->latitude, (float) $r->longitude]);
            if ($km <= 5) {
                $out[] = ['id' => $id, 'km' => $km, 'open' => $open];
            }
        }
        usort($out, fn($a, $b) => [!$a['open'], $a['km']] <=> [!$b['open'], $b['km']]);

        return array_slice($out, 0, 2);
    }

    /**
     * Ngày ngoài trời gặp tháng hay mưa: (1) giữ lịch, lưu sẵn vài nơi trong nhà gần đó làm dự phòng;
     * (2) đổi hẳn các hoạt động ngoài trời sang nơi trong nhà gần nhất (mục cũ về "Đã lưu").
     */
    private function rainOptions(int $day, array $target): array
    {
        $list = $this->days[$day] ?? [];
        $all = array_merge($this->unassigned, ...array_values($this->days));
        $destIds = array_merge([(int) ($this->trip->destinationId ?? 0)], array_map(fn($x) => (int) ($x['destinationId'] ?? 0), $all));
        $sugg = app(\App\Services\Planner\RainPlan::class)->suggest($destIds, $all, $list);
        if (!$sugg) {
            return [];
        }
        $outdoor = array_keys(array_filter($list, fn($x) => ($x['outdoor'] ?? null) === true));
        $opts = [];
        $backup = array_map(fn($x) => ['type' => $x['type'], 'refId' => $x['refId'], 'name' => $x['name'], 'sub' => $x['sub'], 'url' => $x['url'], 'lat' => $x['lat'], 'lng' => $x['lng']],
            array_slice($sugg, 0, min(3, (int) config('planner.rain_plan.max_per_day', 4))));
        $opts[] = $this->option('rain-backup', 'Giữ lịch, lưu ' . count($backup) . ' nơi trong nhà làm dự phòng', [
            'Ngày ' . $day . ' có ' . count($outdoor) . ' hoạt động ngoài trời.',
            'Nếu mưa: ' . implode(', ', array_map(fn($x) => '“' . $x['name'] . '”', $backup)) . '.',
            'Lịch hiện tại không đổi.',
        ], [['op' => 'rainplan', 'day' => $day, 'items' => $backup]], $target);

        // đổi hẳn: mỗi mục ngoài trời lấy nơi trong nhà gần nó nhất, không trùng nhau
        $used = [];
        $changes = [];
        $pairs = [];
        foreach ($outdoor as $i) {
            $it = $list[$i];
            $best = null;
            foreach ($sugg as $k => $x) {
                if (isset($used[$k])) {
                    continue;
                }
                $km = $it['lat'] !== null && $x['lat'] !== null ? HaversineProvider::straightKm([$it['lat'], $it['lng']], [$x['lat'], $x['lng']]) : 50.0;
                if ($best === null || $km < $best[1]) {
                    $best = [$k, $km];
                }
            }
            if ($best === null) {
                break;
            }
            $used[$best[0]] = true;
            $x = $sugg[$best[0]];
            $changes[] = ['op' => 'replace', 'day' => $day, 'index' => $i, 'with' => TripPlanner::itemForClient($this->resolvedItem($x['type'], (int) $x['refId'], $it))];
            $pairs[] = '“' . $it['name'] . '” → “' . $x['name'] . '”' . ($best[1] < 50 ? ' (~' . round($best[1], 1) . ' km)' : '');
        }
        if ($changes) {
            $opts[] = $this->option('rain-swap', 'Đổi ' . count($changes) . ' hoạt động ngoài trời sang trong nhà', array_merge(
                ['Hợp khi dự báo chắc chắn mưa.'], $pairs, ['Các mục ngoài trời về Đã lưu, đổi lại được khi trời đẹp.']
            ), $changes, $target);
        }

        return $opts;
    }

    private function routeOptions(int $day, array $target): array
    {
        $list = $this->days[$day] ?? [];
        $stops = [];
        foreach ($list as $i => $it) {
            $stops[] = ['key' => (string) $i, 'lat' => $it['lat'], 'lng' => $it['lng'], 'anchor' => !$this->isFree($it, $i)];
        }
        $o = $this->route->optimize($stops);
        if (!$o['changed']) {
            return [];
        }
        $full = array_map('intval', $o['order']);   // thứ tự đầy đủ; điểm neo và điểm không tọa độ đã giữ nguyên chỗ

        return [$this->option('optimize', 'Sắp lại thứ tự ngày ' . $day, [
            'Hiện tại ~' . round($o['beforeKm'], 1) . ' km, đề xuất ~' . round($o['afterKm'], 1) . ' km: bớt ~' . round($o['savedKm'], 1) . ' km, ~' . $o['savedMin'] . ' phút đi lại.',
            'Giữ nguyên mục có giờ cố định, đã khóa, có buổi và điểm đầu ngày.',
        ], [['op' => 'reorder', 'day' => $day, 'order' => $full]], $target)];
    }
}
