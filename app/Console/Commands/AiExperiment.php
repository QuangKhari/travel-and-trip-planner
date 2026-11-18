<?php

namespace App\Console\Commands;

use App\Services\Ai\AiExperiments;
use Illuminate\Console\Command;

/**
 * Kết quả A/B thử nghiệm AI trên người dùng thật:
 *   php artisan ai:experiment copilot-model [--from=2026-11-20] [--to=2026-12-05]
 *   php artisan ai:experiment --who=42      nhóm của người dùng 42 trong mọi thử nghiệm (để kiểm tra / thử tay)
 */
class AiExperiment extends Command
{
    protected $signature = 'ai:experiment {name? : tên trong config ai.experiments} {--from=} {--to=} {--who= : userId}';
    protected $description = 'Báo cáo A/B thử nghiệm AI (theo nhóm người dùng) kèm kiểm định hai tỷ lệ';

    public function handle(): int
    {
        if ($u = (int) $this->option('who')) {
            foreach ((array) config('ai.experiments', []) as $n => $e) {
                $this->line($n . ' (' . ($e['feature'] ?? '?') . ', ' . (!empty($e['active']) ? 'đang chạy' : 'tắt') . '): nhóm ' . AiExperiments::assign($n, $u, (int) ($e['split'] ?? 50)));
            }

            return self::SUCCESS;
        }
        $name = (string) $this->argument('name');
        if (!config('ai.experiments.' . $name)) {
            $this->error('Không có thử nghiệm "' . $name . '". Có: ' . implode(', ', array_keys((array) config('ai.experiments', []))));

            return self::FAILURE;
        }
        $r = AiExperiments::report($name, $this->option('from') ?: null, $this->option('to') ?: null);
        $this->table(['Nhóm', 'Lượt', 'Thành công', 'JSON sai', 'P50', 'P95', 'USD/lượt', 'Áp dụng', 'Đúng ý'], array_map(fn($v, $x) => [
            $v, $x['runs'], $x['okRate'] !== null ? $x['okRate'] . '%' : '—', $x['invalid'], $x['p50'] ?? '—', $x['p95'] ?? '—',
            $x['usdPerRun'] !== null ? number_format($x['usdPerRun'], 5) : '—', $x['appliedRate'] !== null ? $x['appliedRate'] . '%' : '—',
            $x['rated'] ? round(100 * $x['helpful'] / $x['rated'], 1) . '%' : '—',
        ], array_keys($r['variants']), $r['variants']));
        if ($t = $r['test']) {
            $this->line(sprintf(
                '%s: A %.1f%% · B %.1f%% · chênh %+.1f điểm · z = %.2f · p = %.4f → %s',
                $t['metric'],
                $t['a'],
                $t['b'],
                $t['diff'],
                $t['z'],
                $t['p'],
                $t['significant'] ? 'khác biệt có ý nghĩa thống kê' : 'chưa đủ cơ sở kết luận (cần p < 0,05 và mỗi nhóm ≥ 30 lượt)',
            ));
        }

        return self::SUCCESS;
    }
}
