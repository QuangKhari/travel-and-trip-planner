<?php

namespace App\Console\Commands;

use App\Support\ModuleMap;
use Illuminate\Console\Command;

/**
 * Bảng truy cập chéo giữa các phân hệ (config/modules.php):
 *   php artisan arch:modules            ma trận phân hệ gọi × phân hệ chủ bảng (số chỗ đọc / ghi), ma trận gọi lớp (giao diện / nội bộ) + vi phạm luật
 *   php artisan arch:modules --debt     chỉ liệt kê nợ kỹ thuật đã biết (baseline) còn tồn tại
 */
class ArchModules extends Command
{
    protected $signature = 'arch:modules {--debt}';
    protected $description = 'Ranh giới phân hệ: ai đọc / ghi bảng của ai, vi phạm và nợ kỹ thuật';

    public function handle(): int
    {
        $hits = ModuleMap::scan();
        $mods = array_keys((array) config('modules.modules'));
        if (!$this->option('debt')) {
            $rows = [];
            foreach ($mods as $m) {
                $row = [$m];
                foreach ($mods as $o) {
                    if ($m === $o) {
                        $row[] = '—';
                        continue;
                    }
                    $r = count(array_filter($hits, fn($h) => $h['module'] === $m && $h['owner'] === $o && $h['kind'] === 'read'));
                    $w = count(array_filter($hits, fn($h) => $h['module'] === $m && $h['owner'] === $o && $h['kind'] === 'write'));
                    $row[] = ($r || $w) ? $r . 'đ / ' . $w . 'g' : '';
                }
                $rows[] = $row;
            }
            $this->info('Số chỗ đọc (đ) / ghi (g) bảng của phân hệ khác — hàng: phân hệ gọi, cột: phân hệ chủ bảng');
            $this->table(array_merge(['Gọi \\ Chủ'], $mods), $rows);

            // gọi lớp: số lớp giao diện (app/Modules/{chủ}) / lớp nội bộ; * = cặp bị class_rules giới hạn chỉ được gọi giao diện
            $deps = ModuleMap::classDeps();
            $rules = (array) config('modules.class_rules', []);
            $rows = [];
            foreach ($mods as $m) {
                $row = [$m];
                foreach ($mods as $o) {
                    if ($m === $o) {
                        $row[] = '—';
                        continue;
                    }
                    $pair = array_filter($deps, fn($h) => $h['module'] === $m && $h['owner'] === $o);
                    $api = count(array_unique(array_column(array_filter($pair, fn($h) => str_starts_with($h['class'], 'App\\Modules\\')), 'class')));
                    $inner = count(array_unique(array_column(array_filter($pair, fn($h) => !str_starts_with($h['class'], 'App\\Modules\\')), 'class')));
                    $strict = in_array($o, (array) ($rules[$m] ?? []), true) ? '*' : '';
                    $row[] = ($api || $inner || $strict) ? $api . ' gd / ' . $inner . ' nb' . $strict : '';
                }
                $rows[] = $row;
            }
            $this->info('Số lớp của phân hệ khác được gọi: gd = giao diện app/Modules, nb = lớp nội bộ; * = chỉ được gọi giao diện (class_rules)');
            $this->table(array_merge(['Gọi \\ Chủ'], $mods), $rows);
        }
        $base = array_flip((array) config('modules.baseline', []));
        $v = ModuleMap::violations();
        $new = array_values(array_filter($v, fn($x) => !isset($base[$x['key']])));
        $debt = array_values(array_unique(array_column(array_filter($v, fn($x) => isset($base[$x['key']])), 'key')));
        $this->line('Nợ kỹ thuật đã biết còn tồn tại: ' . count($debt) . ' / ' . count($base));
        foreach ($debt as $d) {
            $this->line('  - ' . $d);
        }
        if ($new) {
            $this->error(count($new) . ' vi phạm MỚI:');
            foreach ($new as $x) {
                $this->line($x['kind'] === 'class'
                    ? sprintf('  %s:%d %s gọi lớp nội bộ %s của %s (hãy gọi giao diện trong app/Modules/%s)', $x['file'], $x['line'], $x['module'], $x['class'], $x['owner'], $x['owner'])
                    : sprintf(
                        '  %s:%d %s %s bảng %s của %s%s',
                        $x['file'],
                        $x['line'],
                        $x['module'],
                        $x['kind'] === 'write' ? 'GHI' : 'đọc',
                        $x['table'],
                        $x['owner'],
                        $x['rule'] === 'deny' ? ' (bảng cấm với ' . $x['module'] . ': deny_tables)' : '',
                    ));
            }

            return self::FAILURE;
        }
        $this->info('Không có vi phạm mới.');

        return self::SUCCESS;
    }
}
