<?php

namespace App\Services\Planner;

use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\TravelAdvice;
use App\Services\EventDateService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Chưa biết đi đâu?": chọn THÁNG (và cảm hứng nếu muốn) rồi nhận danh sách điểm đến xếp theo độ hợp, mỗi nơi kèm lý do
 * viết từ dữ liệu thật (đánh giá mùa, khí hậu, lễ hội tính theo âm lịch, tour còn chỗ, điểm cảm hứng do biên tập đặt).
 * Planner thông thường bắt đầu từ địa điểm; ở đây bắt đầu từ thời điểm và cảm xúc.
 *
 * score() là hàm thuần để kiểm thử. Điểm chỉ dùng để xếp hạng, không phải "xác suất hợp".
 */
final class DestinationFinder
{
    public function __construct(private ?EventDateService $dates = null, private ?PlannerGate $gate = null)
    {
        $this->dates ??= new EventDateService();
        $this->gate ??= new PlannerGate();
    }

    /**
     * @param array{month:int,selected:list<string>,names?:array<string,string>,scores:array<string,int>,level:?string,levelNote:?string,
     *              climate:?array{tMin:float,tMax:float,rainyDays:float},events:list<array{name:string,when:string}>,
     *              tours:array{count:int,minPrice:?float},ready:bool} $f
     * @param array<string,mixed> $w  config('planner.finder')
     * @return array{score:int,reasons:list<string>,warns:list<string>}
     */
    public static function score(array $f, array $w): array
    {
        $reasons = [];
        $warns = [];
        $month = (int) $f['month'];
        $sel = array_values($f['selected']);

        // Hợp cảm hứng
        if ($sel) {
            $vals = [];
            $hits = [];
            foreach ($sel as $slug) {
                $s = (int) ($f['scores'][$slug] ?? 0);
                $vals[] = $s;
                if ($s >= 60) {
                    $hits[] = [$s, $f['names'][$slug] ?? $slug];
                }
            }
            $insp = array_sum($vals) / count($vals);
            usort($hits, fn($a, $b) => $b[0] <=> $a[0]);
            foreach (array_slice($hits, 0, 2) as [$s, $n]) {
                $reasons[] = "Hợp với “{$n}” ({$s}/100 theo đánh giá của nhóm biên tập)";
            }
            if (!$hits) {
                $warns[] = 'Ít hợp với cảm hứng bạn chọn';
            }
        } else {
            $insp = (float) $w['neutral_inspiration'];
        }

        // Mùa
        $lv = $f['level'] ?: 'unknown';
        $season = (float) ($w['season'][$lv] ?? $w['season']['unknown']);
        $note = $f['levelNote'] ? ': ' . rtrim((string) $f['levelNote'], '.') : '';
        if ($lv === 'best') {
            $reasons[] = "Tháng {$month} là mùa đẹp nhất{$note}";
        } elseif ($lv === 'ok') {
            $reasons[] = "Tháng {$month} đi được, không phải mùa đẹp nhất{$note}";
        } elseif ($lv === 'avoid') {
            $warns[] = "Tháng {$month} nên cân nhắc thời điểm khác{$note}";
        } else {
            $warns[] = "Chưa có đánh giá mùa cho tháng {$month}";
        }

        // Khí hậu
        if ($f['climate']) {
            $c = $f['climate'];
            $reasons[] = "Khí hậu tháng {$month} trung bình " . round($c['tMin']) . '–' . round($c['tMax']) . '°C, khoảng ' . round($c['rainyDays']) . ' ngày mưa';
        }

        $bonus = 0;
        $ev = array_slice($f['events'], 0, 2);
        if ($ev) {
            $bonus += count($ev) * (float) $w['event_bonus'];
            $reasons[] = 'Lễ hội trong tháng: ' . implode('; ', array_map(fn($e) => $e['name'] . ' (' . $e['when'] . ')', $ev));
        }
        if ($f['tours']['count'] > 0) {
            $bonus += (float) $w['tour_bonus'];
            $p = $f['tours']['minPrice'];
            $reasons[] = 'Có ' . $f['tours']['count'] . ' tour khởi hành trong tháng' . ($p ? ', giá từ ' . number_format($p, 0, ',', '.') . 'đ/người' : '');
        }
        if ($f['ready']) {
            $bonus += (float) $w['ready_bonus'];
            $reasons[] = 'Travela có thể tự xếp lịch trình cho nơi này';
        } else {
            $warns[] = 'Chưa đủ dữ liệu để tự xếp lịch, bạn vẫn lập thủ công được';
        }

        $score = $insp * (float) $w['w_inspiration'] + $season * (float) $w['w_season'] + $bonus;

        return ['score' => (int) max(0, min(100, round($score))), 'reasons' => $reasons, 'warns' => $warns];
    }

    /**
     * Đổi cảm hứng (Khám phá) thành sở thích của form Planner, tối đa 3, bỏ trùng.
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    public static function interestsFor(array $slugs, array $map): array
    {
        $out = [];
        foreach ($slugs as $s) {
            foreach ((array) ($map[$s] ?? []) as $i) {
                $out[$i] = true;
            }
        }

        return array_slice(array_keys($out), 0, 3);
    }

    // ------------------------------------------------------------------ nạp dữ liệu

    /**
     * @param array{month:int,inspirations?:list<string>,days?:?int,groupSize?:?int,budget?:?float,limit?:int} $in
     * @return array{month:int,items:list<array<string,mixed>>,budgetNote:?string,total:int}
     */
    public function recommend(array $in): array
    {
        $month = DestinationMonthBrief::validMonth($in['month'] ?? null);
        if ($month === null) {
            return ['month' => 0, 'items' => [], 'budgetNote' => null, 'total' => 0];
        }
        $w = (array) config('planner.finder');
        $limit = (int) ($in['limit'] ?? $w['limit']);
        $year = DestinationMonthBrief::defaultYear($month);

        $insNames = DB::table('tbl_inspirations')->where('isActive', 1)->pluck('name', 'slug')->all();
        $selected = array_values(array_intersect((array) ($in['inspirations'] ?? []), array_keys($insNames)));

        $dests = DB::table('tbl_destinations')->where('isActive', 1)->get(['destinationId', 'slug', 'name', 'province', 'domain'])->keyBy('destinationId');
        if ($dests->isEmpty()) {
            return ['month' => $month, 'items' => [], 'budgetNote' => null, 'total' => 0];
        }
        $ids = $dests->keys()->all();

        $insScores = [];
        foreach (DB::table('tbl_destination_inspiration as di')->join('tbl_inspirations as i', 'i.inspirationId', '=', 'di.inspirationId')
            ->whereIn('di.destinationId', $ids)->get(['di.destinationId', 'i.slug', 'di.score']) as $r) {
            $insScores[(int) $r->destinationId][$r->slug] = (int) $r->score;
        }
        $verdicts = (new TravelAdvice())->verdicts($ids, $month);
        $climate = [];
        foreach (DB::table('tbl_destination_climate')->whereIn('destinationId', $ids)->where('month', $month)->get() as $c) {
            if ($c->tMax !== null && $c->tMin !== null && $c->rainyDays !== null) {
                $climate[(int) $c->destinationId] = ['tMin' => (float) $c->tMin, 'tMax' => (float) $c->tMax, 'rainyDays' => (float) $c->rainyDays];
            }
        }
        $events = $this->eventsInMonth($month, $year);
        $tours = $this->toursInMonth($month, $year);
        $ready = [];
        foreach ($this->gate->coreStatus() as $s) {
            $ready[$s['destinationId']] = $s['ready'];
        }

        $items = [];
        foreach ($dests as $id => $d) {
            $id = (int) $id;
            $sc = self::score([
                'month' => $month, 'selected' => $selected, 'names' => $insNames, 'scores' => $insScores[$id] ?? [],
                'level' => $verdicts[$id]['level'] ?? null, 'levelNote' => $verdicts[$id]['note'] ?? null,
                'climate' => $climate[$id] ?? null, 'events' => $events[$id] ?? [], 'tours' => $tours[$id] ?? ['count' => 0, 'minPrice' => null],
                'ready' => $ready[$id] ?? false,
            ], $w);
            $items[] = [
                'destinationId' => $id, 'slug' => $d->slug, 'name' => $d->name, 'province' => $d->province, 'level' => $verdicts[$id]['level'] ?? null,
                'score' => $sc['score'], 'reasons' => $sc['reasons'], 'warns' => $sc['warns'], 'plannerReady' => $ready[$id] ?? false,
                'url' => route('destination.show', ['slug' => $d->slug]), 'monthUrl' => route('destination.month', ['slug' => $d->slug, 'n' => $month]),
            ];
        }
        // Tháng "nên cân nhắc" xuống cuối; còn lại theo điểm, hòa thì theo tên
        usort($items, fn($a, $b) => [$a['level'] === 'avoid', -$a['score'], $a['name']] <=> [$b['level'] === 'avoid', -$b['score'], $b['name']]);
        $total = count($items);
        $items = array_slice($items, 0, $limit);

        $interests = self::interestsFor($selected, (array) ($w['to_interests'] ?? []));
        $days = isset($in['days']) && $in['days'] ? (int) $in['days'] : null;
        $group = isset($in['groupSize']) && $in['groupSize'] ? (int) $in['groupSize'] : null;
        $budget = isset($in['budget']) && $in['budget'] ? (float) $in['budget'] : null;
        foreach ($items as &$it) {
            $it['planUrl'] = route('planner.suggest', array_filter([
                'diem-den' => $it['slug'], 'thang' => $month, 'ngay' => $days, 'nguoi' => $group,
                'ngan-sach' => $budget !== null ? (int) $budget : null, 'so-thich' => $interests ?: null,
            ], fn($v) => $v !== null));
        }
        unset($it);

        return ['month' => $month, 'items' => $items, 'budgetNote' => self::budgetNote($budget, $days, $group), 'total' => $total];
    }

    /** Ngân sách bình quân người/ngày so với mức tham khảo trong config('planner.costs'). null nếu thiếu số liệu. */
    public static function budgetNote(?float $budget, ?int $days, ?int $group): ?string
    {
        if (!$budget || !$days || !$group) {
            return null;
        }
        $per = $budget / ($days * $group);
        $t = (array) config('planner.costs.tierBudgetPerPersonDay', ['low' => 600000, 'mid' => 1500000]);
        $label = $per < $t['low'] ? 'tiết kiệm' : ($per < $t['mid'] ? 'vừa phải' : 'thoải mái');

        return 'Ngân sách khoảng ' . number_format($per, 0, ',', '.') . 'đ/người/ngày, thuộc mức ' . $label . ' theo giá tham khảo của Travela (ước tính, bạn có thể sửa sau).';
    }

    /** @return array<int,list<array{name:string,when:string}>> */
    private function eventsInMonth(int $month, int $year): array
    {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month')->format('Y-m-d');
        $out = [];
        foreach (DB::table('tbl_events')->where('reviewStatus', 'published')->whereNotNull('destinationId')->orderByDesc('isMustTry')->orderBy('name')->get() as $e) {
            foreach ($this->dates->upcoming($e, $first, 6) as $o) {
                if ($o['start'] <= $last && $o['end'] >= $first->format('Y-m-d')) {
                    $s = new DateTimeImmutable($o['start']);
                    $when = !empty($o['approx']) ? 'khoảng tháng ' . $s->format('n') : ($o['start'] === $o['end'] ? $s->format('d/m') : $s->format('d/m') . '–' . date('d/m', strtotime($o['end'])));
                    $out[(int) $e->destinationId][] = ['name' => $e->name, 'when' => $when];
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<int,array{count:int,minPrice:?float}> */
    private function toursInMonth(int $month, int $year): array
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $out = [];
        $q = DB::table('tbl_tours')->whereNotNull('destinationId')->where('availability', 1)->where('quantity', '>', 0)
            ->whereBetween('startDate', [$first, date('Y-m-t', strtotime($first))])->whereDate('startDate', '>=', date('Y-m-d'));
        foreach ($q->selectRaw('destinationId, COUNT(*) AS c, MIN(priceAdult) AS p')->groupBy('destinationId')->get() as $r) {
            $out[(int) $r->destinationId] = ['count' => (int) $r->c, 'minPrice' => $r->p !== null ? (float) $r->p : null];
        }

        return $out;
    }
}
