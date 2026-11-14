<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Tổng hợp thử nghiệm so sánh "Google Docs + Maps" với "Travela" (docs/ai/THU_NGHIEM_SO_SANH.md).
 *   php artisan study:summary database/study/so_sanh_mau.csv
 * Mỗi dòng một người: thời gian (giây) và số lỗi còn sót của mỗi cách, 10 câu SUS cho mỗi cách.
 * In trung bình, độ lệch chuẩn, trung vị, chênh lệch cặp (Travela − Docs) kèm khoảng tin cậy 95% (t), và số người làm nhanh / ít lỗi hơn với Travela.
 */
class StudySummary extends Command
{
    protected $signature = 'study:summary {file : tệp CSV theo mẫu database/study/so_sanh_mau.csv}';

    protected $description = 'Tổng hợp thử nghiệm người dùng: thời gian, lỗi còn sót, điểm SUS (Docs + Maps vs Travela)';

    /** Điểm SUS 0–100 từ 10 câu (1–5); null nếu thiếu câu. */
    public static function sus(array $q): ?float
    {
        if (count(array_filter($q, fn($v) => is_numeric($v) && $v >= 1 && $v <= 5)) !== 10) {
            return null;
        }
        $s = 0;
        foreach (array_values($q) as $i => $v) {
            $s += $i % 2 === 0 ? (int) $v - 1 : 5 - (int) $v;
        }

        return $s * 2.5;
    }

    /** @return array{n:int,mean:?float,sd:?float,median:?float} */
    public static function stats(array $x): array
    {
        $x = array_values(array_filter($x, fn($v) => $v !== null));
        $n = count($x);
        if (!$n) {
            return ['n' => 0, 'mean' => null, 'sd' => null, 'median' => null];
        }
        sort($x);
        $m = array_sum($x) / $n;
        $sd = $n > 1 ? sqrt(array_sum(array_map(fn($v) => ($v - $m) ** 2, $x)) / ($n - 1)) : 0.0;
        $med = $n % 2 ? $x[intdiv($n, 2)] : ($x[$n / 2 - 1] + $x[$n / 2]) / 2;

        return ['n' => $n, 'mean' => $m, 'sd' => $sd, 'median' => $med];
    }

    /** Hệ số t hai phía 95% theo bậc tự do (bảng rút gọn cho cỡ mẫu nhỏ). */
    public static function t95(int $df): float
    {
        $t = [1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571, 6 => 2.447, 7 => 2.365, 8 => 2.306, 9 => 2.262, 10 => 2.228, 11 => 2.201, 12 => 2.179, 13 => 2.160, 14 => 2.145, 15 => 2.131, 20 => 2.086, 30 => 2.042];
        foreach ($t as $k => $v) {
            if ($df <= $k) {
                return $v;
            }
        }

        return 1.96;
    }

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $f = @fopen(is_file($path) ? $path : base_path($path), 'r');
        if (!$f) {
            $this->error("Không mở được $path");

            return self::FAILURE;
        }
        $head = array_map(fn($h) => trim((string) $h, "\xEF\xBB\xBF \t"), fgetcsv($f) ?: []);
        $rows = [];
        while (($r = fgetcsv($f)) !== false) {
            if (count($r) < count($head)) {
                $r = array_pad($r, count($head), '');
            }
            $x = array_combine($head, array_slice($r, 0, count($head)));
            if (($x['giay_docs'] ?? '') === '' && ($x['giay_travela'] ?? '') === '') {
                continue;
            }
            $num = fn($k) => isset($x[$k]) && is_numeric($x[$k]) ? (float) $x[$k] : null;
            $rows[] = [
                'who' => $x['nguoi'] ?? '?', 'tD' => $num('giay_docs'), 'tT' => $num('giay_travela'), 'eD' => $num('loi_docs'), 'eT' => $num('loi_travela'),
                'sD' => self::sus(array_map(fn($i) => $x['sus_d' . $i] ?? null, range(1, 10))), 'sT' => self::sus(array_map(fn($i) => $x['sus_t' . $i] ?? null, range(1, 10))),
            ];
        }
        fclose($f);
        if (!$rows) {
            $this->warn('Chưa có dòng nào có số liệu.');

            return self::SUCCESS;
        }
        $fmt = fn($v, $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
        $line = [];
        foreach ([['Thời gian (phút)', 'tD', 'tT', 1 / 60, true], ['Lỗi còn sót', 'eD', 'eT', 1, true], ['SUS (0–100)', 'sD', 'sT', 1, false]] as [$label, $a, $b, $k, $lowerBetter]) {
            $A = self::stats(array_map(fn($r) => $r[$a] !== null ? $r[$a] * $k : null, $rows));
            $B = self::stats(array_map(fn($r) => $r[$b] !== null ? $r[$b] * $k : null, $rows));
            $pairs = array_values(array_filter(array_map(fn($r) => $r[$a] !== null && $r[$b] !== null ? ($r[$b] - $r[$a]) * $k : null, $rows), fn($v) => $v !== null));
            $D = self::stats($pairs);
            $ci = $D['n'] > 1 ? self::t95($D['n'] - 1) * $D['sd'] / sqrt($D['n']) : null;
            $better = count(array_filter($pairs, fn($d) => $lowerBetter ? $d < 0 : $d > 0));
            $line[] = [$label, $fmt($A['mean']) . ' ± ' . $fmt($A['sd']) . ' (TV ' . $fmt($A['median']) . ')', $fmt($B['mean']) . ' ± ' . $fmt($B['sd']) . ' (TV ' . $fmt($B['median']) . ')',
                $fmt($D['mean']) . ($ci !== null ? ' [' . $fmt($D['mean'] - $ci) . '; ' . $fmt($D['mean'] + $ci) . ']' : ''), $better . '/' . $D['n']];
        }
        $this->table(['Chỉ số', 'Docs + Maps (TB ± ĐLC)', 'Travela (TB ± ĐLC)', 'Chênh Travela − Docs [KTC 95%]', 'Số người tốt hơn với Travela'], $line);
        $this->line('Số người: ' . count($rows) . '. Cỡ mẫu nhỏ: chỉ kết luận "trên nhóm thử này"; khoảng tin cậy chứa 0 nghĩa là chưa đủ bằng chứng có khác biệt.');
        $this->line('SUS tham chiếu: 68 là mức trung bình; trên 80 là tốt.');

        return self::SUCCESS;
    }
}
