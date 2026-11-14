<?php

namespace App\Console\Commands;

use App\Services\Planner\TripWatch;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Theo dõi các chuyến sắp đi (trong 16 ngày) và đang đi: dự báo theo giờ, nơi đóng cửa, cảnh báo điểm đến, giờ tới / giờ đi của vé.
 * Có thay đổi đáng kể thì ghi sự kiện (chuông trong trang) và gửi email kèm liên kết mở La bàn ở đúng cảnh báo; không có gì mới thì im lặng.
 *   php artisan trips:watch [--trip=12] [--dry] [--no-mail] [--date=2026-12-01]
 */
class TripWatchCommand extends Command
{
    protected $signature = 'trips:watch {--trip= : Chỉ một chuyến} {--dry : Chỉ in ra, không ghi, không gửi} {--no-mail : Ghi sự kiện, không gửi email} {--date= : Coi như hôm nay là ngày này}';

    protected $description = 'Theo dõi chuyến sắp đi / đang đi và báo khi thời tiết, cảnh báo điểm đến, giờ mở cửa thay đổi';

    public function handle(TripWatch $watch): int
    {
        if (!TripWatch::ready()) {
            $this->warn('Cần chạy php artisan migrate (2026_12_08_000001).');

            return self::SUCCESS;
        }
        $today = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $trips = $this->option('trip') ? \DB::table('tbl_trips')->where('tripId', (int) $this->option('trip'))->get() : $watch->dueTrips($today);
        $nNew = $nMail = 0;
        foreach ($trips as $trip) {
            if ($this->option('dry')) {
                $sig = $watch->signals($trip);
                $this->line("#{$trip->tripId} {$trip->title}: " . count($sig) . ' cảnh báo đang có');
                foreach ($sig as $s) {
                    $this->line('   [' . $s['tone'] . '] ' . $s['title']);
                }
                continue;
            }
            $res = $watch->observe($trip);
            $nNew += count($res['new']);
            if (!$this->option('no-mail') && $watch->shouldMail($trip, $res)) {
                $nMail += $watch->mailAlerts($trip, $res);
            }
            if ($res['new'] || $res['resolved']) {
                $this->line("#{$trip->tripId} {$trip->title}: +" . count($res['new']) . ' mới, ' . count($res['resolved']) . ' đã ổn');
            }
        }
        $this->info('Đã kiểm tra ' . count($trips) . ' chuyến, ' . $nNew . ' cảnh báo mới, ' . $nMail . ' email.');

        return self::SUCCESS;
    }
}
