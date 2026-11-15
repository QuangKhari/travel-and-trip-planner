<?php

namespace App\Console\Commands;

use App\Services\Content\PlaceImport;
use App\Support\Geo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rà chất lượng kho địa điểm (Đợt D mục D2): trùng, sai tọa độ, thiếu giờ / thời lượng / nguồn, và đủ số lượng theo chuẩn điểm đến lõi.
 *   php artisan places:audit --core
 *   php artisan places:audit --dest=da-lat --all-status --csv=storage/app/audit.csv
 * Chỉ đọc, không sửa gì. Lỗi "nặng" (trùng, tọa độ sai) phải sửa trước khi demo; "nhẹ" là thiếu thông tin.
 */
class PlacesAudit extends Command
{
    protected $signature = 'places:audit {--core : Các điểm đến lõi (config/core_destinations.php)} {--dest=* : slug} {--all-status : Tính cả nơi nháp / đã duyệt chưa xuất bản} {--csv= : Ghi danh sách vấn đề ra CSV} {--strict : Trả mã lỗi khi còn lỗi nặng hoặc thiếu số lượng}';

    protected $description = 'Rà chất lượng dữ liệu địa điểm: trùng, tọa độ, giờ, thời lượng, nguồn, số lượng';

    public function handle(): int
    {
        $slugs = $this->option('core') ? (array) config('core_destinations.slugs', []) : (array) $this->option('dest');
        $q = DB::table('tbl_destinations');
        if ($slugs) {
            $q->whereIn('slug', $slugs);
        }
        $dests = $q->orderBy('name')->get(['destinationId', 'slug', 'name', 'latitude', 'longitude']);
        if ($dests->isEmpty()) {
            $this->error('Không có điểm đến nào khớp.');

            return self::FAILURE;
        }
        $minS = (int) config('core_destinations.min_sight', 15);
        $minF = (int) config('core_destinations.min_food', 8);
        $minI = (int) config('core_destinations.min_indoor', 0);
        $issues = [];
        $rows = [];
        $bad = 0;
        foreach ($dests as $d) {
            $r = self::auditDestination($d, (bool) $this->option('all-status'));
            foreach ($r['issues'] as $i) {
                $issues[] = ['dest' => $d->slug] + $i;
            }
            $heavy = count(array_filter($r['issues'], fn($i) => $i['level'] === 'nặng'));
            $short = $r['sight'] < $minS || $r['food'] < $minF || $r['indoor'] < $minI;
            $bad += ($heavy || $short) ? 1 : 0;
            $rows[] = [$d->name, $d->slug, $r['sight'] . ($r['sight'] < $minS ? " (<$minS)" : ''), $r['food'] . ($r['food'] < $minF ? " (<$minF)" : ''), $r['indoor'] . ($r['indoor'] < $minI ? " (<$minI)" : ''),
                $r['pctHours'] . '%', $r['pctDur'] . '%', $r['pctSource'] . '%', $heavy, count($r['issues']) - $heavy,
                ($heavy || $short) ? 'CHƯA ĐẠT' : 'đạt'];
        }
        $this->table(['Điểm đến', 'slug', 'Tham quan', 'Ăn uống', 'Trong nhà', 'Có giờ', 'Có thời lượng', 'Có nguồn', 'Lỗi nặng', 'Lỗi nhẹ', 'Kết quả'], $rows);
        foreach (array_slice(array_filter($issues, fn($i) => $i['level'] === 'nặng'), 0, 30) as $i) {
            $this->line("  [{$i['dest']}] #{$i['placeId']} {$i['name']}: {$i['msg']}");
        }
        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['dest', 'placeId', 'name', 'level', 'code', 'msg'], ',', '"', '');
            foreach ($issues as $i) {
                fputcsv($fh, [$i['dest'], $i['placeId'], $i['name'], $i['level'], $i['code'], $i['msg']], ',', '"', '');
            }
            fclose($fh);
            $this->info('Đã ghi ' . count($issues) . ' vấn đề vào ' . $csv);
        }
        $this->line($bad ? "$bad điểm đến chưa đạt chuẩn ({$minS} tham quan, {$minF} ăn uống, {$minI} nơi trong nhà, không lỗi nặng)." : 'Mọi điểm đến đạt chuẩn.');

        return ($bad && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{sight:int, indoor:int, food:int, pctHours:int, pctDur:int, pctSource:int, issues:list<array{placeId:int,name:string,level:string,code:string,msg:string}>}
     */
    public static function auditDestination(object $d, bool $allStatus = false): array
    {
        $q = DB::table('tbl_external_places as p')->where('p.destinationId', $d->destinationId)
            ->leftJoin('tbl_opening_hours as h', function ($j) {
                $j->on('h.refId', '=', 'p.placeId')->where('h.refType', '=', 'place');
            })
            ->select('p.placeId', 'p.name', 'p.category', 'p.kind', 'p.indoor', 'p.latitude', 'p.longitude', 'p.durationMinutes', 'p.factsSource', 'p.source', 'p.reviewStatus')
            ->selectRaw('MAX(h.refId) as hasHours')
            ->groupBy('p.placeId', 'p.name', 'p.category', 'p.kind', 'p.indoor', 'p.latitude', 'p.longitude', 'p.durationMinutes', 'p.factsSource', 'p.source', 'p.reviewStatus');
        if (!$allStatus) {
            $q->where('p.reviewStatus', 'published');
        }
        $places = $q->get();
        $issues = [];
        $add = function ($p, string $level, string $code, string $msg) use (&$issues) {
            $issues[] = ['placeId' => (int) $p->placeId, 'name' => (string) $p->name, 'level' => $level, 'code' => $code, 'msg' => $msg];
        };
        $n = max(1, $places->count());
        $withHours = $withDur = $withSource = 0;
        foreach ($places as $p) {
            $lat = (float) $p->latitude;
            $lng = (float) $p->longitude;
            if ($lat < 8 || $lat > 24 || $lng < 102 || $lng > 110) {
                $add($p, 'nặng', 'bad_coords', 'Tọa độ ngoài Việt Nam (có thể đảo vĩ / kinh độ).');
            } elseif ($d->latitude !== null && Geo::distanceKm((float) $d->latitude, (float) $d->longitude, $lat, $lng) > PlaceImport::FAR_KM) {
                $add($p, 'nặng', 'far', sprintf('Cách trung tâm %d km.', (int) round(Geo::distanceKm((float) $d->latitude, (float) $d->longitude, $lat, $lng))));
            }
            $p->hasHours ? $withHours++ : $add($p, 'nhẹ', 'no_hours', 'Thiếu giờ mở cửa.');
            $p->durationMinutes ? $withDur++ : $add($p, 'nhẹ', 'no_duration', 'Thiếu thời lượng tham quan.');
            ($p->factsSource || in_array($p->source, ['osm', 'wikidata', 'import'], true)) ? $withSource++ : $add($p, 'nhẹ', 'no_source', 'Thiếu nguồn dữ liệu.');
        }
        // trùng: cùng tên rút gọn và gần nhau, hoặc tên gần giống và rất gần
        $list = $places->values()->all();
        $seen = [];
        for ($i = 0; $i < count($list); $i++) {
            for ($j = $i + 1; $j < count($list); $j++) {
                $a = $list[$i];
                $b = $list[$j];
                $na = PlaceImport::norm((string) $a->name);
                $nb = PlaceImport::norm((string) $b->name);
                if ($na === '' || $nb === '') {
                    continue;
                }
                $km = Geo::distanceKm((float) $a->latitude, (float) $a->longitude, (float) $b->latitude, (float) $b->longitude);
                similar_text($na, $nb, $pct);
                if (($na === $nb && $km <= 2) || ($pct >= 88 && $km <= PlaceImport::DUP_NEAR_KM)) {
                    $key = min($a->placeId, $b->placeId) . '-' . max($a->placeId, $b->placeId);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $add($b, 'nặng', 'duplicate', sprintf('Có thể trùng #%d "%s" (cách %d m).', $a->placeId, $a->name, (int) round($km * 1000)));
                    }
                }
            }
        }
        $pub = $places->where('reviewStatus', 'published');
        $inKinds = (array) config('planner.indoor_place_kinds', []);

        return [
            'sight' => $pub->where('category', 'sight')->count(),
            'indoor' => $pub->where('category', 'sight')->filter(fn($p) => $p->indoor !== null ? (bool) $p->indoor : in_array($p->kind, $inKinds, true))->count(),
            'food' => $pub->where('category', 'food')->count(),
            'pctHours' => (int) round($withHours * 100 / $n),
            'pctDur' => (int) round($withDur * 100 / $n),
            'pctSource' => (int) round($withSource * 100 / $n),
            'issues' => $issues,
        ];
    }
}
