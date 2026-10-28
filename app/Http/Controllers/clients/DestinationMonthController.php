<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\DestinationMonthBrief;

/** GET /diem-den/{slug}/thang-{n}: "Điểm đến X vào tháng N" (tuần 4, kế hoạch v6 mục 10). Mọi dữ liệu do DestinationMonthBrief. */
class DestinationMonthController extends Controller
{
    public function show(string $slug, int $n, DestinationMonthBrief $brief)
    {
        $b = $brief->forSlug($slug, $n) ?? abort(404);
        $name = $b['destination']['name'];

        // Trang gần như trống (chưa có đánh giá mùa lẫn khí hậu) không đưa vào chỉ mục tìm kiếm
        $thin = !$b['hasRating'] && !$b['climate'];

        return view('clients.destination-month', [
            'title' => "{$name} tháng {$n}: thời tiết, lễ hội, món ăn và nên làm gì",
            'metaDescription' => DestinationMonthBrief::summaryText($b),
            'canonical' => route('destination.month', ['slug' => $slug, 'n' => $n]),
            'noindex' => $thin,
            'b' => $b,
            'plannerUrl' => config('postcards.planner_url') ? url(config('postcards.planner_url')) : null,
        ]);
    }
}
