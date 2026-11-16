<?php

namespace App\Console\Commands;

use App\Services\Planner\Reco\RecoLog;
use Illuminate\Console\Command;

/**
 * Kết quả thử xen kẽ (Đợt F, L8): mỗi danh sách trộn kết quả luật (A) và mô hình (B); danh sách nào người dùng chọn nhiều nơi của đội B hơn
 * thì B thắng. Kiểm định dấu hai phía (bỏ hòa) cho biết khác biệt có ý nghĩa không.
 *   php artisan reco:interleave-report
 */
class RecoInterleaveReport extends Command
{
    protected $signature = 'reco:interleave-report {--since=}';

    protected $description = 'Tổng hợp thử xen kẽ: luật (A) so với mô hình cá nhân hóa (B)';

    public function handle(): int
    {
        $lab = array_filter(RecoLog::labelled($this->option('since')), fn($r) => in_array($r['policy'], ['il-A', 'il-B'], true));
        $lists = [];
        foreach ($lab as $r) {
            $lists[$r['listId']][$r['policy']] = ($lists[$r['listId']][$r['policy']] ?? 0) + $r['label'];
        }
        $a = $b = $tie = 0;
        foreach ($lists as $l) {
            $x = $l['il-A'] ?? 0;
            $y = $l['il-B'] ?? 0;
            if ($x === 0 && $y === 0) {
                continue;
            }
            $x > $y ? $a++ : ($y > $x ? $b++ : $tie++);
        }
        $n = $a + $b;
        $p = $n ? self::signTest(max($a, $b), $n) : 1.0;
        $this->table(['Danh sách có chọn', 'Luật thắng (A)', 'Mô hình thắng (B)', 'Hòa', 'Tỷ lệ B thắng', 'p (kiểm định dấu)'],
            [[$n + $tie, $a, $b, $tie, $n ? round(100 * $b / $n, 1) . '%' : '—', $n ? round($p, 4) : '—']]);
        $this->line($n < 10 ? 'Còn quá ít danh sách để kết luận (nên ≥ 30).' : ($p < 0.05 ? ($b > $a ? 'Mô hình được chọn nhiều hơn, có ý nghĩa (p < 0,05).' : 'Luật được chọn nhiều hơn, có ý nghĩa.') : 'Chưa khác biệt có ý nghĩa.'));

        return self::SUCCESS;
    }

    /** p hai phía của kiểm định dấu: P(X ≥ k) × 2 với X ~ Nhị thức(n, 0,5). */
    public static function signTest(int $k, int $n): float
    {
        $p = 0.0;
        for ($i = $k; $i <= $n; $i++) {
            $p += exp(self::lnC($n, $i) - $n * log(2));
        }

        return min(1.0, 2 * $p);
    }

    private static function lnC(int $n, int $k): float
    {
        $s = 0.0;
        for ($i = 1; $i <= $k; $i++) {
            $s += log($n - $k + $i) - log($i);
        }

        return $s;
    }
}
