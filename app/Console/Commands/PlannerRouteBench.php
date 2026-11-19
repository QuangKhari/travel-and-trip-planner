<?php

namespace App\Console\Commands;

use App\Services\Content\OpeningHours;
use App\Services\Planner\Route\DayOptimizer;
use App\Services\Planner\Route\RouteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Benchmark tối ưu thứ tự ngày (Đợt D, AR4). Sinh N ngày ngẫu nhiên có hạt giống cố định (lặp lại được) từ dữ liệu
 * các điểm đến lõi: 5–9 nơi tham quan có giờ mở cửa, xáo thứ tự như người dùng tự thêm; một phần có chỗ ở đầu / cuối ngày,
 * một phần có một mục đặt giờ cố định. So bốn cách trên CÙNG hàm chấm (DaySchedule):
 *   A. thứ tự nhập  ·  B. tối ưu theo km (bộ cũ: láng giềng gần nhất + 2-opt)  ·  C. tối ưu theo lịch (DayOptimizer)
 *   D. tối ưu vét cạn (chỉ khi ≤ --exact mục tự do) để đo khoảng cách của C tới lời giải tối ưu.
 *
 *   php artisan planner:route-bench --n=30 --seed=42 --csv=storage/app/bench/route-30.csv
 */
class PlannerRouteBench extends Command
{
    protected $signature = 'planner:route-bench {--n=30} {--seed=42} {--exact=7 : vét cạn khi số mục tự do ≤ giá trị này} {--budget=150 : ms cho tìm kiếm cục bộ} {--csv=}';

    protected $description = 'So thứ tự nhập / tối ưu theo km / tối ưu theo lịch / tối ưu vét cạn trên N ngày ngẫu nhiên';

    public function handle(OpeningHours $oh): int
    {
        mt_srand((int) $this->option('seed'));
        $slugs = (array) config('core_destinations.slugs', []);
        $dests = DB::table('tbl_destinations')->whereIn('slug', $slugs)->get(['destinationId', 'slug', 'name', 'latitude', 'longitude'])->values();
        $pool = [];
        foreach ($dests as $d) {
            $places = DB::table('tbl_external_places')->where('destinationId', $d->destinationId)->where('reviewStatus', 'published')
                ->where('category', 'sight')->whereNotNull('latitude')->orderBy('placeId')
                ->get(['placeId', 'name', 'kind', 'latitude', 'longitude', 'durationMinutes'])->all();
            if (count($places) >= 9) {
                $pool[] = [$d, $places];
            }
        }
        if (!$pool) {
            $this->error('Chưa có điểm đến lõi nào đủ dữ liệu (chạy places:audit --core).');

            return self::FAILURE;
        }
        $route = RouteService::make();
        $n = (int) $this->option('n');
        $rows = [];
        for ($k = 0; $k < $n; $k++) {
            [$d, $places] = $pool[$k % count($pool)];
            $count = mt_rand(5, 9);
            $pick = $this->sample($places, $count);
            $items = array_map(fn($p) => ['type' => 'place', 'refId' => (int) $p->placeId, 'name' => $p->name, 'kind' => $p->kind,
                'lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'durationMin' => $p->durationMinutes ? (int) $p->durationMinutes : null,
                'startMin' => null, 'slot' => null], $pick);
            $hours = $oh->forItems($items);
            foreach ($items as $i => $it) {
                $items[$i]['hours'] = $hours['place:' . $it['refId']] ?? null;
            }
            if (mt_rand(1, 100) <= 40) {   // một mục đặt giờ cố định
                $f = mt_rand(1, count($items) - 1);
                $items[$f]['startMin'] = 60 * mt_rand(9, 15);
            }
            $ctx = null;
            if (mt_rand(1, 100) <= 50) {   // có chỗ ở: đi từ khách sạn, tối về khách sạn
                $h = ['name' => 'Khách sạn', 'lat' => (float) $d->latitude + (mt_rand(-20, 20) / 1000), 'lng' => (float) $d->longitude + (mt_rand(-20, 20) / 1000)];
                $ctx = ['from' => $h, 'to' => $h, 'arrive' => null, 'leave' => null, 'blocks' => []];
            }
            $dow = mt_rand(1, 7);
            $keepFirst = $ctx === null;   // không có chỗ ở thì giữ điểm đầu như người dùng chọn
            $opt = new DayOptimizer(1, $items, $route, null, $dow, $ctx);

            $a = $opt->evaluate(range(0, count($items) - 1));
            $b = $opt->evaluate($this->kmOrder($route, $items, $ctx, $keepFirst, $opt));
            $c = $opt->optimize($keepFirst, (int) $this->option('budget'));
            $x = (new DayOptimizer(1, $items, $route, null, $dow, $ctx))->exact($keepFirst, (int) $this->option('exact'));
            $free = count(array_filter(array_keys($items), fn($i) => $opt->isFree($items[$i], $i, $keepFirst)));
            $rows[] = [
                'case' => $k + 1, 'dest' => $d->slug, 'stops' => count($items), 'free' => $free, 'hotel' => $ctx ? 1 : 0, 'fixed' => (int) collect($items)->contains(fn($i) => $i['startMin'] !== null),
                'A_min' => $a['travelMin'], 'A_viol' => $a['hard'] + $a['hours'], 'B_min' => $b['travelMin'], 'B_viol' => $b['hard'] + $b['hours'],
                'C_min' => $c['after']['travelMin'], 'C_viol' => $c['after']['hard'] + $c['after']['hours'], 'C_ms' => $c['ms'], 'C_evals' => $c['evals'],
                'D_min' => $x ? $x['after']['travelMin'] : null, 'D_viol' => $x ? $x['after']['hard'] + $x['after']['hours'] : null, 'D_ms' => $x['ms'] ?? null,
                'C_cost' => round($c['after']['cost'], 1), 'D_cost' => $x ? round($x['after']['cost'], 1) : null,
            ];
        }

        $this->table(array_keys($rows[0]), array_map(fn($r) => array_map(fn($v) => $v ?? '—', $r), $rows));
        $sum = fn($k) => array_sum(array_column($rows, $k));
        $exact = array_values(array_filter($rows, fn($r) => $r['D_min'] !== null));
        $optimal = count(array_filter($exact, fn($r) => abs($r['C_cost'] - $r['D_cost']) < 0.01));
        $gap = $exact ? array_sum(array_map(fn($r) => $r['D_min'] ? ($r['C_min'] - $r['D_min']) / $r['D_min'] : 0, array_filter($exact, fn($r) => $r['C_viol'] === $r['D_viol']))) / max(1, count(array_filter($exact, fn($r) => $r['C_viol'] === $r['D_viol']))) : null;
        $ms = array_column($rows, 'C_ms');
        sort($ms);
        $pct = fn(array $a, float $p) => $a[(int) min(count($a) - 1, floor($p * (count($a) - 1) + 0.5))];
        $this->newLine();
        $this->table(['Cách', 'Tổng phút di chuyển', 'Số vi phạm (giờ / xung đột)', 'Ngày có vi phạm'], [
            ['A. Thứ tự nhập', $sum('A_min'), $sum('A_viol'), count(array_filter($rows, fn($r) => $r['A_viol'] > 0))],
            ['B. Tối ưu theo km', $sum('B_min'), $sum('B_viol'), count(array_filter($rows, fn($r) => $r['B_viol'] > 0))],
            ['C. Tối ưu theo lịch', $sum('C_min'), $sum('C_viol'), count(array_filter($rows, fn($r) => $r['C_viol'] > 0))],
        ]);
        $this->line(sprintf(
            'So với thứ tự nhập, C bớt %.1f%% phút di chuyển; so với tối ưu theo km, C bớt %.1f%% phút và %d vi phạm.',
            $sum('A_min') ? 100 * ($sum('A_min') - $sum('C_min')) / $sum('A_min') : 0,
            $sum('B_min') ? 100 * ($sum('B_min') - $sum('C_min')) / $sum('B_min') : 0,
            $sum('B_viol') - $sum('C_viol'),
        ));
        if ($exact) {
            $this->line(sprintf(
                'Vét cạn được %d/%d ngày: C đạt đúng tối ưu %d ngày; khoảng cách trung bình tới tối ưu (cùng số vi phạm) %.1f%% phút di chuyển.',
                count($exact),
                count($rows),
                $optimal,
                100 * (float) $gap,
            ));
        }
        $this->line(sprintf('Thời gian C: trung vị %.1f ms, p95 %.1f ms, lớn nhất %.1f ms.', $pct($ms, 0.5), $pct($ms, 0.95), end($ms)));

        if ($csv = $this->option('csv')) {
            @mkdir(dirname($csv), 0777, true);
            $fh = fopen($csv, 'w');
            fputcsv($fh, array_keys($rows[0]), ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($fh, $r, ',', '"', '');
            }
            fclose($fh);
            $this->info('Đã ghi ' . $csv);
        }

        return self::SUCCESS;
    }

    private function sample(array $a, int $n): array
    {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }

        return array_slice($a, 0, $n);
    }

    /** Thứ tự của bộ tối ưu cũ theo km (RouteService::optimize), cùng quy tắc neo. */
    private function kmOrder(RouteService $route, array $items, ?array $ctx, bool $keepFirst, DayOptimizer $opt): array
    {
        $stops = [];
        if ($ctx) {
            $stops[] = ['key' => '__from', 'lat' => $ctx['from']['lat'], 'lng' => $ctx['from']['lng'], 'anchor' => true];
        }
        foreach ($items as $i => $it) {
            $stops[] = ['key' => (string) $i, 'lat' => $it['lat'], 'lng' => $it['lng'], 'anchor' => !$opt->isFree($it, $i, $keepFirst)];
        }
        if ($ctx) {
            $stops[] = ['key' => '__to', 'lat' => $ctx['to']['lat'], 'lng' => $ctx['to']['lng'], 'anchor' => true];
        }
        $o = $route->optimize($stops);

        return array_values(array_map('intval', array_filter($o['order'], fn($k) => !str_starts_with($k, '__'))));
    }
}
