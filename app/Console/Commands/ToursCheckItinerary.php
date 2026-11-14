<?php

namespace App\Console\Commands;

use App\Services\TourStops;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Soát lịch trình theo giờ của mọi tour đang bán: lệch số ngày, ngày trống, chồng giờ, thiếu điểm tập trung / trả khách.
 *   php artisan tours:check-itinerary [--tour=12] [--missing : liệt kê cả tour chưa nhập lịch theo giờ]
 */
class ToursCheckItinerary extends Command
{
    protected $signature = 'tours:check-itinerary {--tour=} {--missing}';

    protected $description = 'Kiểm tra lịch trình theo giờ của các tour';

    public function handle(TourStops $stops): int
    {
        if (!TourStops::ready()) {
            $this->error('Chưa có bảng tbl_tour_stops: chạy php artisan migrate.');

            return self::FAILURE;
        }
        $q = DB::table('tbl_tours')->orderBy('tourId');
        if ($this->option('tour')) {
            $q->where('tourId', (int) $this->option('tour'));
        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('tbl_tours', 'availability')) {
            $q->where('availability', 1);
        }
        $rows = [];
        $withStops = DB::table('tbl_tour_stops')->distinct()->pluck('tourId')->flip();
        foreach ($q->get() as $t) {
            if (!isset($withStops[$t->tourId])) {
                if ($this->option('missing')) {
                    $rows[] = [$t->tourId, mb_strimwidth($t->title, 0, 50, '…'), 'Chưa nhập lịch theo giờ'];
                }
                continue;
            }
            foreach ($stops->check($t) as $issue) {
                $rows[] = [$t->tourId, mb_strimwidth($t->title, 0, 50, '…'), $issue];
            }
        }
        if (!$rows) {
            $this->info('Không thấy vấn đề (' . count($withStops) . ' tour có lịch theo giờ).');

            return self::SUCCESS;
        }
        $this->table(['Tour', 'Tên', 'Vấn đề'], $rows);

        return self::SUCCESS;
    }
}
