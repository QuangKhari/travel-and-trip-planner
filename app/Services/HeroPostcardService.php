<?php

namespace App\Services;

use App\Services\Content\ContentCatalog;
use App\Support\DiscoverImage;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Danh sách bưu ảnh cho hero trang chủ (và API GET /api/v1/seasons).
 * v4 (D1): thêm lễ hội bắt đầu trong 30 ngày tới (type = event), xen vào giữa các điểm đến.
 * v3 (thêm thumb cho nền mờ). Mùa đẹp đọc từ tbl_destination_months (level best/ok/avoid), không đọc config nữa.
 * Điểm đến level 'avoid' trong tháng bị loại; 'best' lên trước; còn lại xếp theo điểm cảm hứng cao nhất.
 * Chỉ lấy điểm đến đang bật có ảnh bìa tồn tại thật trên disk.
 */
class HeroPostcardService
{
    public function forMonth(int $month, ?int $limit = null): array
    {
        $month = max(1, min(12, $month));
        $limit = $limit ?? (int) config('postcards.limit', 8);

        return Cache::remember("postcards.v4.m{$month}.l{$limit}", 3600, fn() => $this->build($month, $limit));
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
                'type'      => 'destination',
                'slug'      => $r->slug,
                'name'      => $r->name,
                'province'  => $r->province,
                'image'     => $img,
                'thumb'     => DiscoverImage::thumbUrl($r->coverImage),
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

        // Xen tối đa 3 lễ hội sắp diễn ra vào các vị trí 2, 5, 7 (đếm từ 1) để dải không toàn điểm đến
        foreach (array_slice($this->upcomingEvents(), 0, 3) as $i => $ev) {
            array_splice($items, min(count($items), [1, 4, 6][$i]), 0, [$ev]);
        }

        return array_slice($items, 0, $limit);
    }

    /** Lễ hội đã duyệt, có ảnh thật, ngày rõ (không phải "chỉ biết tháng"), bắt đầu hoặc đang diễn ra trong 30 ngày tới. */
    private function upcomingEvents(): array
    {
        $dates = app(\App\Services\EventDateService::class);
        $to = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
        $rows = DB::table('tbl_events as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->whereNotNull('e.coverImage')
            ->get(['e.slug', 'e.name', 'e.isLunar', 'e.lunarMonth', 'e.lunarDay', 'e.solarMonth', 'e.solarDay', 'e.durationDays',
                'e.coverImage', 'e.imageCredit', 'e.imageSourceUrl', 'd.name as destName']);
        $out = [];
        foreach ($rows as $e) {
            $img = DiscoverImage::url($e->coverImage);
            $next = $dates->upcoming($e, null, 1)[0] ?? null;
            if (!$img || !$next || $next['approx'] || $next['start'] > $to) {
                continue;
            }
            $out[] = [
                'type'      => 'event',
                'slug'      => $e->slug,
                'name'      => $e->name,
                'province'  => $e->destName ?? 'Cả nước',
                'image'     => $img,
                'thumb'     => DiscoverImage::thumbUrl($e->coverImage),
                'best'      => false,
                'why'       => 'Lễ hội ' . ContentCatalog::range($next),
                'url'       => route('events.show', ['slug' => $e->slug]),
                'credit'    => $e->imageCredit,
                'creditUrl' => $e->imageSourceUrl,
                'score'     => 0,
                'start'     => $next['start'],
            ];
        }
        usort($out, fn($a, $b) => $a['start'] <=> $b['start']);

        return $out;
    }
}
