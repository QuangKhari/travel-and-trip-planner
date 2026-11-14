<?php

namespace App\Console\Commands;

use App\Services\Planner\TripWatch;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bản tin chuyến đi theo mốc: trước 7 ngày, trước 1 ngày, sáng mỗi ngày đang đi. Mỗi mốc gửi một lần (tbl_trip_events kind=digest).
 *   php artisan trips:digest [--date=2026-12-01] [--no-mail]
 */
class TripDigestCommand extends Command
{
    protected $signature = 'trips:digest {--date= : Coi như hôm nay là ngày này} {--no-mail : Chỉ ghi vào chuông trong trang}';

    protected $description = 'Gửi bản tin chuyến đi trước 7 ngày, trước 1 ngày và sáng mỗi ngày đi';

    public function handle(TripWatch $watch): int
    {
        if (!TripWatch::ready()) {
            $this->warn('Cần chạy php artisan migrate (2026_12_08_000001).');

            return self::SUCCESS;
        }
        $today = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $n = 0;
        $trips = DB::table('tbl_trips')->where('kind', 'trip')->whereNotNull('startDate')->whereNotIn('status', ['done', 'cancelled'])
            ->where('startDate', '<=', $today->copy()->addDays(7)->toDateString())
            ->whereRaw('DATE_ADD(startDate, INTERVAL GREATEST(days, 1) - 1 DAY) >= ?', [$today->toDateString()])->get();
        foreach ($trips as $trip) {
            if ($m = $watch->digest($trip, $today, !$this->option('no-mail'))) {
                $this->line("#{$trip->tripId} {$trip->title}: bản tin $m");
                $n++;
            }
        }
        $this->info("Đã gửi $n bản tin.");

        return self::SUCCESS;
    }
}
