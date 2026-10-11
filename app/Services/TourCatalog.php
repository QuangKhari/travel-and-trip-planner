<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Truy vấn danh sách tour cho trang /tours (lọc + sắp xếp + tìm kiếm).
 * Mọi cột đều ghi rõ bảng (tbl_tours.*) để không bị lỗi "ambiguous" khi JOIN tbl_reviews.
 */
class TourCatalog
{
    public const SORTS = ['new', 'old', 'price_asc', 'price_desc', 'rating', 'soon'];

    /** Số ngày: nhóm -> [từ, đến] */
    public const DAY_GROUPS = ['short' => [1, 3], 'mid' => [4, 5], 'long' => [6, 99]];

    public function paginate(array $f, int $perPage = 9): LengthAwarePaginator
    {
        $cols = [
            'tbl_tours.tourId',
            'tbl_tours.title',
            'tbl_tours.priceAdult',
            'tbl_tours.priceChild',
            'tbl_tours.time',
            'tbl_tours.destination',
            'tbl_tours.quantity',
            'tbl_tours.startDate',
            'tbl_tours.endDate',
            'tbl_tours.domain',
        ];

        $q = DB::table('tbl_tours')
            ->leftJoin('tbl_reviews', 'tbl_tours.tourId', '=', 'tbl_reviews.tourId')
            ->select(array_merge($cols, [
                DB::raw('AVG(tbl_reviews.rating) as averageRating'),
                DB::raw('COUNT(tbl_reviews.reviewId) as reviewCount'),
            ]))
            ->where('tbl_tours.availability', 1)
            ->where('tbl_tours.quantity', '>', 0)
            ->whereDate('tbl_tours.startDate', '>', now()->toDateString())
            ->groupBy($cols);

        if (!empty($f['keyword'])) {
            $like = '%' . addcslashes($f['keyword'], '%_\\') . '%';
            $q->where(function ($w) use ($like) {
                $w->where('tbl_tours.title', 'like', $like)
                    ->orWhere('tbl_tours.destination', 'like', $like)
                    ->orWhere('tbl_tours.time', 'like', $like);
            });
        }
        // Khoảng ngày (Y-m-d): tour khởi hành từ ngày `from` và kết thúc trước hoặc đúng ngày `to`
        if (!empty($f['from'])) {
            $q->whereDate('tbl_tours.startDate', '>=', $f['from']);
        }
        if (!empty($f['to'])) {
            $q->whereDate('tbl_tours.endDate', '<=', $f['to']);
        }
        if (!empty($f['domain'])) {
            $q->where('tbl_tours.domain', $f['domain']);
        }
        if (isset($f['min']) && $f['min'] !== null) {
            $q->where('tbl_tours.priceAdult', '>=', $f['min']);
        }
        if (isset($f['max']) && $f['max'] !== null) {
            $q->where('tbl_tours.priceAdult', '<=', $f['max']);
        }
        if (!empty($f['days']) && isset(self::DAY_GROUPS[$f['days']])) {
            [$a, $b] = self::DAY_GROUPS[$f['days']];
            $q->whereRaw("CAST(SUBSTRING_INDEX(tbl_tours.time, ' ', 1) AS UNSIGNED) BETWEEN ? AND ?", [$a, $b]);
        }
        if (!empty($f['rating'])) {
            $q->havingRaw('AVG(tbl_reviews.rating) >= ?', [(int) $f['rating']]);
        }

        switch ($f['sort'] ?? 'new') {
            case 'old':
                $q->orderBy('tbl_tours.tourId', 'asc');
                break;
            case 'price_asc':
                $q->orderBy('tbl_tours.priceAdult', 'asc');
                break;
            case 'price_desc':
                $q->orderBy('tbl_tours.priceAdult', 'desc');
                break;
            case 'soon':
                $q->orderBy('tbl_tours.startDate', 'asc');
                break;
            case 'rating':
                $q->orderByRaw('AVG(tbl_reviews.rating) IS NULL')->orderByRaw('AVG(tbl_reviews.rating) DESC');
                break;
            default:
                $q->orderBy('tbl_tours.tourId', 'desc');
        }
        if (($f['sort'] ?? 'new') !== 'new') {
            $q->orderBy('tbl_tours.tourId', 'desc');
        }

        $tours = $q->paginate($perPage, ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));
        $this->hydrate($tours);

        return $tours;
    }

    /** Khoảng giá thật của các tour đang mở bán (làm ranh giới thanh trượt). */
    public function priceBounds(): array
    {
        $row = DB::table('tbl_tours')
            ->where('availability', 1)->where('quantity', '>', 0)
            ->whereDate('startDate', '>', now()->toDateString())
            ->selectRaw('MIN(priceAdult) as mn, MAX(priceAdult) as mx')->first();

        $step = 100000;
        $min = (int) (floor(((float) ($row->mn ?? 0)) / $step) * $step);
        $max = (int) (ceil(((float) ($row->mx ?? 0)) / $step) * $step);
        if ($max <= $min) {
            $max = $min + $step * 10;
        }

        return [$min, $max];
    }

    private function hydrate(LengthAwarePaginator $tours): void
    {
        $items = $tours->getCollection();
        if ($items->isEmpty()) {
            return;
        }

        $imagesByTour = DB::table('tbl_images')
            ->whereIn('tourId', $items->pluck('tourId')->all())
            ->orderBy('sortOrder')
            ->get(['tourId', 'imageURL'])
            ->groupBy('tourId');

        foreach ($items as $tour) {
            $tour->images = collect($imagesByTour->get($tour->tourId, []))->pluck('imageURL')->values();
            $tour->rating = $tour->averageRating !== null ? (float) $tour->averageRating : null;
            $tour->ratingCount = (int) $tour->reviewCount;
        }
    }
}
