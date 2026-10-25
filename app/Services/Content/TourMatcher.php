<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/** Tour còn chỗ, sắp khởi hành tại các điểm đến cho trước (cùng điều kiện với PlannerPreviewService). */
class TourMatcher
{
    /** @param int[] $destinationIds  @return array<int,array{title:string,start:string,price:string,url:string}> */
    public function forDestinations(array $destinationIds, int $limit = 3): array
    {
        $ids = array_values(array_filter(array_map('intval', $destinationIds)));
        if (!$ids) {
            return [];
        }

        return DB::table('tbl_tours')->whereIn('destinationId', $ids)->where('availability', 1)
            ->where('quantity', '>', 0)->whereDate('startDate', '>', now()->toDateString())
            ->orderBy('startDate')->limit($limit)->get(['tourId', 'title', 'startDate', 'priceAdult'])
            ->map(fn($t) => [
                'title' => $t->title,
                'start' => date('d/m/Y', strtotime($t->startDate)),
                'price' => number_format((float) $t->priceAdult, 0, ',', '.') . 'đ',
                'url' => route('tour-detail', ['id' => $t->tourId]),
            ])->all();
    }
}
