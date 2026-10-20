<?php

namespace App\Console\Commands;

use App\Services\Climate\OpenMeteoArchive;
use App\Services\Content\ImportRaw;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClimateFetch extends Command
{
    protected $signature = 'climate:fetch
        {--slug= : Chỉ một điểm đến}
        {--years= : Số năm gần nhất (mặc định theo config/climate.php)}
        {--refresh : Gọi lại API dù vùng đệm đã có}';

    protected $description = 'A5: lấy khí hậu nhiều năm từ Open-Meteo Archive, gộp 12 tháng, lưu tbl_destination_climate (vùng đệm: tbl_import_raw)';

    public function handle(OpenMeteoArchive $api): int
    {
        $years = max(1, (int) ($this->option('years') ?: config('climate.years')));
        $toYear = (int) now()->format('Y') - 1;       // chỉ lấy năm đã kết thúc
        $fromYear = $toYear - $years + 1;
        [$from, $to] = ["{$fromYear}-01-01", "{$toYear}-12-31"];

        $q = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('destinationId');
        if ($s = $this->option('slug')) {
            $q->where('slug', $s);
        }
        $list = $q->get(['destinationId', 'slug', 'latitude', 'longitude']);
        $this->info("Khí hậu {$fromYear}-{$toYear} cho {$list->count()} điểm đến (Open-Meteo, CC BY 4.0).");
        $ok = $fail = 0;

        foreach ($list as $d) {
            $key = "{$d->slug}:{$fromYear}-{$toYear}";
            try {
                $raw = $this->option('refresh') ? null : ImportRaw::get('openmeteo', $key);
                if ($raw === null) {
                    $daily = $api->fetch((float) $d->latitude, (float) $d->longitude, $from, $to)['daily'];
                    $raw = ['from' => $from, 'to' => $to, 'months' => OpenMeteoArchive::aggregate($daily, (float) config('climate.rainy_day_mm'))];
                    ImportRaw::put('openmeteo', $key, $raw);
                    usleep((int) config('climate.delay_us'));
                }
                $rows = [];
                foreach ($raw['months'] as $mo => $m) {
                    if ($m['tMax'] === null) {
                        continue;
                    }
                    $rows[] = [
                        'destinationId' => $d->destinationId,
                        'month' => (int) $mo,
                        'tMax' => $m['tMax'],
                        'tMin' => $m['tMin'],
                        'rainMm' => $m['rainMm'],
                        'rainyDays' => $m['rainyDays'],
                        'years' => $m['years'],
                        'periodFrom' => $fromYear,
                        'periodTo' => $toYear,
                        'fetchedAt' => now()
                    ];
                }
                DB::table('tbl_destination_climate')->upsert($rows, ['destinationId', 'month'], ['tMax', 'tMin', 'rainMm', 'rainyDays', 'years', 'periodFrom', 'periodTo', 'fetchedAt']);
                $this->line("✓ {$d->slug}: " . count($rows) . ' tháng');
                $ok++;
            } catch (\Throwable $e) {
                $this->warn("✗ {$d->slug}: " . $e->getMessage());
                $fail++;
            }
        }
        $this->info("Xong: {$ok} thành công, {$fail} lỗi. Chạy tiếp: php artisan climate:build-months");

        return $fail && !$ok ? self::FAILURE : self::SUCCESS;
    }
}
