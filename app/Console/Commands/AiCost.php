<?php

namespace App\Console\Commands;

use App\Services\Ai\AiCost as Cost;
use Illuminate\Console\Command;

/**
 * Bảng chi phí AI theo chức năng và theo ngày (La bàn + Hỏi đáp), tính từ token đã ghi và bảng giá config('ai.pricing').
 *   php artisan ai:cost --from=2026-11-01 --to=2026-11-30 [--feature=copilot] [--csv=storage/app/ai-cost.csv]
 */
class AiCost extends Command
{
    protected $signature = 'ai:cost {--from= : YYYY-MM-DD} {--to= : YYYY-MM-DD} {--feature= : brief|explain|copilot|packing|booking_read|ask} {--csv= : ghi chi tiết ngày × chức năng × model}';
    protected $description = 'Chi phí AI theo chức năng và theo ngày, tính theo bảng giá từng model';

    public function handle(): int
    {
        $r = Cost::report($this->option('from') ?: null, $this->option('to') ?: null, $this->option('feature') ?: null);
        $this->info('Giá theo config/ai.php (kiểm ngày ' . $r['checked'] . ').');
        $this->table(['Chức năng', 'Lượt', 'Token vào', 'Token ra', 'USD', 'USD / lượt'], array_map(fn($f, $c) => [
            $f, $c['calls'], $c['in'], $c['out'], number_format($c['usd'], 4),
            $c['calls'] - $c['unpriced'] > 0 ? number_format($c['usd'] / ($c['calls'] - $c['unpriced']), 5) : '—',
        ], array_keys($r['byFeature']), $r['byFeature']));
        $this->line('Tổng: $' . number_format($r['total'], 4));
        if ($r['byDay']) {
            $this->table(['Ngày', 'USD'], array_map(fn($d, $v) => [$d, number_format($v, 4)], array_keys($r['byDay']), $r['byDay']));
        }
        if ($r['unpriced']) {
            $this->warn('Chưa có giá (không cộng vào tổng): ' . implode(', ', $r['unpriced']));
        }
        if ($csv = $this->option('csv')) {
            $fh = fopen(base_path($csv), 'w');
            fputcsv($fh, ['day', 'feature', 'model', 'calls', 'inputTokens', 'outputTokens', 'usd']);
            foreach ($r['rows'] as $x) {
                fputcsv($fh, [$x['day'], $x['feature'], $x['model'], $x['calls'], $x['in'], $x['out'], $x['usd'] === null ? '' : round($x['usd'], 6)]);
            }
            fclose($fh);
            $this->info('Đã ghi ' . $csv);
        }

        return self::SUCCESS;
    }
}
