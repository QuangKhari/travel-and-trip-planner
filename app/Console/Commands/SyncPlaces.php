<?php

namespace App\Console\Commands;

use App\Services\Discover\PlaceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPlaces extends Command
{
    protected $signature = 'discover:sync-places
        {--slug= : Chỉ một điểm đến}
        {--provider= : overpass | geoapify (mặc định theo DISCOVER_POI_PROVIDER)}
        {--no-prune : Không xoá địa điểm cũ không còn trong kết quả}';

    protected $description = 'Đồng bộ địa điểm tham quan/văn hoá/ăn uống quanh mỗi điểm đến vào tbl_external_places';

    public function handle(PlaceSync $sync): int
    {
        $provider = $sync->provider($this->option('provider') ?: null);

        $q = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('destinationId');
        if ($slug = $this->option('slug')) {
            $q->where('slug', $slug);
        }
        $rows = $q->get();
        if ($rows->isEmpty()) {
            $this->error('Không có điểm đến nào khớp.');

            return self::FAILURE;
        }

        $this->info("Nguồn: {$provider->name()} - {$rows->count()} điểm đến (có thể mất vài phút).");
        $fail = 0;
        foreach ($rows as $i => $d) {
            try {
                $n = $sync->syncDestination($d, $provider, !$this->option('no-prune'));
                $this->line("✓ {$d->slug}: {$n} địa điểm");
            } catch (\Throwable $e) {
                $fail++;
                $this->warn("✗ {$d->slug}: " . $e->getMessage());
            }
            if ($i < $rows->count() - 1) {
                usleep((int) config('discover.places.delay_us'));
            }
        }

        $this->info($fail ? "Xong, {$fail} điểm đến lỗi - chạy lại với --slug=... sau." : 'Xong.');

        return $fail === $rows->count() ? self::FAILURE : self::SUCCESS;
    }
}
