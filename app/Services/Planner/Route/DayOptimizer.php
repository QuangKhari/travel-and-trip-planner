<?php

namespace App\Services\Planner\Route;

use App\Services\Planner\Health\DaySchedule;
use App\Services\Planner\PlannerRules;

/**
 * Tối ưu thứ tự một ngày theo LỊCH, không chỉ theo km (Đợt D, AR1–AR2).
 *
 * Hàm mục tiêu (nhỏ hơn là tốt hơn), tính bằng chính DaySchedule nên khớp với lịch người dùng thấy:
 *   1. khả thi trước: mỗi xung đột cứng (trùng giờ, không kịp di chuyển, tới sau giờ phải đi, chồng giờ chuyển thành phố) 10 000;
 *      đến lúc đóng cửa 5 000; tới sớm / về muộn so với giờ mở cửa 2 000; mỗi phút tràn quá cuối ngày 50;
 *   2. rồi tối thiểu tổng phút di chuyển (kể cả chặng từ / về chỗ ở), phụ là phút chờ (cửa chưa mở, giờ cố định).
 * Thuật toán: thứ tự hiện tại và láng giềng gần nhất (theo km) làm điểm xuất phát → tìm kiếm cục bộ
 *   2-opt (đảo một đoạn các mục tự do), Or-opt (dời 1–3 mục tự do liền nhau sang chỗ khác), đổi chỗ hai mục,
 *   lặp tới khi không cải thiện hoặc hết ngân sách thời gian. Không bao giờ trả kết quả kém hơn thứ tự hiện tại.
 * Mục KHÔNG được đổi chỗ (neo): có giờ cố định, đã khóa, đã chọn buổi, lưu trú / ăn uống / di chuyển, và mục đầu ngày khi giữ điểm đầu.
 * Ma trận đường đi tính một lần cho cả ngày (MatrixCache), nên mỗi lần thử chỉ tốn phần xếp giờ.
 */
final class DayOptimizer
{
    public const W_HARD = 10000;
    public const W_CLOSED = 5000;
    public const W_HOURS = 2000;
    public const W_OVERRUN = 50;
    public const W_WAIT = 0.2;

    private PlannerRules $rules;
    private RouteService $fast;
    public int $evals = 0;

    /**
     * @param list<array> $items mục của ngày theo thứ tự hiện tại (dạng payload: lat, lng, startMin, durationMin, hours, slot, locked...)
     */
    public function __construct(private int $day, private array $items, RouteService $route, ?PlannerRules $rules = null,
        private ?int $dow = null, private ?array $ctx = null)
    {
        $this->rules = $rules ?? PlannerRules::fromConfig();
        $this->fast = MatrixCache::wrap($route, $this->points());
    }

    /** Điểm có tọa độ của ngày + chỗ ở đầu / cuối ngày: đủ để tính sẵn mọi chặng. */
    private function points(): array
    {
        $pts = [];
        foreach ($this->items as $it) {
            if (($it['lat'] ?? null) !== null && ($it['lng'] ?? null) !== null) {
                $pts[] = [(float) $it['lat'], (float) $it['lng']];
            }
        }
        foreach (['from', 'to'] as $k) {
            if (isset($this->ctx[$k]['lat'], $this->ctx[$k]['lng'])) {
                $pts[] = [(float) $this->ctx[$k]['lat'], (float) $this->ctx[$k]['lng']];
            }
        }

        return $pts;
    }

    public function isFree(array $it, int $index, bool $keepFirst): bool
    {
        if ($index === 0 && $keepFirst) {
            return false;
        }
        $k = $this->rules->kindOf($it);

        return ($it['startMin'] ?? null) === null && empty($it['locked']) && empty($it['slot'])
            && ($it['lat'] ?? null) !== null && !in_array($k, ['lodging', 'meal', 'transport'], true);
    }

    /** @return array{cost:float, travelMin:int, travelKm:float, waitMin:int, hard:int, hours:int, overrun:int, end:int} */
    public function evaluate(array $order): array
    {
        $this->evals++;
        $list = array_map(fn($i) => $this->items[$i], $order);
        $s = new DaySchedule($this->day, $list, $this->fast, $this->rules, $this->dow, $this->ctx);
        $hard = $hours = $closed = $wait = 0;
        foreach ($s->rows as $r) {
            $hard += $r['conflict'] !== null ? 1 : 0;
            if ($r['hours'] === 'closed') {
                $closed++;
            } elseif ($r['hours'] !== null) {
                $hours++;
            }
            $wait += (int) ($r['waitMin'] ?? 0);
        }
        $limit = isset($this->ctx['leave']) ? (int) $this->ctx['leave'] : $this->rules->dayEnd();
        $overrun = max(0, $s->end + (int) ($s->endLeg['min'] ?? 0) - $limit);
        $cost = $hard * self::W_HARD + $closed * self::W_CLOSED + $hours * self::W_HOURS + $overrun * self::W_OVERRUN
            + $s->travelMin + $wait * self::W_WAIT;

        return ['cost' => $cost, 'travelMin' => $s->travelMin, 'travelKm' => round($s->travelKm, 2), 'waitMin' => $wait,
            'hard' => $hard, 'hours' => $hours + $closed, 'overrun' => $overrun, 'end' => $s->end];
    }

    /**
     * @param int $budgetMs ngân sách thời gian cho tìm kiếm cục bộ
     * @return array{changed:bool, order:list<int>, before:array, after:array, ms:float, evals:int, method:string}
     */
    public function optimize(bool $keepFirst = true, int $budgetMs = 150, bool $localSearch = true): array
    {
        $t0 = microtime(true);
        $this->evals = 0;
        $n = count($this->items);
        $cur = range(0, $n - 1);
        $free = [];                     // các CHỖ (vị trí) được phép đổi
        foreach ($this->items as $i => $it) {
            if ($this->isFree($it, $i, $keepFirst)) {
                $free[] = $i;
            }
        }
        $before = $this->evaluate($cur);
        $best = $cur;
        $bestEval = $before;
        if (count($free) < 2) {
            return $this->result(false, $cur, $before, $before, $t0, 'none');
        }

        // điểm xuất phát thứ hai: láng giềng gần nhất trên các chỗ tự do (theo km, như bộ tối ưu cũ)
        $nn = $this->nearestNeighbour($cur, $free);
        $nnEval = $this->evaluate($nn);
        if ($nnEval['cost'] + 1e-9 < $bestEval['cost']) {
            [$best, $bestEval] = [$nn, $nnEval];
        }
        $method = 'nn';
        if ($localSearch) {
            [$best, $bestEval] = $this->localSearch($best, $bestEval, $free, $t0, $budgetMs);
            $method = 'nn+2opt+oropt';
        }
        $changed = $best !== $cur && $bestEval['cost'] + 0.5 < $before['cost'];

        return $this->result($changed, $changed ? $best : $cur, $before, $changed ? $bestEval : $before, $t0, $method);
    }

    /** Lời giải tối ưu bằng vét cạn (chỉ cho benchmark, số mục tự do nhỏ). */
    public function exact(bool $keepFirst = true, int $maxFree = 7): ?array
    {
        $t0 = microtime(true);
        $this->evals = 0;
        $cur = range(0, count($this->items) - 1);
        $free = [];
        foreach ($this->items as $i => $it) {
            if ($this->isFree($it, $i, $keepFirst)) {
                $free[] = $i;
            }
        }
        if (count($free) > $maxFree) {
            return null;
        }
        $before = $this->evaluate($cur);
        $best = $cur;
        $bestEval = $before;
        $vals = array_map(fn($s) => $cur[$s], $free);
        foreach (self::permutations($vals) as $perm) {
            $cand = $cur;
            foreach ($free as $k => $slot) {
                $cand[$slot] = $perm[$k];
            }
            $e = $this->evaluate($cand);
            if ($e['cost'] + 1e-9 < $bestEval['cost']) {
                [$best, $bestEval] = [$cand, $e];
            }
        }

        return $this->result($best !== $cur, $best, $before, $bestEval, $t0, 'exact');
    }

    private function result(bool $changed, array $order, array $before, array $after, float $t0, string $method): array
    {
        return ['changed' => $changed, 'order' => array_values($order), 'before' => $before, 'after' => $after,
            'ms' => round((microtime(true) - $t0) * 1000, 1), 'evals' => $this->evals, 'method' => $method];
    }

    private function nearestNeighbour(array $cur, array $free): array
    {
        $pos = fn($i) => [(float) $this->items[$i]['lat'], (float) $this->items[$i]['lng']];
        $pool = array_map(fn($s) => $cur[$s], $free);
        $nn = $cur;
        $prevPt = isset($this->ctx['from']['lat']) ? [(float) $this->ctx['from']['lat'], (float) $this->ctx['from']['lng']] : null;
        $freeSet = array_flip($free);
        foreach ($cur as $slot => $i) {
            if (!isset($freeSet[$slot])) {
                if (($this->items[$nn[$slot]]['lat'] ?? null) !== null) {
                    $prevPt = $pos($nn[$slot]);
                }
                continue;
            }
            $pick = 0;
            if ($prevPt !== null) {
                $bd = INF;
                foreach ($pool as $k => $cand) {
                    $d = HaversineProvider::straightKm($prevPt, $pos($cand));
                    if ($d < $bd) {
                        [$bd, $pick] = [$d, $k];
                    }
                }
            }
            $nn[$slot] = $pool[$pick];
            $prevPt = $pos($pool[$pick]);
            array_splice($pool, $pick, 1);
        }

        return $nn;
    }

    /** 2-opt + Or-opt + đổi chỗ, cải thiện đầu tiên, trong ngân sách thời gian. */
    private function localSearch(array $best, array $bestEval, array $free, float $t0, int $budgetMs): array
    {
        $m = count($free);
        $deadline = $t0 + $budgetMs / 1000;
        $try = function (array $cand) use (&$best, &$bestEval): bool {
            if ($cand === $best) {
                return false;
            }
            $e = $this->evaluate($cand);
            if ($e['cost'] + 1e-9 < $bestEval['cost']) {
                [$best, $bestEval] = [$cand, $e];

                return true;
            }

            return false;
        };
        $guard = 0;
        do {
            $improved = false;
            // 2-opt: đảo các mục tự do trong đoạn [a, b]
            for ($a = 0; $a < $m - 1 && !$improved; $a++) {
                for ($b = $a + 1; $b < $m && !$improved; $b++) {
                    $inner = array_slice($free, $a, $b - $a + 1);
                    $vals = array_reverse(array_map(fn($s) => $best[$s], $inner));
                    $cand = $best;
                    foreach ($inner as $k => $s) {
                        $cand[$s] = $vals[$k];
                    }
                    $improved = $try($cand);
                    if (microtime(true) > $deadline) {
                        break 3;
                    }
                }
            }
            // Or-opt: lấy 1–3 mục tự do liền nhau, chèn vào chỗ tự do khác
            for ($len = 1; $len <= 3 && !$improved; $len++) {
                for ($a = 0; $a + $len <= $m && !$improved; $a++) {
                    for ($to = 0; $to <= $m - $len && !$improved; $to++) {
                        if ($to === $a) {
                            continue;
                        }
                        $seq = array_map(fn($s) => $best[$s], $free);
                        $seg = array_splice($seq, $a, $len);
                        array_splice($seq, $to, 0, $seg);
                        $cand = $best;
                        foreach ($free as $k => $s) {
                            $cand[$s] = $seq[$k];
                        }
                        $improved = $try($cand);
                        if (microtime(true) > $deadline) {
                            break 4;
                        }
                    }
                }
            }
            // đổi chỗ hai mục tự do
            for ($a = 0; $a < $m - 1 && !$improved; $a++) {
                for ($b = $a + 1; $b < $m && !$improved; $b++) {
                    $cand = $best;
                    [$cand[$free[$a]], $cand[$free[$b]]] = [$cand[$free[$b]], $cand[$free[$a]]];
                    $improved = $try($cand);
                }
            }
        } while ($improved && ++$guard < 200 && microtime(true) <= $deadline);

        return [$best, $bestEval];
    }

    /** @return \Generator<list<int>> */
    public static function permutations(array $a): \Generator
    {
        $n = count($a);
        $c = array_fill(0, $n, 0);
        yield $a;
        $i = 0;
        while ($i < $n) {
            if ($c[$i] < $i) {
                $j = $i % 2 === 0 ? 0 : $c[$i];
                [$a[$j], $a[$i]] = [$a[$i], $a[$j]];
                yield $a;
                $c[$i]++;
                $i = 0;
            } else {
                $c[$i] = 0;
                $i++;
            }
        }
    }
}
