<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Con số tổng cho chân trang ("1.263 món ăn đặc sản"...). Chỉ đếm nội dung đã duyệt, cache 1 giờ, lỗi thì trả mảng rỗng. */
class SiteStats
{
    public function get(): array
    {
        try {
            return Cache::remember('sitestats.v1', 3600, function () {
                $n = fn(string $t, array $w) => \Illuminate\Support\Facades\Schema::hasTable($t) ? DB::table($t)->where($w)->count() : 0;

                return array_filter([
                    'Điểm đến' => $n('tbl_destinations', ['isActive' => 1]),
                    'Món ăn đặc sản' => $n('tbl_foods', ['reviewStatus' => 'published']),
                    'Trải nghiệm' => $n('tbl_experiences', ['reviewStatus' => 'published']),
                    'Lịch trình' => $n('tbl_itineraries', ['reviewStatus' => 'published']),
                    'Bài cẩm nang' => $n('tbl_guides', ['reviewStatus' => 'published']),
                ]);
            });
        } catch (\Throwable) {
            return [];
        }
    }
}
