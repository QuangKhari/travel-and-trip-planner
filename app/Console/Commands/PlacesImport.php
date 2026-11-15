<?php

namespace App\Console\Commands;

use App\Services\Content\PlaceImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nạp một tệp địa điểm thành lô chờ duyệt (không ghi thẳng vào kho). Duyệt ở Admin > Nhập địa điểm.
 *   php artisan places:import du-lieu/da-lat.csv --note="Khảo sát 11/2026"
 *   php artisan places:import --template > mau.csv
 */
class PlacesImport extends Command
{
    protected $signature = 'places:import {file? : Tệp .csv hoặc .json} {--note= : Ghi chú cho lô} {--template : In tệp CSV mẫu}';

    protected $description = 'Nạp tệp địa điểm thành lô chờ duyệt (kiểm tra lỗi, dò trùng)';

    public function handle(): int
    {
        if ($this->option('template')) {
            $this->output->write(PlaceImport::template());

            return self::SUCCESS;
        }
        $file = (string) $this->argument('file');
        if ($file === '' || !is_file($file)) {
            $this->error('Không thấy tệp ' . $file);

            return self::FAILURE;
        }
        if (!PlaceImport::ready()) {
            $this->error('Chưa chạy migration (php artisan migrate).');

            return self::FAILURE;
        }
        $r = PlaceImport::stage((string) file_get_contents($file), basename($file), null, $this->option('note'));
        if ($r['error']) {
            $this->error($r['error']);

            return self::FAILURE;
        }
        $this->info(sprintf('Lô #%d: %d dòng · %d lỗi · %d cần xem · %d có thể trùng.', $r['batchId'], $r['total'], $r['bad'], $r['warn'], $r['dup']));
        $bad = DB::table('tbl_import_rows')->where('batchId', $r['batchId'])->where('level', 'error')->orderBy('line')->limit(20)->get(['line', 'issues']);
        foreach ($bad as $b) {
            $msg = implode(' ', array_map(fn($i) => $i['msg'], array_filter(json_decode($b->issues, true), fn($i) => $i['level'] === 'error')));
            $this->line("  dòng {$b->line}: $msg");
        }
        $this->line('Duyệt tại: ' . route('admin.place-import.show', ['id' => $r['batchId']]));

        return self::SUCCESS;
    }
}
