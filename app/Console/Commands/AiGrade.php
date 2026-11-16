<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Đợt F (AF2): tổng hợp chấm tay của hai người cho lời kể La bàn.
 *   1. php artisan ai:eval --suite=explain --out=storage/app/cham.csv     (sinh lời kể; cột judgeFaithful, judgeUseful để trống)
 *   2. Chép cham.csv thành hai bản, mỗi người chấm điền 1–5 vào hai cột đó, không xem bản của người kia.
 *   3. php artisan ai:grade storage/app/cham-A.csv storage/app/cham-B.csv
 * In trung bình từng tiêu chí, tỉ lệ hai người chấm trùng hẳn / lệch ≤ 1 điểm, và kappa có trọng số bậc hai (Cohen).
 * Kappa: < 0,2 rất thấp · 0,2–0,4 thấp · 0,4–0,6 vừa · 0,6–0,8 khá · > 0,8 rất cao (Landis & Koch, 1977).
 */
class AiGrade extends Command
{
    protected $signature = 'ai:grade {a : bảng chấm người 1 (CSV)} {b : bảng chấm người 2 (CSV)} {--cols=judgeFaithful,judgeUseful} {--md=}';

    protected $description = 'Độ đồng thuận và điểm trung bình của hai người chấm tay (thang 1–5)';

    public function handle(): int
    {
        $A = self::read($this->argument('a'));
        $B = self::read($this->argument('b'));
        if ($A === null || $B === null) {
            $this->error('Không đọc được tệp chấm (cần cột id và các cột điểm).');
            return self::FAILURE;
        }
        $names = ['judgeFaithful' => 'Đúng với dữ liệu (không bịa)', 'judgeUseful' => 'Hữu ích khi quyết định'];
        $md = ['| Tiêu chí | Số mẫu | Người 1 | Người 2 | Chung | Trùng hẳn | Lệch ≤ 1 | Kappa (trọng số bậc hai) |', '|---|---|---|---|---|---|---|---|'];
        foreach (explode(',', (string) $this->option('cols')) as $c) {
            $x = $y = [];
            foreach ($A as $id => $r) {
                $va = $r[$c] ?? null;
                $vb = $B[$id][$c] ?? null;
                if (self::ok($va) && self::ok($vb)) {
                    $x[] = (int) $va;
                    $y[] = (int) $vb;
                }
            }
            $n = count($x);
            if (!$n) {
                $md[] = "| " . ($names[$c] ?? $c) . " | 0 | – | – | – | – | – | – |";
                continue;
            }
            $exact = count(array_filter(array_keys($x), fn($i) => $x[$i] === $y[$i])) / $n;
            $near = count(array_filter(array_keys($x), fn($i) => abs($x[$i] - $y[$i]) <= 1)) / $n;
            $k = self::kappa($x, $y);
            $f = fn($v) => number_format($v, 2, ',', '');
            $md[] = sprintf('| %s | %d | %s | %s | %s | %d%% | %d%% | %s |', $names[$c] ?? $c, $n, $f(array_sum($x) / $n), $f(array_sum($y) / $n), $f((array_sum($x) + array_sum($y)) / (2 * $n)),
                round(100 * $exact), round(100 * $near), $k === null ? '–' : $f($k));
        }
        foreach ($md as $l) {
            $this->line($l);
        }
        if ($p = $this->option('md')) {
            file_put_contents(base_path($p), "# Chấm tay lời kể La bàn (AF2)\n\n" . implode("\n", $md) . "\n");
            $this->info("Đã ghi $p");
        }

        return self::SUCCESS;
    }

    private static function ok($v): bool
    {
        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 5;
    }

    /** @return array<string,array<string,string>>|null */
    public static function read(string $path): ?array
    {
        $path = is_file($path) ? $path : base_path($path);
        if (!is_file($path) || !($fh = fopen($path, 'r'))) {
            return null;
        }
        $head = fgetcsv($fh, null, ',', '"', '');
        if (!$head) {
            return null;
        }
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
        if (!in_array('id', $head, true)) {
            return null;
        }
        $out = [];
        while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if (count($r) === count($head)) {
                $row = array_combine($head, $r);
                $out[$row['id']] = $row;
            }
        }
        fclose($fh);

        return $out;
    }

    /** Kappa Cohen có trọng số bậc hai, thang 1–5. */
    public static function kappa(array $x, array $y, int $k = 5): ?float
    {
        $n = count($x);
        if ($n < 2) {
            return null;
        }
        $O = array_fill(1, $k, array_fill(1, $k, 0));
        foreach ($x as $i => $a) {
            $O[$a][$y[$i]]++;
        }
        $ra = array_count_values($x);
        $rb = array_count_values($y);
        $num = $den = 0.0;
        for ($i = 1; $i <= $k; $i++) {
            for ($j = 1; $j <= $k; $j++) {
                $w = (($i - $j) ** 2) / (($k - 1) ** 2);
                $num += $w * $O[$i][$j];
                $den += $w * (($ra[$i] ?? 0) * ($rb[$j] ?? 0) / $n);
            }
        }

        return $den > 0 ? 1 - $num / $den : null;
    }
}
