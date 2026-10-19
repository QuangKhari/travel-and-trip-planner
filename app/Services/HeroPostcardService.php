<?php

namespace App\Services;

use App\Support\DiscoverImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Danh sách bưu ảnh cho hero trang chủ (và API GET /api/v1/seasons).
 * Chỉ lấy điểm đến đang bật, có ảnh bìa tồn tại thật trên disk (DiscoverImage::url trả null nếu thiếu).
 * Xếp: điểm đến "đẹp nhất vào tháng này" (theo config/postcards.php) trước, rồi theo điểm cảm hứng cao nhất.
 */
class HeroPostcardService
{
    public function forMonth(int $month, ?int $limit = null): array
    {
        $month = max(1, min(12, $month));
        $limit = $limit ?? (int) config('postcards.limit', 8);

        return Cache::remember("postcards.v1.m{$month}.l{$limit}", 3600, fn() => $this->build($month, $limit));
    }

    private function build(int $month, int $limit): array
    {
        $seasons = config('postcards.seasons', []);

        $rows = DB::table('tbl_destinations as d')
            ->leftJoin('tbl_destination_inspiration as di', 'di.destinationId', '=', 'd.destinationId')
            ->where('d.isActive', 1)
            ->whereNotNull('d.coverImage')
            ->groupBy('d.destinationId', 'd.slug', 'd.name', 'd.province', 'd.coverImage', 'd.imageCredit', 'd.imageSourceUrl')
            ->get([
                'd.slug',
                'd.name',
                'd.province',
                'd.coverImage',
                'd.imageCredit',
                'd.imageSourceUrl',
                DB::raw('COALESCE(MAX(di.score), 0) as topScore'),
            ]);

        $items = [];
        foreach ($rows as $r) {
            $img = DiscoverImage::url($r->coverImage);
            if (!$img) {
                continue; // thiếu ảnh thì bỏ qua, đúng tài liệu
            }
            $s = $seasons[$r->slug] ?? null;
            $best = $s && in_array($month, $s['m'], true);

            $items[] = [
                'slug'      => $r->slug,
                'name'      => $r->name,
                'province'  => $r->province,
                'image'     => $img,
                'best'      => $best,
                'why'       => $best ? $s['w'] . ' · đẹp nhất vào tháng ' . $month : 'Điểm nổi bật quanh năm',
                'url'       => route('destination.show', ['slug' => $r->slug]),
                'credit'    => $r->imageCredit,
                'creditUrl' => $r->imageSourceUrl,
                'score'     => (int) $r->topScore,
            ];
        }

        usort($items, fn($a, $b) => [$b['best'], $b['score'], $a['name']] <=> [$a['best'], $a['score'], $b['name']]);

        return array_slice($items, 0, $limit);
    }
}
