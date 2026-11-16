<?php

namespace App\Console\Commands;

use App\Services\Study\StudySession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Đợt F (F2, F4): tổng hợp buổi thử từ dữ liệu hệ thống tự ghi.
 *   php artisan study:report                       mọi vòng có dữ liệu
 *   php artisan study:report --round=1 --round=2   so vòng 2 với vòng 1
 *   php artisan study:report --md=docs/so-lieu/buoi-thu.md --csv=storage/app/buoi-thu.csv
 *
 * Mỗi vòng: tỉ lệ đạt, thời gian trung vị (Q1–Q3) theo nhiệm vụ (chỉ lượt đạt), số lần vấp (bấm Xong khi chưa đạt),
 * SUS trung bình ± độ lệch chuẩn và khoảng tin cậy 95% (t). Hai vòng: chênh SUS (Welch) và chênh thời gian trung vị
 * (bootstrap 2.000 lần). Cỡ mẫu nhỏ: báo khoảng tin cậy, không kết luận "có ý nghĩa" khi khoảng còn chứa 0.
 */
class StudyReport extends Command
{
    protected $signature = 'study:report {--round=* : vòng (1, 2)} {--md= : ghi bảng Markdown} {--csv= : xuất từng lượt nhiệm vụ} {--seed=7}';

    protected $description = 'Tổng hợp buổi thử người dùng: tỉ lệ đạt, thời gian, vấp, SUS; so sánh hai vòng';

    public function handle(): int
    {
        if (!StudySession::ready()) {
            $this->error('Chưa có bảng buổi thử. Chạy php artisan migrate.');
            return self::FAILURE;
        }
        $rounds = array_map('intval', (array) $this->option('round')) ?: DB::table('tbl_study_participants')->distinct()->orderBy('round')->pluck('round')->map(fn($v) => (int) $v)->all();
        if (!$rounds) {
            $this->warn('Chưa có người thử. Chạy php artisan study:setup --round=1 --n=8');
            return self::SUCCESS;
        }
        $data = [];
        foreach ($rounds as $r) {
            $data[$r] = self::round($r);
        }
        $md = [];
        foreach ($data as $r => $d) {
            $md[] = "### Vòng $r — {$d['n']} người ({$d['done']} làm xong, {$d['devices']})";
            $md[] = '| Nhiệm vụ | Đạt | Thời gian trung vị (Q1–Q3) | Vấp / người | Bấm trung vị |';
            $md[] = '|---|---|---|---|---|';
            foreach ($d['tasks'] as $k => $t) {
                $md[] = sprintf('| %s | %s | %s | %s | %s |', config("study.tasks.$k.title"), $t['n'] ? $t['ok'] . '/' . $t['n'] . ' (' . round(100 * $t['ok'] / $t['n']) . '%)' : '–',
                    $t['time'] ? self::sec($t['time'][1]) . ' (' . self::sec($t['time'][0]) . '–' . self::sec($t['time'][2]) . ')' : '–',
                    $t['n'] ? number_format($t['slips'] / $t['n'], 1, ',', '') : '–', $t['clicks'] !== null ? (string) $t['clicks'] : '–');
            }
            $s = $d['sus'];
            $md[] = '';
            $md[] = $s['n'] ? sprintf('SUS: %s ± %s (n = %d%s). Thang 0–100; mốc trung bình chung khoảng 68.', self::f($s['mean']), self::f($s['sd']), $s['n'],
                $s['lo'] !== null ? ', KTC 95% ' . self::f($s['lo']) . ' – ' . self::f($s['hi']) : '') : 'SUS: chưa có phiếu.';
            $md[] = sprintf('Dữ liệu hành vi ghi được trong vòng: %d lượt hiện gợi ý, %d lượt người thử chọn (thêm / áp dụng / ghé).', $d['impressions'], $d['labelled']);
            $md[] = '';
        }
        if (count($data) >= 2) {
            [$a, $b] = array_slice(array_keys($data), 0, 2);
            $md[] = "### So sánh vòng $b với vòng $a";
            $md[] = '| Chỉ số | Vòng ' . $a . ' | Vòng ' . $b . ' | Chênh (KTC 95%) |';
            $md[] = '|---|---|---|---|';
            $sa = $data[$a]['susRaw'];
            $sb = $data[$b]['susRaw'];
            $w = self::welch($sa, $sb);
            $md[] = sprintf('| SUS trung bình | %s | %s | %s |', self::f($data[$a]['sus']['mean']), self::f($data[$b]['sus']['mean']), $w ? self::f($w[0]) . ' (' . self::f($w[1]) . ' – ' . self::f($w[2]) . ')' : '–');
            foreach (StudySession::taskKeys() as $k) {
                $ta = $data[$a]['tasks'][$k];
                $tb = $data[$b]['tasks'][$k];
                $bs = self::bootMedianDiff($ta['secs'], $tb['secs'], (int) $this->option('seed'));
                $md[] = sprintf('| %s: tỉ lệ đạt | %s | %s | |', config("study.tasks.$k.title"), self::pct($ta), self::pct($tb));
                $md[] = sprintf('| %s: thời gian trung vị | %s | %s | %s |', config("study.tasks.$k.title"), $ta['time'] ? self::sec($ta['time'][1]) : '–', $tb['time'] ? self::sec($tb['time'][1]) : '–',
                    $bs ? self::sec($bs[0], true) . ' (' . self::sec($bs[1], true) . ' – ' . self::sec($bs[2], true) . ')' : '–');
            }
            $md[] = '';
            $md[] = 'Hai vòng là hai nhóm người khác nhau, cỡ mẫu nhỏ: chênh lệch có khoảng tin cậy chứa 0 thì chỉ ghi là "chưa thấy khác biệt rõ".';
        }
        $text = implode("\n", $md) . "\n";
        foreach (explode("\n", rtrim($text)) as $l) {
            $this->line($l);
        }
        if ($p = $this->option('md')) {
            @mkdir(dirname(base_path($p)), 0775, true);
            file_put_contents(base_path($p), "# Kết quả buổi thử người dùng\n\nTạo bởi `php artisan study:report` lúc " . now()->format('H:i d/m/Y') . ".\n\n" . $text);
            $this->info("Đã ghi $p");
        }
        if ($p = $this->option('csv')) {
            $f = fopen(base_path($p), 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['vong', 'ma', 'thiet_bi', 'nhiem_vu', 'thu_tu', 'ket_qua', 'giay', 'lan_vap', 'so_lan_bam', 'so_trang', 'bat_dau'], ',', '"', '');
            foreach (DB::table('tbl_study_tasks as t')->join('tbl_study_participants as p', 'p.participantId', '=', 't.participantId')->whereIn('p.round', $rounds)->orderBy('p.code')->orderBy('t.position')->get() as $r) {
                fputcsv($f, [$r->round, $r->code, $r->device, $r->task, $r->position, $r->outcome ?? 'dang_lam', $r->seconds, max(0, (int) $r->checks - ($r->outcome === 'success' ? 1 : 0)), $r->clicks, $r->pages, $r->startedAt], ',', '"', '');
            }
            fclose($f);
            $this->info("Đã ghi $p");
        }

        return self::SUCCESS;
    }

    public static function round(int $round): array
    {
        $ps = DB::table('tbl_study_participants')->where('round', $round)->get();
        $ids = $ps->pluck('participantId')->all();
        $rows = DB::table('tbl_study_tasks')->whereIn('participantId', $ids ?: [0])->get()->groupBy('task');
        $tasks = [];
        foreach (StudySession::taskKeys() as $k) {
            $rs = collect($rows[$k] ?? [])->filter(fn($r) => $r->outcome !== null);
            $secs = $rs->where('outcome', 'success')->pluck('seconds')->map(fn($v) => (int) $v)->values()->all();
            $clicks = $rs->pluck('clicks')->filter(fn($v) => $v !== null)->map(fn($v) => (int) $v)->values()->all();
            $tasks[$k] = ['n' => $rs->count(), 'ok' => $rs->where('outcome', 'success')->count(), 'secs' => $secs, 'time' => $secs ? self::quartiles($secs) : null,
                'slips' => $rs->sum(fn($r) => max(0, (int) $r->checks - ($r->outcome === 'success' ? 1 : 0))), 'clicks' => $clicks ? (int) round(self::quartiles($clicks)[1]) : null];
        }
        $sus = DB::table('tbl_study_sus')->whereIn('participantId', $ids ?: [0])->pluck('score')->map(fn($v) => (float) $v)->all();
        $st = StudySummary::stats($sus);
        $half = $st['n'] > 1 ? StudySummary::t95($st['n'] - 1) * $st['sd'] / sqrt($st['n']) : null;
        $users = $ps->pluck('userId')->all();
        $imp = \Illuminate\Support\Facades\Schema::hasTable('tbl_reco_impressions') ? DB::table('tbl_reco_impressions')->whereIn('userId', $users ?: [0]) : null;
        $dev = $ps->countBy(fn($p) => $p->device ?: 'chưa rõ')->map(fn($n, $k) => $n . ' ' . ['desktop' => 'máy tính', 'mobile' => 'điện thoại'][$k] ?? $k)->implode(', ');

        return ['n' => $ps->count(), 'done' => $ps->whereNotNull('finishedAt')->count(), 'devices' => $dev ?: 'chưa rõ thiết bị', 'tasks' => $tasks, 'susRaw' => $sus,
            'sus' => $st + ['lo' => $half !== null ? $st['mean'] - $half : null, 'hi' => $half !== null ? $st['mean'] + $half : null],
            'impressions' => $imp ? (clone $imp)->count() : 0, 'labelled' => self::positives($users)];
    }

    /** Lượt hiện có nhãn dương (người thử đã thêm / áp dụng / ghé nơi được gợi ý). */
    private static function positives(array $users): int
    {
        $n = 0;
        foreach ($users as $u) {
            $n += collect(\App\Services\Planner\Reco\RecoLog::labelled(null, (int) $u))->where('label', 1)->count();
        }

        return $n;
    }

    /** @return array{0:float,1:float,2:float} Q1, trung vị, Q3 */
    public static function quartiles(array $x): array
    {
        sort($x);
        $q = function (float $p) use ($x) {
            $i = ($p * (count($x) - 1));
            $lo = (int) floor($i);
            $hi = (int) ceil($i);

            return $x[$lo] + ($x[$hi] - $x[$lo]) * ($i - $lo);
        };

        return [$q(.25), $q(.5), $q(.75)];
    }

    /** Chênh trung bình b − a, KTC 95% Welch. @return array{0:float,1:float,2:float}|null */
    public static function welch(array $a, array $b): ?array
    {
        $sa = StudySummary::stats($a);
        $sb = StudySummary::stats($b);
        if ($sa['n'] < 2 || $sb['n'] < 2) {
            return null;
        }
        $va = $sa['sd'] ** 2 / $sa['n'];
        $vb = $sb['sd'] ** 2 / $sb['n'];
        $se = sqrt($va + $vb);
        $df = $se > 0 ? (int) floor(($va + $vb) ** 2 / (($va ** 2) / ($sa['n'] - 1) + ($vb ** 2) / ($sb['n'] - 1))) : $sa['n'] + $sb['n'] - 2;
        $d = $sb['mean'] - $sa['mean'];
        $h = StudySummary::t95(max(1, $df)) * $se;

        return [$d, $d - $h, $d + $h];
    }

    /** Chênh trung vị b − a, bootstrap phân vị 2,5–97,5%. */
    public static function bootMedianDiff(array $a, array $b, int $seed = 7, int $B = 2000): ?array
    {
        if (count($a) < 2 || count($b) < 2) {
            return null;
        }
        mt_srand($seed);
        $med = fn($x) => self::quartiles($x)[1];
        $diffs = [];
        for ($i = 0; $i < $B; $i++) {
            $ra = array_map(fn() => $a[mt_rand(0, count($a) - 1)], $a);
            $rb = array_map(fn() => $b[mt_rand(0, count($b) - 1)], $b);
            $diffs[] = $med($rb) - $med($ra);
        }
        sort($diffs);

        return [$med($b) - $med($a), $diffs[(int) floor(.025 * $B)], $diffs[(int) floor(.975 * $B) - 1]];
    }

    private static function pct(array $t): string
    {
        return $t['n'] ? round(100 * $t['ok'] / $t['n']) . '%' : '–';
    }

    private static function f(?float $v): string
    {
        return $v === null ? '–' : number_format($v, 1, ',', '');
    }

    private static function sec(float $s, bool $signed = false): string
    {
        $neg = $s < 0;
        $s = (int) round(abs($s));
        $t = $s >= 60 ? intdiv($s, 60) . ' ph ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s' : $s . ' s';

        return ($neg ? '−' : ($signed && $s ? '+' : '')) . $t;
    }
}
