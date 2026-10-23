<?php

namespace App\Services;

use App\Services\Content\ContentCatalog;
use App\Services\Content\GuideCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * D2: dữ liệu các dải trên trang chủ. Chỉ nội dung published (ContentCatalog/GuideCatalog đã lọc sẵn).
 * Cache 10 phút theo tháng và ngày. Dải nào rỗng thì view tự ẩn.
 */
class HomeFeedService
{
    public function __construct(private ContentCatalog $catalog, private GuideCatalog $guides) {}

    public function forMonth(int $month): array
    {
        $month = max(1, min(12, $month));

        return Cache::remember('homefeed.v1.m' . $month . '.' . date('Ymd'), 600, fn() => $this->build($month));
    }

    private function build(int $month): array
    {
        $season = collect($this->catalog->destinations(['thang' => $month], 1, 8)['items']->items())->values()->all();

        // Lễ hội: ưu tiên đợt bắt đầu trong 60 ngày tới; không có thì lấy 4 đợt gần nhất
        $events = collect($this->catalog->events([], 1, 60)['items']->items());
        $to = date('Y-m-d', strtotime('+60 days'));
        $soon = $events->filter(fn($c) => $c->start && $c->start <= $to)->take(8);
        $events = ($soon->isNotEmpty() ? $soon : $events->take(4))->values()->all();

        // Ẩm thực: chỉ món có ảnh; thứ tự đổi theo ngày nhưng ổn định trong ngày (cache được)
        $day = date('Ymd');
        $foods = collect($this->catalog->foods([], 1, 60)['items']->items())
            ->filter(fn($c) => !empty($c->image))
            ->sortBy(fn($c) => crc32($c->slug . $day))
            ->take(8)->values()->all();

        $itins = collect($this->catalog->itineraries([], 1, 8)['items']->items())->take(6)->values()->all();

        $guides = DB::table('tbl_guides as g')
            ->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')
            ->orderByDesc('g.updatedOn')->orderBy('g.title')->limit(4)
            ->get(['g.*', 'c.slug as catSlug', 'c.name as catName'])
            ->map(fn($g) => $this->guides->card($g))->values()->all();

        return compact('season', 'events', 'foods', 'itins', 'guides');
    }

    /** Danh sách điểm đến cho ô chọn của khối Planner. */
    public function destinationOptions(): array
    {
        return DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['slug', 'name', 'province'])
            ->map(fn($d) => ['slug' => $d->slug, 'name' => $d->name])->all();
    }
}
