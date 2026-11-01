<?php

namespace App\Models\clients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Home extends Model
{
    use HasFactory;

    protected $table = 'tbl_tours';

    public function getHomeTours()
    {
        $tours = DB::table($this->table)
            ->where('availability', 1)
            ->where('quantity', '>', 0)
            ->whereDate('startDate', '>', now()->toDateString())
            ->orderBy('startDate')
            ->limit(12) // 4 tour hiện ra; số còn lại để trang chủ ưu tiên lại theo cảm hứng người xem chọn
            ->get();

        $tourIds = $tours->pluck('tourId')->all();

        if (empty($tourIds)) {
            return $tours;
        }

        $imagesByTour = DB::table('tbl_images')
            ->whereIn('tourId', $tourIds)
            ->orderBy('sortOrder')
            ->get(['tourId', 'imageURL'])
            ->groupBy('tourId');

        $timelinesByTour = DB::table('tbl_timeline')
            ->whereIn('tourId', $tourIds)
            ->orderBy('timeLineId')
            ->get(['tourId', 'title'])
            ->groupBy('tourId');

        $ratingsByTour = \App\Support\ReviewScope::visible(DB::table('tbl_reviews'))
            ->whereIn('tourId', $tourIds)
            ->groupBy('tourId')
            ->select('tourId', DB::raw('AVG(rating) as avgRating'), DB::raw('COUNT(*) as ratingCount'))
            ->get()
            ->keyBy('tourId');

        // slug điểm đến của từng tour (để khớp với điểm theo cảm hứng trên bản đồ)
        $destSlugs = DB::table('tbl_destinations')
            ->whereIn('destinationId', $tours->pluck('destinationId')->filter()->unique()->all())
            ->pluck('slug', 'destinationId');

        foreach ($tours as $tour) {
            $tour->destSlug = $destSlugs[$tour->destinationId ?? 0] ?? null;
            $tour->rating = optional($ratingsByTour->get($tour->tourId))->avgRating;
            $tour->ratingCount = optional($ratingsByTour->get($tour->tourId))->ratingCount ?? 0;

            $tour->images = collect($imagesByTour->get($tour->tourId, []))
                ->pluck('imageURL')
                ->values();

            $tour->timelines = collect($timelinesByTour->get($tour->tourId, []))
                ->pluck('title')
                ->values();
        }

        return $tours;
    }
}
