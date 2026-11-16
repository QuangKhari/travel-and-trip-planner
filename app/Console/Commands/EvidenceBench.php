<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Đợt F (F5): chạy lại các bộ đo không cần người và gom phần tổng kết vào một tệp cho chương thực nghiệm.
 *   php artisan evidence:bench --md=docs/so-lieu/benchmark-tong-hop.md
 * Gồm: tối ưu thứ tự ngày (planner:route-bench), theo dõi chuyến / La bàn (trips:incident-bench --explain).
 * Bảng chi tiết từng ca ghi kèm CSV trong storage/app/bench/.
 */
class EvidenceBench extends Command
{
    protected $signature = 'evidence:bench {--md=docs/so-lieu/benchmark-tong-hop.md} {--n=30 : số ngày cho bench tối ưu tuyến}';

    protected $description = 'Chạy lại benchmark tối ưu tuyến và sự cố chuyến, gom tổng kết ra Markdown';

    public function handle(): int
    {
        @mkdir(storage_path('app/bench'), 0775, true);
        $md = ['# Benchmark tổng hợp (F5)', '', 'Tạo bởi `php artisan evidence:bench` lúc ' . now()->format('H:i d/m/Y') . '. Chi tiết từng ca: `storage/app/bench/*.csv`.', ''];

        $this->info('Tối ưu thứ tự ngày…');
        Artisan::call('planner:route-bench', ['--n' => (int) $this->option('n'), '--csv' => 'storage/app/bench/route.csv']);
        $out = Artisan::output();
        $lines = explode("\n", trim($out));
        $start = null;
        foreach ($lines as $i => $l) {
            if (str_contains($l, '| Cách ')) {
                $start = $i - 1;
            }
        }
        $md[] = '## Tối ưu thứ tự trong ngày (planner:route-bench --n=' . (int) $this->option('n') . ')';
        $md[] = '';
        $md[] = '```';
        $md = array_merge($md, $start !== null ? array_slice($lines, $start) : array_slice($lines, -12));
        $md[] = '```';
        $md[] = '';

        $this->info('Sự cố chuyến và lời giải thích…');
        Artisan::call('trips:incident-bench', ['--explain' => true, '--csv' => 'storage/app/bench/incident.csv']);
        $lines = explode("\n", trim(Artisan::output()));
        $from = null;
        foreach ($lines as $i => $l) {
            if (str_starts_with($l, 'Sự cố gây được')) {
                $from = $i;
            }
        }
        $md[] = '## Theo dõi chuyến và La bàn (trips:incident-bench --explain)';
        $md[] = '';
        $md[] = '```';
        $md = array_merge($md, $from !== null ? array_slice($lines, $from) : array_slice($lines, -12));
        $md[] = '```';
        $md[] = '';
        $md[] = 'Ghi chú: lời giải thích dùng mẫu khi máy chạy bench tắt AI; bật AI (AI_ENABLED, khóa API) rồi chạy lại để có số lời AI qua / bị chốt chặn.';

        foreach ($md as $l) {
            $this->line($l);
        }
        @mkdir(dirname(base_path($this->option('md'))), 0775, true);
        file_put_contents(base_path($this->option('md')), implode("\n", $md) . "\n");
        $this->info('Đã ghi ' . $this->option('md'));

        return self::SUCCESS;
    }
}
