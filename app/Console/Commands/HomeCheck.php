<?php

namespace App\Console\Commands;

use App\Services\EventDateService;
use App\Support\DiscoverImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** D5: vì sao một dải trang chủ đang trống? In số liệu từng dải và việc cần làm. */
class HomeCheck extends Command
{
    protected $signature = 'home:check';

    protected $description = 'Kiểm tra dữ liệu cho hero và các dải trang chủ (giai đoạn D)';

    public function handle(EventDateService $dates): int
    {
        $month = (int) date('n');
        $rows = [];
        $row = fn(string $name, string $num, bool $ok, string $fix) => $rows[] = [$name, $num, $ok ? 'OK' : $fix];

        $dest = DB::table('tbl_destinations')->where('isActive', 1)->get(['coverImage']);
        $img = $dest->filter(fn($d) => DiscoverImage::url($d->coverImage))->count();
        $row('Hero: điểm đến bật có ảnh thật', "{$img}/{$dest->count()}", $img >= 3,
            'Cần ≥3 (nên ≥6): php artisan content:import-images rồi kiểm storage/app/public/discover/destinations và `storage:link`');

        $best = DB::table('tbl_destination_months as dm')->join('tbl_destinations as d', 'd.destinationId', '=', 'dm.destinationId')
            ->where('d.isActive', 1)->where('dm.month', $month)->where('dm.level', 'best')->count();
        $row("Dải Đang vào mùa: điểm đến best tháng {$month}", (string) $best, $best > 0,
            'php artisan db:seed --class=DestinationMonthSeeder (hoặc sửa ở màn duyệt admin)');

        $ev = DB::table('tbl_events')->where('reviewStatus', 'published')->get();
        $soon = $ev->filter(function ($e) use ($dates) {
            $n = $dates->upcoming($e, null, 1)[0] ?? null;

            return $n && $n['start'] <= date('Y-m-d', strtotime('+60 days'));
        })->count();
        $row('Dải Lễ hội: published / tổng / bắt đầu trong 60 ngày',
            $ev->count() . '/' . DB::table('tbl_events')->count() . "/{$soon}", $ev->count() > 0,
            'Duyệt lễ hội: /admin/content/events (draft → published)');

        $evHero = $ev->filter(function ($e) use ($dates) {
            $n = $dates->upcoming($e, null, 1)[0] ?? null;

            return $n && !$n['approx'] && $n['start'] <= date('Y-m-d', strtotime('+30 days')) && DiscoverImage::url($e->coverImage);
        })->count();
        $row('Hero (D1): lễ hội published, có ảnh thật, ngày rõ, ≤30 ngày', (string) $evHero, $evHero > 0,
            'Cần lễ hội có coverImage tồn tại trên đĩa và bắt đầu trong 30 ngày tới; không có thì hero chỉ gồm điểm đến (bình thường)');

        foreach ([['tbl_foods', 'Ẩm thực', 'foods'], ['tbl_itineraries', 'Lịch trình', 'itineraries'], ['tbl_guides', 'Cẩm nang', 'guides']] as [$t, $label, $kind]) {
            $pub = DB::table($t)->where('reviewStatus', 'published')->count();
            $row("Dải {$label}: published/tổng", $pub . '/' . DB::table($t)->count(), $pub > 0,
                "Duyệt: /admin/content/{$kind} (draft → published)");
        }

        $tours = DB::table('tbl_tours')->where('availability', 1)->where('quantity', '>', 0)->whereDate('startDate', '>', now()->toDateString())->count();
        $row('Dải Tour nổi bật: tour còn chỗ, chưa khởi hành', (string) $tours, $tours > 0, 'Thêm tour ở admin');

        $this->table(['Hạng mục', 'Số liệu', 'Kết luận'], $rows);
        $this->line('Dải rỗng tự ẩn; sau khi sửa dữ liệu chạy: php artisan cache:clear');

        return self::SUCCESS;
    }
}
