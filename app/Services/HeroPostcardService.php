<?php

namespace App\Services;

use App\Support\DiscoverImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Danh sách bưu ảnh cho hero trang chủ (và API GET /api/v1/seasons).
 * v2: mùa đẹp đọc từ tbl_destination_months (level best/ok/avoid), không đọc config nữa.
 * Điểm đến level 'avoid' trong tháng bị loại; 'best' lên trước; còn lại xếp theo điểm cảm hứng cao nhất.
 * Chỉ lấy điểm đến đang bật có ảnh bìa tồn tại thật trên disk.
 */
class HeroPostcardService
{
    public function forMonth(int $month, ?int $limit = null): array
    {
        $month = max(1, min(12, $month));
        $limit = $limit ?? (int) config('postcards.limit', 8);

        return Cache::remember("postcards.v2.m{$month}.l{$limit}", 3600, fn() => $this->build($month, $limit));
    }

    private function build(int $month, int $limit): array
    {
        $rows = DB::table('tbl_destinations as d')
            ->leftJoin('tbl_destination_inspiration as di', 'di.destinationId', '=', 'd.destinationId')
            ->leftJoin('tbl_destination_months as dm', function ($j) use ($month) {
                $j->on('dm.destinationId', '=', 'd.destinationId')->where('dm.month', '=', $month);
            })
            ->where('d.isActive', 1)
            ->whereNotNull('d.coverImage')
            ->where(fn($q) => $q->whereNull('dm.level')->orWhere('dm.level', '!=', 'avoid'))
            ->groupBy('d.destinationId', 'd.slug', 'd.name', 'd.province', 'd.coverImage', 'd.imageCredit', 'd.imageSourceUrl', 'dm.level', 'dm.note')
            ->get([
                'd.slug',
                'd.name',
                'd.province',
                'd.coverImage',
                'd.imageCredit',
                'd.imageSourceUrl',
                'dm.level',
                'dm.note',
                DB::raw('COALESCE(MAX(di.score), 0) as topScore'),
            ]);

        $items = [];
        foreach ($rows as $r) {
            $img = DiscoverImage::url($r->coverImage);
            if (!$img) {
                continue;
            }
            $best = $r->level === 'best';

            $items[] = [
                'slug'      => $r->slug,
                'name'      => $r->name,
                'province'  => $r->province,
                'image'     => $img,
                'best'      => $best,
                'why'       => $best
                    ? trim(($r->note ? $r->note . ' · ' : '') . 'đẹp nhất vào tháng ' . $month)
                    : 'Điểm nổi bật quanh năm',
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
