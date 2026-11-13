<?php

namespace App\Console\Commands;

use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\SearchCatalog;
use App\Services\Content\SiteStats;
use App\Services\HeroPostcardService;
use App\Services\HomeFeedService;
use App\Services\Planner\TripWeather;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PF-11: nạp sẵn cache trước giờ cao điểm / buổi demo, để lượt truy cập đầu tiên không phải dựng lại dữ liệu.
 *   php artisan cache:warm [--only=home,stats,search,briefs,weather] [--json]
 * (Chỗ trống tour không nạp sẵn: cache của nó chỉ sống 15 giây để luôn đúng.)
 * Mỗi phần chạy độc lập (một phần lỗi không chặn phần khác), in thời gian từng phần. Chạy lại an toàn (chỉ ghi cache).
 */
class CacheWarm extends Command
{
    protected $signature = 'cache:warm {--only= : Danh sách phần, cách nhau dấu phẩy} {--json : In kết quả dạng JSON}';

    protected $description = 'Nạp sẵn cache: trang chủ, số liệu, tìm kiếm, mùa đi theo tháng, dự báo thời tiết của chuyến sắp đi';

    public function handle(): int
    {
        $parts = [
            'home' => fn() => $this->home(),
            'stats' => fn() => count(app(SiteStats::class)->get()),
            'search' => function () {
                app(SearchCatalog::class)->suggest('ha', 1);   // dựng chỉ mục tìm kiếm (cache 10 phút)

                return 1;
            },
            'briefs' => fn() => $this->briefs(),
            'weather' => fn() => $this->weather(),
        ];
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        if ($only) {
            $parts = array_intersect_key($parts, array_flip($only));
        }
        $rows = [];
        $fail = 0;
        foreach ($parts as $name => $fn) {
            $t = microtime(true);
            try {
                $n = $fn();
                $rows[] = ['part' => $name, 'items' => (int) $n, 'ms' => (int) round((microtime(true) - $t) * 1000), 'ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                $fail++;
                report($e);
                $rows[] = ['part' => $name, 'items' => 0, 'ms' => (int) round((microtime(true) - $t) * 1000), 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 120)];
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Phần', 'Số mục', 'Thời gian (ms)', 'Kết quả'], array_map(fn($r) => [$r['part'], $r['items'], $r['ms'], $r['ok'] ? 'OK' : 'Lỗi: ' . $r['error']], $rows));
        }

        return $fail ? self::FAILURE : self::SUCCESS;
    }

    private function home(): int
    {
        $m = (int) now()->month;
        app(HomeFeedService::class)->forMonth($m);
        app(HeroPostcardService::class)->forMonth($m);

        return 2;
    }

    /** Mùa đi tháng này + tháng sau cho mọi điểm đến đang hiện. */
    private function briefs(): int
    {
        $svc = app(DestinationMonthBrief::class);
        $n = 0;
        foreach (DB::table('tbl_destinations')->where('isActive', 1)->pluck('destinationId') as $id) {
            foreach ([now(), now()->addMonthNoOverflow()] as $d) {
                $svc->for((int) $id, (int) $d->month, (int) $d->year);
                $n++;
            }
        }

        return $n;
    }

    /** Dự báo theo giờ cho điểm đến của các chuyến khởi hành trong phạm vi dự báo (gộp một lần gọi Open-Meteo). */
    private function weather(): int
    {
        if (!TripWeather::enabled()) {
            return 0;
        }
        $pts = DB::table('tbl_trips as t')->join('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('t.kind', 'trip')->whereNotNull('t.startDate')
            ->whereBetween('t.startDate', [today()->subDays(7)->toDateString(), today()->addDays((int) config('planner.weather.days', 16) - 1)->toDateString()])
            ->whereNotNull('d.latitude')->whereNotNull('d.longitude')->distinct()->get(['d.latitude', 'd.longitude'])
            ->map(fn($r) => [(float) $r->latitude, (float) $r->longitude])->all();

        return count(array_filter(app(TripWeather::class)->forPoints($pts)));
    }
}
