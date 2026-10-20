<?php

namespace App\Console\Commands;

use App\Services\Climate\ClimateRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ClimateBuildMonths extends Command
{
    protected $signature = 'climate:build-months
        {--slug= : Chỉ một điểm đến}
        {--compare : Chỉ so sánh luật với dữ liệu nhập tay, không ghi DB}
        {--force : Ghi đè cả dòng source=manual (giữ lại note cũ nếu tháng vẫn là best)}
        {--dry-run : Không ghi DB}';

    protected $description = 'A5: sinh tbl_destination_months (source=rule) từ khí hậu bằng luật trong config/climate.php';

    public function handle(): int
    {
        $rules = config('climate.rules');
        $q = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name');
        if ($s = $this->option('slug')) {
            $q->where('slug', $s);
        }
        $agree = $total = 0;
        $labels = ['best' => 'B', 'ok' => '-', 'avoid' => 'X'];

        foreach ($q->get(['destinationId', 'slug']) as $d) {
            $climate = DB::table('tbl_destination_climate')->where('destinationId', $d->destinationId)->get()->keyBy('month');
            if ($climate->isEmpty()) {
                $this->warn("• {$d->slug}: chưa có khí hậu (chạy climate:fetch)");
                continue;
            }
            $months = [];
            foreach ($climate as $mo => $c) {
                $months[(int) $mo] = ['tMax' => (float) $c->tMax, 'tMin' => (float) $c->tMin, 'rainMm' => (float) $c->rainMm, 'rainyDays' => (float) $c->rainyDays];
            }
            $res = ClimateRules::evaluate($months, $rules);
            $existing = DB::table('tbl_destination_months')->where('destinationId', $d->destinationId)->get()->keyBy('month');

            if ($this->option('compare')) {
                $line = $manual = '';
                foreach (range(1, 12) as $mo) {
                    $r = $res[$mo]['level'] ?? null;
                    $e = $existing[$mo]->level ?? null;
                    $line .= $r ? $labels[$r] : '?';
                    $manual .= $e ? $labels[$e] : '?';
                    if ($r && $e) {
                        $total++;
                        $agree += ($r === $e) ? 1 : 0;
                    }
                }
                $this->line(sprintf('%-14s luật   %s', $d->slug, $line));
                $this->line(sprintf('%-14s nhập tay %s', '', $manual));
                continue;
            }

            $rows = [];
            foreach ($res as $mo => $x) {
                $old = $existing[$mo] ?? null;
                if ($old && $old->source === 'manual' && !$this->option('force')) {
                    continue;                      // không đè dữ liệu nhập tay
                }
                $note = $x['note'];
                if ($old && $old->source === 'manual' && $old->note && $x['level'] === 'best') {
                    $note = $old->note;             // giữ lý do đã viết tay
                }
                $rows[] = ['destinationId' => $d->destinationId, 'month' => $mo, 'level' => $x['level'], 'note' => $note, 'source' => 'rule'];
            }
            if ($rows && !$this->option('dry-run')) {
                DB::table('tbl_destination_months')->upsert($rows, ['destinationId', 'month'], ['level', 'note', 'source']);
            }
            $best = implode(',', array_keys(array_filter($res, fn($x) => $x['level'] === 'best')));
            $this->line("✓ {$d->slug}: ghi " . count($rows) . " tháng; best = {$best}");
        }

        if ($this->option('compare') && $total) {
            $this->info(sprintf('Trùng khớp luật với nhập tay: %d/%d tháng (%.0f%%). B = best, - = ok, X = avoid.', $agree, $total, 100 * $agree / $total));
        }
        if ($this->option('dry-run')) {
            $this->line('(dry-run: chưa ghi DB)');
        } elseif (!$this->option('compare')) {
            for ($m = 1; $m <= 12; $m++) {      // hero cache kết quả từng tháng 1 giờ
                Cache::forget("postcards.v2.m{$m}.l" . (int) config('postcards.limit', 8));
            }
        }

        return self::SUCCESS;
    }
}
