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

        return Cache::remember('homefeed.v3.m' . $month . '.' . date('Ymd'), 600, fn() => $this->build($month));
    }

    private function build(int $month): array
    {
        $season = collect($this->catalog->destinations(['thang' => $month], 1, 8)['items']->items())->values()->all();

        // Lễ hội: ưu tiên đợt bắt đầu trong 60 ngày tới; không có thì lấy 4 đợt gần nhất
        $events = collect($this->catalog->events([], 1, 60)['items']->items());
        $to = date('Y-m-d', strtotime('+60 days'));
        $soon = $events->filter(fn($c) => $c->start && $c->start <= $to)->take(8);
        $events = ($soon->isNotEmpty() ? $soon : $events->take(4))->values()->all();

        // Ẩm thực (tài liệu mục 2): ưu tiên món có ảnh; chưa đủ 4 món có ảnh thì dùng thẻ thường cho mọi món đã duyệt
        $day = date('Ymd');
        $allFoods = collect($this->catalog->foods([], 1, 60)['items']->items());
        $withImg = $allFoods->filter(fn($c) => !empty($c->image));
        $foods = ($withImg->count() >= 4 ? $withImg : $allFoods)
            ->sortBy(fn($c) => [-(int) ($c->featured ?? 0), -(int) ($c->must ?? 0), crc32($c->slug . $day)])   // chọn tay trước, còn lại xoay theo ngày
            ->take(8)->values()->all();

        $seasonMix = $this->seasonMix($month, $season, $events);

        // Trải nghiệm: bảng chỉ có sau khi migrate, lỗi thì bỏ qua dải này chứ không làm sập trang chủ
        $experiences = [];
        try {
            $experiences = collect(app(\App\Services\Content\ExperienceCatalog::class)->list([], 1, 40)['items']->items())
                ->sortBy(fn($c) => [-(int) ($c->featured ?? 0), -(int) $c->must, crc32($c->slug . $day)])->take(8)->values()->all();
        } catch (\Throwable) {
        }

        $itins = collect($this->catalog->itineraries([], 1, 8)['items']->items())->take(6)->values()->all();

        $guides = DB::table('tbl_guides as g')
            ->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')
            ->orderByDesc('g.updatedOn')->orderBy('g.title')->limit(4)
            ->get(['g.*', 'c.slug as catSlug', 'c.name as catName'])
            ->map(fn($g) => $this->guides->card($g))->values()->all();

        return compact('season', 'events', 'seasonMix', 'experiences', 'foods', 'itins', 'guides');
    }

    /**
     * Dải "Đang vào mùa" kiểu GoVietnam: xen kẽ điểm đến đang đẹp và lễ hội sắp tới, mỗi thẻ có chip tháng.
     * Giữ nguyên $season và $events để chỗ khác dùng.
     */
    private function seasonMix(int $month, array $season, array $events): array
    {
        $slugs = array_map(fn($c) => $c->slug, $season);
        $bestMonths = [];
        if ($slugs) {
            DB::table('tbl_destination_months as dm')->join('tbl_destinations as d', 'd.destinationId', '=', 'dm.destinationId')
                ->whereIn('d.slug', $slugs)->where('dm.level', 'best')->orderBy('dm.month')
                ->get(['d.slug', 'dm.month'])->each(function ($r) use (&$bestMonths) {
                    $bestMonths[$r->slug][] = (int) $r->month;
                });
        }
        $dest = array_map(function ($c) use ($bestMonths, $month) {
            $c = clone $c;
            $c->chip = self::monthRun($bestMonths[$c->slug] ?? [], $month);

            return $c;
        }, $season);
        $evs = array_map(function ($c) {
            $c = clone $c;
            $c->chip = !empty($c->start) ? 'Lễ hội · Tháng ' . (int) substr($c->start, 5, 2) : 'Lễ hội';

            return $c;
        }, array_slice($events, 0, 4));

        $mix = [];
        for ($i = 0, $n = max(count($dest), count($evs)); $i < $n; $i++) {
            if (isset($dest[$i])) {
                $mix[] = $dest[$i];
            }
            if (isset($evs[$i])) {
                $mix[] = $evs[$i];
            }
        }

        return array_slice($mix, 0, 10);
    }

    /** [9,10,11,2] với tháng hiện tại 10 -> "Tháng 9–11" (đợt liên tiếp chứa tháng hiện tại). */
    private static function monthRun(array $months, int $cur): string
    {
        sort($months);
        if (!in_array($cur, $months, true)) {
            return 'Tháng ' . $cur;
        }
        $a = $b = $cur;
        while (in_array($a - 1, $months, true)) {
            $a--;
        }
        while (in_array($b + 1, $months, true)) {
            $b++;
        }

        return $a === $b ? 'Tháng ' . $a : 'Tháng ' . $a . '–' . $b;
    }

    /** Danh sách điểm đến cho ô chọn của khối Planner. */
    public function destinationOptions(): array
    {
        return DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['slug', 'name', 'province'])
            ->map(fn($d) => ['slug' => $d->slug, 'name' => $d->name])->all();
    }
}
