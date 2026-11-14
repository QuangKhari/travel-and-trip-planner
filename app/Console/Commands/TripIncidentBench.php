<?php

namespace App\Console\Commands;

use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\Health\TripResolver;
use App\Services\Planner\PlannerService;
use App\Services\Planner\TransferParser;
use App\Services\Planner\TripWatch;
use App\Services\Planner\TripWeather;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Thí nghiệm sự cố: cố ý gây sự cố cho các chuyến tổng hợp rồi đo hệ thống theo dõi (TripWatch) và bộ sửa (TripResolver).
 *   php artisan trips:incident-bench                 (24 sự cố: mưa, dông, đóng cửa, dời giờ bay tới, dời giờ về, cảnh báo điểm đến)
 *   php artisan trips:incident-bench --n=30 --csv=storage/app/bench/incident.csv
 *
 * Mỗi ca: tạo chuyến bằng bộ xếp lịch thật (Hà Nội / Hội An / Đà Lạt, đi sau 2–11 ngày, 3 ngày) → chạy theo dõi lần 1 (mốc)
 * → chạy lần 2 KHÔNG đổi gì (đo báo nhầm) → gây sự cố → chạy lần 3 (đo phát hiện) → hỏi bộ sửa (đo có cách sửa hợp lệ:
 * phương án đã mô phỏng lại bằng TripHealth và hết lỗi, không sinh lỗi cứng mới).
 * Dự báo thời tiết là dự báo giả (thay TripWeather trong container) để sự cố lặp lại được; mọi thay đổi CSDL bị hoàn tác cuối buổi.
 * Độ trễ: thời gian xử lý đo thật; thời gian chờ tới lần quét kế tiếp suy từ lịch chạy (mỗi 3 giờ) — nút "Kiểm tra ngay" bỏ phần chờ.
 */
class TripIncidentBench extends Command
{
    protected $signature = 'trips:incident-bench {--n=24 : số sự cố} {--csv= : ghi từng ca ra CSV} {--seed=7 : hạt ngẫu nhiên} {--debug : in các kiểm tra của TripHealth ở ca bị bỏ sót}';

    protected $description = 'Thí nghiệm sự cố: đo tỷ lệ phát hiện, tỷ lệ có cách sửa hợp lệ, báo nhầm và độ trễ của TripWatch';

    public const KINDS = ['rain', 'storm', 'closure', 'arrival', 'departure', 'alert'];

    public const LABEL = ['rain' => 'Mưa lớn', 'storm' => 'Dông', 'closure' => 'Đóng cửa đột xuất', 'arrival' => 'Chuyến bay tới trễ', 'departure' => 'Chuyến về sớm hơn', 'alert' => 'Cảnh báo tại điểm'];

    /** Dự báo giả theo ngày (xem paintSky): date => 'clear' | 'rain' | 'storm' */
    public static array $sky = [];

    public function handle(PlannerService $service): int
    {
        mt_srand((int) $this->option('seed'));
        $n = max(1, (int) $this->option('n'));
        $uid = (int) DB::table('tbl_users')->orderBy('userId')->value('userId');
        if (!$uid) {
            $this->error('Cần ít nhất một người dùng trong tbl_users.');

            return self::FAILURE;
        }
        // dự báo giả: TripWeather đọc cache theo ô lưới 0,1° trước khi gọi Open-Meteo, nên chỉ cần ghi cache (kho 'array', mất khi lệnh kết thúc)
        config(['cache.default' => 'array', 'planner.weather.enabled' => true]);
        config(['mail.default' => 'array']);

        $rows = [];
        DB::beginTransaction();
        try {
            $dests = [1, 18, 25];
            for ($i = 0; $i < $n; $i++) {
                $kind = self::KINDS[$i % count(self::KINDS)];
                $rows[] = $this->runCase($service, $uid, $i + 1, $kind, $dests[intdiv($i, count(self::KINDS)) % 3], 2 + ($i * 3) % 10);
                $this->output->write('.');
            }
        } finally {
            DB::rollBack();
            self::$sky = [];
        }
        $this->newLine(2);
        $this->report($rows);

        return self::SUCCESS;
    }

    private function runCase(PlannerService $service, int $uid, int $no, string $kind, int $destId, int $lead): array
    {
        self::$sky = [];
        $start = now()->addDays($lead)->toDateString();
        $req = ['destinationId' => $destId, 'startDate' => $start, 'days' => 3, 'adults' => 2, 'children' => 0, 'pace' => 'normal',
            'month' => (int) date('n', strtotime($start)), 'groupSize' => 2];
        if (in_array($kind, ['arrival', 'departure'], true)) {
            $req += ['moveKind' => 'flight', 'arriveAt' => '07:30', 'leaveAt' => '20:00'];
        }
        $req = TransferParser::minutes($req);
        $base = ['case' => $no, 'kind' => $kind, 'dest' => $destId, 'lead' => $lead, 'injected' => 0, 'affected' => 0, 'baseline' => 0, 'noise' => 0, 'detected' => 0,
            'eventTitle' => '', 'fixable' => 0, 'fix' => '', 'ms' => 0, 'note' => ''];
        $r = $service->generate($req);
        if (empty($r['ok'])) {
            return ['note' => 'không tạo được chuyến: ' . ($r['message'] ?? '')] + $base;
        }
        $tripId = $service->save($uid, $r);
        self::paintSky($destId);
        if (!empty($req['arriveAt'])) {
            \App\Http\Controllers\clients\PlannerController::recordTransfers($tripId, $req);
        }
        $trip = fn() => DB::table('tbl_trips')->where('tripId', $tripId)->first();
        $watch = app(TripWatch::class);
        $first = $watch->observe($trip());
        $base['baseline'] = count($first['new']);
        $again = $watch->observe($trip());
        $base['noise'] = count($again['new']) + count($again['resolved']);

        $hardBefore = $this->hardKeys($trip());

        // chọn mục để gây sự cố: mục ngoài trời / có giờ đầu tiên của một ngày giữa
        $items = DB::table('tbl_trip_items')->where('tripId', $tripId)->whereNotNull('dayNo')->orderBy('dayNo')->orderBy('orderNo')->get();
        $day = 2;
        $expect = null;
        $note = '';
        switch ($kind) {
            case 'rain':
            case 'storm':
                self::$sky[Carbon::parse($start)->addDays($day - 1)->toDateString()] = $kind;
                $expect = fn($id) => str_contains($id, 'weather.');
                break;
            case 'closure':
                $it = $items->first(fn($x) => in_array($x->refType, ['place', 'experience'], true) && $x->dayNo == $day) ?? $items->first(fn($x) => in_array($x->refType, ['place', 'experience'], true));
                if (!$it) {
                    return ['note' => 'chuyến không có địa điểm để đóng cửa'] + $base;
                }
                $day = (int) $it->dayNo;
                $dow = Carbon::parse($start)->addDays($day - 1)->dayOfWeekIso;
                $codes = [1 => 'Mo', 2 => 'Tu', 3 => 'We', 4 => 'Th', 5 => 'Fr', 6 => 'Sa', 7 => 'Su'];
                app(\App\Services\Planner\OpeningHours::class)->store($it->refType, (int) $it->refId, 'Mo-Su 07:00-21:00; ' . $codes[$dow] . ' off', 'bench');
                $note = 'đóng: refType=' . $it->refType . ' #' . $it->refId;
                $expect = fn($id) => str_contains($id, 'hours.closed');
                break;
            case 'arrival':
                $day = 1;
                $row = DB::table('tbl_trip_external')->where('tripId', $tripId)->orderBy('startAt')->first();
                if (!$row) {
                    return ['note' => 'chưa ghi được vé'] + $base;
                }
                $late = Carbon::parse($row->startAt)->setTime(14, 30);
                DB::table('tbl_trip_external')->where('externalId', $row->externalId)->update(['startAt' => $late, 'endAt' => $late]);
                $expect = fn($id) => str_contains($id, 'time.arrival') || str_contains($id, 'hours.late') || str_contains($id, 'hours.early');
                break;
            case 'departure':
                $day = 3;
                $row = DB::table('tbl_trip_external')->where('tripId', $tripId)->orderByDesc('startAt')->first();
                if (!$row) {
                    return ['note' => 'chưa ghi được vé'] + $base;
                }
                DB::table('tbl_trip_external')->where('externalId', $row->externalId)->update(['startAt' => Carbon::parse($row->startAt)->setTime(11, 0)]);
                $expect = fn($id) => str_contains($id, 'time.departure');
                break;
            case 'alert':
                $it = $items->first(fn($x) => $x->refType === 'place' && $x->dayNo == $day) ?? $items->first(fn($x) => $x->refType === 'place');
                if (!$it) {
                    return ['note' => 'chuyến không có địa điểm'] + $base;
                }
                $day = (int) $it->dayNo;
                DB::table('tbl_travel_alerts')->insert(['destinationId' => null, 'placeId' => (int) $it->refId, 'kind' => 'closure', 'level' => 'bad',
                    'title' => 'Tạm đóng cửa để sửa chữa (thử nghiệm)', 'detail' => 'Sự cố giả cho thí nghiệm.', 'startsOn' => $start,
                    'endsOn' => Carbon::parse($start)->addDays(5)->toDateString(), 'isActive' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $expect = fn($id) => str_starts_with($id, 'travel:');
                break;
        }
        self::paintSky($destId);
        $base['injected'] = 1;
        // "sự thật" độc lập với TripWatch: sự cố có làm lịch hỏng không?
        //  mưa / dông: ngày đó có mục NGOÀI TRỜI theo dữ liệu gốc; đóng cửa / cảnh báo tại điểm: nơi đó đang nằm trong lịch;
        //  đổi giờ vé: bộ kiểm tra lịch (TripHealth) có lỗi cứng mới so với trước khi đổi (lịch tự lùi giờ mà vẫn kịp thì không tính là hỏng)
        $base['affected'] = match ($kind) {
            'rain', 'storm' => $this->hasOutdoor($tripId, $day) ? 1 : 0,
            'closure', 'alert' => 1,
            default => array_diff($this->hardKeys($trip()), $hardBefore) ? 1 : 0,
        };
        $t0 = microtime(true);
        $res = $watch->observe($trip());
        $base['ms'] = (int) round((microtime(true) - $t0) * 1000);
        $hit = array_values(array_filter($res['new'], fn($e) => $expect($e['id'])));
        $base['detected'] = $hit ? 1 : 0;
        $base['eventTitle'] = $hit ? $hit[0]['title'] : implode(' | ', array_map(fn($e) => $e['title'], $res['new']));
        $base['note'] = $note;
        if (!$hit) {
            if ($this->option('debug') && $base['affected']) {
                $this->newLine();
                $this->line("Ca $no ($kind) bỏ sót. Kiểm tra hiện có:");
                foreach (app(TripHealth::class)->forTrip($trip())['checks'] as $c) {
                    $this->line('  ' . $c['code'] . ' [' . ($c['level'] ?? '') . '] ngày ' . ($c['day'] ?? '-') . ': ' . mb_substr((string) $c['text'], 0, 110));
                }
                foreach (DB::table('tbl_trip_items')->where('tripId', $tripId)->where('dayNo', $day)->orderBy('orderNo')->get() as $x) {
                    $this->line('  · ' . $x->refType . '#' . $x->refId . ' ' . ($x->label ?? '') . ' start=' . ($x->startMin ?? '-'));
                }
            }

            return $base;
        }

        // cách sửa: hỏi bộ sửa với đúng mã lỗi của TripHealth; hợp lệ = có phương án đã mô phỏng hết lỗi và không thêm lỗi cứng
        $checks = app(TripHealth::class)->forTrip($trip())['checks'];
        $want = match ($kind) { 'rain' => ['weather.rain', 'weather.storm'], 'storm' => ['weather.storm', 'weather.rain'], 'closure' => ['hours.closed'],
            'arrival' => ['time.arrival', 'hours.late', 'hours.early'], 'departure' => ['time.departure', 'hours.late'], default => [] };
        if ($kind === 'alert') {
            // cảnh báo tại một địa điểm: cách sửa là đưa nơi đó ra khỏi lịch (vào Đã lưu) — mô phỏng và kiểm tra không sinh lỗi cứng
            $pos = $this->positionOf($trip(), 'place', (int) $it->refId);
            if ($pos) {
                $sim = app(TripResolver::class)->simulate($trip(), [['op' => 'move', 'day' => $pos[0], 'index' => $pos[1], 'toDay' => null]]);
                $hard = array_filter($sim['newIssues'] ?? [], fn($x) => ($x['severity'] ?? '') === 'hard');
                $base['fixable'] = $hard ? 0 : 1;
                $base['fix'] = 'Chuyển vào Đã lưu';
            }

            return $base;
        }
        foreach ($checks as $c) {
            if (!in_array($c['code'], $want, true)) {
                continue;
            }
            $opts = app(TripResolver::class)->forTrip($trip(), $c['code'], $c['day'] ?? null, $c['index'] ?? null)['options'] ?? [];
            $ok = array_values(array_filter($opts, fn($o) => !empty($o['resolves'])));
            if ($ok) {
                $base['fixable'] = 1;
                $base['fix'] = $ok[0]['title'] ?? $ok[0]['label'] ?? 'có';
                break;
            }
            $base['fix'] = $opts ? 'có phương án nhưng chưa hết lỗi' : 'không có phương án';
        }

        return $base;
    }

    /** Ghi dự báo giả (theo self::$sky) cho mọi ô lưới phủ các nơi của điểm đến. */
    public static function paintSky(int $destId): void
    {
        $b = DB::table('tbl_external_places')->where('destinationId', $destId)
            ->selectRaw('min(latitude) a, max(latitude) b, min(longitude) c, max(longitude) d')->first();
        $ctr = DB::table('tbl_destinations')->where('destinationId', $destId)->first(['latitude', 'longitude']);
        $la0 = min((float) ($b->a ?? $ctr->latitude), (float) $ctr->latitude) - 0.1;
        $la1 = max((float) ($b->b ?? $ctr->latitude), (float) $ctr->latitude) + 0.1;
        $ln0 = min((float) ($b->c ?? $ctr->longitude), (float) $ctr->longitude) - 0.1;
        $ln1 = max((float) ($b->d ?? $ctr->longitude), (float) $ctr->longitude) + 0.1;
        $days = [];
        for ($i = 0; $i < 16; $i++) {
            $date = now()->addDays($i)->toDateString();
            $sky = self::$sky[$date] ?? 'clear';
            $hours = [];
            for ($h = 0; $h < 24; $h++) {
                $wet = $sky !== 'clear' && $h >= 7 && $h <= 21;
                $hours[$h] = ['p' => $wet ? 90 : 5, 't' => 26, 'mm' => $wet ? ($sky === 'storm' ? 12.0 : 4.0) : 0.0,
                    'code' => $wet ? ($sky === 'storm' ? 95 : 63) : 1, 'wind' => $wet && $sky === 'storm' ? 55 : 8];
            }
            $days[$date] = ['code' => $sky === 'storm' ? 95 : ($sky === 'rain' ? 63 : 1), 'tMax' => 28, 'tMin' => 20, 'rainMax' => $sky === 'clear' ? 5 : 90, 'hours' => $hours];
        }
        $v = ['days' => $days, 'fetchedAt' => now()->toIso8601String(), 'stale' => false];
        for ($la = round($la0, 1); $la <= $la1 + 1e-9; $la = round($la + 0.1, 1)) {
            for ($ln = round($ln0, 1); $ln <= $ln1 + 1e-9; $ln = round($ln + 0.1, 1)) {
                \Illuminate\Support\Facades\Cache::put(TripWeather::cacheKey($la, $ln), $v, now()->addHours(2));
            }
        }
    }

    /** @return list<string> các lỗi cứng hiện có (mã:ngày:vị trí) */
    private function hardKeys(object $trip): array
    {
        return array_values(array_map(fn($c) => $c['code'] . ':' . ($c['day'] ?? 0) . ':' . ($c['index'] ?? 0),
            array_filter(app(TripHealth::class)->forTrip($trip)['checks'], fn($c) => in_array($c['code'], TripHealth::HARD, true))));
    }

    /** Ngày có mục ngoài trời theo dữ liệu gốc (cột indoor; không có thì theo loại nơi ngoài trời). */
    private function hasOutdoor(int $tripId, int $day): bool
    {
        $outKinds = array_merge((array) config('planner.dislikes.outdoor.place_kinds', []), ['peak', 'beach', 'viewpoint', 'cave_entrance', 'hot_spring', 'waterfall', 'park', 'archaeological_site']);
        foreach (DB::table('tbl_trip_items')->where('tripId', $tripId)->where('dayNo', $day)->get() as $x) {
            if ($x->refType === 'place') {
                $p = DB::table('tbl_external_places')->where('placeId', $x->refId)->first();
                if ($p && (isset($p->indoor) && $p->indoor !== null ? !$p->indoor : in_array($p->kind, $outKinds, true))) {
                    return true;
                }
            } elseif ($x->refType === 'experience' && DB::table('tbl_experiences')->where('experienceId', $x->refId)->value('indoor') === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return ?array{0:int,1:int} (ngày, vị trí) của mục trong lịch */
    private function positionOf(object $trip, string $type, int $refId): ?array
    {
        $items = DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->whereNotNull('dayNo')->orderBy('dayNo')->orderBy('orderNo')->get();
        $idx = [];
        foreach ($items as $x) {
            $i = $idx[$x->dayNo] = ($idx[$x->dayNo] ?? -1) + 1;
            if ($x->refType === $type && (int) $x->refId === $refId) {
                return [(int) $x->dayNo, $i];
            }
        }

        return null;
    }

    private function report(array $rows): void
    {
        $this->table(['#', 'Sự cố', 'Điểm đến', 'Đi sau', 'Làm hỏng lịch', 'Báo nhầm', 'Phát hiện', 'Thông báo', 'Sửa hợp lệ', 'Cách sửa', 'ms'], array_map(fn($r) => [
            $r['case'], self::LABEL[$r['kind']], $r['dest'], $r['lead'] . ' ngày', $r['injected'] ? ($r['affected'] ? 'có' : 'không') : '—', $r['noise'], $r['injected'] ? ($r['detected'] ? 'có' : 'KHÔNG') : '—',
            mb_strimwidth($r['eventTitle'] ?: $r['note'], 0, 46, '…'), $r['detected'] ? ($r['fixable'] ? 'có' : 'không') : '—', mb_strimwidth($r['fix'], 0, 34, '…'), $r['ms']], $rows));
        $inj = array_filter($rows, fn($r) => $r['injected']);
        $aff = array_filter($inj, fn($r) => $r['affected']);
        $quiet = array_filter($inj, fn($r) => !$r['affected']);
        $det = array_filter($aff, fn($r) => $r['detected']);
        $fix = array_filter($det, fn($r) => $r['fixable']);
        $loud = array_filter($quiet, fn($r) => $r['detected']);
        $ms = array_column($inj, 'ms');
        sort($ms);
        $p = fn(int $a, int $b) => $b ? round(100 * $a / $b, 1) . '%' : '—';
        $this->line('Sự cố gây được: ' . count($inj) . '/' . count($rows) . ' · làm hỏng lịch: ' . count($aff) . ' · không ảnh hưởng lịch: ' . count($quiet));
        $this->line('Tỷ lệ phát hiện (trên sự cố làm hỏng lịch): ' . count($det) . '/' . count($aff) . ' = ' . $p(count($det), count($aff)));
        $this->line('Tỷ lệ có cách sửa hợp lệ (trên số đã phát hiện): ' . count($fix) . '/' . count($det) . ' = ' . $p(count($fix), count($det)));
        $this->line('Báo khi sự cố không ảnh hưởng lịch: ' . count($loud) . '/' . count($quiet) . ' · báo nhầm khi không có gì đổi: ' . array_sum(array_column($rows, 'noise')) . ' sự kiện / ' . count($rows) . ' lần quét lại');
        if ($ms) {
            $this->line('Thời gian xử lý một lần quét: trung vị ' . $ms[intdiv(count($ms), 2)] . ' ms, lớn nhất ' . max($ms) . ' ms');
        }
        $this->line('Độ trễ tới thông báo: chờ lần quét kế tiếp (lịch mỗi 3 giờ: trung bình 90 phút, tối đa 180 phút) + thời gian xử lý; bấm "Kiểm tra ngay" thì chỉ còn thời gian xử lý.');
        foreach (self::KINDS as $k) {
            $a = array_filter($aff, fn($r) => $r['kind'] === $k);
            $d = array_filter($a, fn($r) => $r['detected']);
            $f = array_filter($d, fn($r) => $r['fixable']);
            $q = count(array_filter($quiet, fn($r) => $r['kind'] === $k));
            $this->line(sprintf('  %s: phát hiện %d/%d · sửa được %d/%d · không ảnh hưởng %d', self::LABEL[$k], count($d), count($a), count($f), count($d), $q));
        }
        if ($path = $this->option('csv')) {
            @mkdir(dirname(base_path($path)), 0775, true);
            $f = fopen(base_path($path), 'w');
            fputcsv($f, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($f, $r);
            }
            fclose($f);
            $this->info("Đã ghi $path");
        }
    }
}
