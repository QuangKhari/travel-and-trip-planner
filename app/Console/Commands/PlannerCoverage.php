<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Độ phủ dữ liệu cho bộ xếp lịch theo điểm đến: bao nhiêu nơi đủ để xếp một chuyến tử tế (giờ mở cửa, giá, trong nhà / ngoài trời,
 * thời lượng, vị trí, đủ chỗ ăn). Thiếu dữ liệu thì mọi thuật toán đều trông như ghi chú, nên đo trước khi demo.
 *   php artisan planner:coverage               (3 điểm đến demo: Hà Nội, Hội An, Đà Lạt)
 *   php artisan planner:coverage --dest=17 --dest=22   |   --all   |   --csv=storage/app/coverage.csv
 */
class PlannerCoverage extends Command
{
    protected $signature = 'planner:coverage {--dest=* : mã điểm đến} {--all : mọi điểm đến} {--csv= : ghi kết quả ra CSV}';

    protected $description = 'Đo độ phủ dữ liệu của Trip Planner theo điểm đến (giờ mở cửa, giá, trong nhà, thời lượng, chỗ ăn)';

    public const DEMO = [1, 18, 25];

    /** Ngưỡng "đủ để demo": tối thiểu nơi tham quan, chỗ ăn, và tỷ lệ có giờ mở cửa / giá / trong-ngoài */
    public const NEED = ['sights' => 20, 'eat' => 10, 'hours' => 0.8, 'price' => 0.8, 'indoor' => 0.8, 'indoorCount' => 5];

    public function handle(): int
    {
        $ids = $this->option('all') ? DB::table('tbl_destinations')->orderBy('destinationId')->pluck('destinationId')->all()
            : (array_map('intval', (array) $this->option('dest')) ?: self::DEMO);
        $rows = array_map(fn($id) => self::measure((int) $id), $ids);
        $this->table(['#', 'Điểm đến', 'Tham quan', 'Ăn/uống', 'Trải nghiệm', 'Có giờ', 'Có giá', 'Biết trong/ngoài', 'Trong nhà', 'Có thời lượng', 'Chưa xuất bản', 'Đủ demo'],
            array_map(fn($r) => [$r['id'], $r['name'], $r['sights'], $r['eat'], $r['experiences'], $r['hoursPct'] . '%', $r['pricePct'] . '%',
                $r['indoorPct'] . '%', $r['indoorCount'], $r['durationPct'] . '%', $r['drafts'], $r['ok'] ? 'đủ' : 'thiếu: ' . implode(', ', $r['gaps'])], $rows));
        if ($path = $this->option('csv')) {
            $f = fopen(base_path($path), 'w');
            fputcsv($f, array_keys($rows[0] ?? []));
            foreach ($rows as $r) {
                $r['gaps'] = implode('; ', $r['gaps']);
                fputcsv($f, $r);
            }
            fclose($f);
            $this->info("Đã ghi $path");
        }

        return self::SUCCESS;
    }

    /** @return array{id:int,name:string,sights:int,eat:int,experiences:int,hoursPct:int,pricePct:int,indoorPct:int,indoorCount:int,durationPct:int,drafts:int,ok:bool,gaps:list<string>} */
    public static function measure(int $id): array
    {
        $eatKinds = (array) config('planner.kind_groups.eat', []);
        $inKinds = (array) config('planner.indoor_place_kinds', []);
        $extra = Schema::hasColumn('tbl_external_places', 'durationMinutes');
        $places = DB::table('tbl_external_places')->where('destinationId', $id)->where('reviewStatus', 'published')->get();
        $exps = DB::table('tbl_experiences')->where('destinationId', $id)->where('reviewStatus', 'published')->get();
        $timed = DB::table('tbl_opening_hours')->where(fn($q) => $q->where('refType', 'place')->whereIn('refId', $places->pluck('placeId'))
            ->orWhere(fn($q) => $q->where('refType', 'experience')->whereIn('refId', $exps->pluck('experienceId'))))
            ->select('refType', 'refId')->distinct()->get()->count();
        $n = $places->count() + $exps->count();
        $eat = $places->filter(fn($p) => in_array($p->kind, $eatKinds, true))->count();
        $price = $places->filter(fn($p) => $extra && $p->priceMin !== null)->count() + $exps->filter(fn($x) => $x->priceMin !== null)->count();
        $known = $places->filter(fn($p) => ($extra && $p->indoor !== null) || in_array($p->kind, $inKinds, true))->count() + $exps->filter(fn($x) => $x->indoor !== null)->count();
        $indoor = $places->filter(fn($p) => ($extra && $p->indoor !== null) ? (bool) $p->indoor && !in_array($p->kind, $eatKinds, true) : in_array($p->kind, $inKinds, true))->count()
            + $exps->filter(fn($x) => (bool) $x->indoor)->count();
        $dur = $places->filter(fn($p) => $extra && !empty($p->durationMinutes))->count() + $exps->filter(fn($x) => !empty($x->durationMinutes))->count();
        $pct = fn($k) => $n ? (int) round(100 * $k / $n) : 0;
        $r = ['id' => $id, 'name' => (string) DB::table('tbl_destinations')->where('destinationId', $id)->value('name'), 'sights' => $places->count() - $eat + $exps->count(),
            'eat' => $eat, 'experiences' => $exps->count(), 'hoursPct' => $pct($timed), 'pricePct' => $pct($price), 'indoorPct' => $pct($known), 'indoorCount' => $indoor,
            'durationPct' => $pct($dur), 'drafts' => DB::table('tbl_external_places')->where('destinationId', $id)->where('reviewStatus', '!=', 'published')->count(), 'ok' => false, 'gaps' => []];
        $n2 = self::NEED;
        foreach ([['sights', $r['sights'] >= $n2['sights'], 'nơi tham quan < ' . $n2['sights']], ['eat', $eat >= $n2['eat'], 'chỗ ăn < ' . $n2['eat']],
            ['hours', $n && $timed / $n >= $n2['hours'], 'giờ mở cửa < 80%'], ['price', $n && $price / $n >= $n2['price'], 'giá < 80%'],
            ['indoor', $n && $known / $n >= $n2['indoor'], 'trong/ngoài < 80%'], ['indoorCount', $indoor >= $n2['indoorCount'], 'ít chỗ trong nhà cho ngày mưa']] as [$k, $okay, $msg]) {
            if (!$okay) {
                $r['gaps'][] = $msg;
            }
        }
        $r['ok'] = !$r['gaps'];

        return $r;
    }
}
