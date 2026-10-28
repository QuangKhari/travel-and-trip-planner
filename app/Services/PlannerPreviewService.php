<?php

namespace App\Services;

use App\Services\Content\DestinationMonthBrief;
use App\Support\DiscoverImage;
use Illuminate\Support\Facades\DB;

/**
 * D4: xem trước nhanh cho khối Planner ở trang chủ (khi chưa có trang /len-ke-hoach).
 * Chỉ ghép dữ liệu đã có: mức mùa đẹp của tháng, lịch trình mẫu đã duyệt, tour còn chỗ.
 * Không phải Planner thật: không chấm điểm, không tối ưu lộ trình.
 */
class PlannerPreviewService
{
    public function __construct(private HeroPostcardService $postcards) {}

    public function preview(?string $slug, ?int $days, int $month): array
    {
        $dest = $slug ? DB::table('tbl_destinations')->where('isActive', 1)->where('slug', $slug)->first() : null;

        $out = ['month' => $month, 'days' => $days, 'destination' => null, 'verdict' => null,
            'itineraries' => [], 'tours' => [], 'suggestions' => []];

        if (!$dest) {
            foreach (array_slice($this->postcards->forMonth($month), 0, 4) as $p) {
                if (($p['type'] ?? 'destination') === 'destination') {
                    $out['suggestions'][] = ['name' => $p['name'], 'why' => $p['why'], 'url' => $p['url']];
                }
            }

            return $out;
        }

        $out['destination'] = ['name' => $dest->name, 'url' => route('destination.show', ['slug' => $dest->slug])];

        // Tuần 4: một nguồn duy nhất cho câu trả lời theo thời điểm (cùng trang /diem-den/{slug}/thang-{n})
        $brief = app(DestinationMonthBrief::class)->for((int) $dest->destinationId, $month);
        if ($brief && $brief['hasRating'] && in_array($brief['rating']['level'], ['best', 'ok', 'avoid'], true)) {
            $out['verdict'] = ['level' => $brief['rating']['level'], 'text' => DestinationMonthBrief::summaryText($brief),
                'url' => route('destination.month', ['slug' => $dest->slug, 'n' => $month])];
        }

        $base = fn() => DB::table('tbl_itineraries as i')->where('i.reviewStatus', 'published')
            ->when($days, fn($q) => $q->whereBetween('i.days', [max(1, $days - 1), $days + 1]));
        $rows = $base()->whereExists(fn($s) => $s->from('tbl_itinerary_items as it')
            ->whereColumn('it.itineraryId', 'i.itineraryId')->where('it.refType', 'destination')->where('it.refId', $dest->destinationId))
            ->orderByRaw('ABS(i.days - ?)', [$days ?? 0])->limit(3)->get();
        if ($rows->isEmpty() && $dest->domain) {
            $rows = $base()->where('i.region', $dest->domain)->orderByRaw('ABS(i.days - ?)', [$days ?? 0])->limit(3)->get();
        }
        foreach ($rows as $i) {
            $out['itineraries'][] = ['title' => $i->title, 'days' => (int) $i->days, 'summary' => mb_substr((string) $i->summary, 0, 140),
                'url' => route('itineraries.show', ['slug' => $i->slug]), 'image' => DiscoverImage::url($i->coverImage)];
        }

        $tours = DB::table('tbl_tours')->where('destinationId', $dest->destinationId)->where('availability', 1)
            ->where('quantity', '>', 0)->whereDate('startDate', '>', now()->toDateString())
            ->orderBy('startDate')->limit(3)->get(['tourId', 'title', 'startDate', 'priceAdult']);
        foreach ($tours as $t) {
            $out['tours'][] = ['title' => $t->title, 'start' => date('d/m/Y', strtotime($t->startDate)),
                'price' => number_format((float) $t->priceAdult, 0, ',', '.') . 'đ', 'url' => route('tour-detail', ['id' => $t->tourId])];
        }

        return $out;
    }
}
