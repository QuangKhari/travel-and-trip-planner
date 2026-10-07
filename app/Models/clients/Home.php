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
            ->inRandomOrder()
            ->limit(6)
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

        foreach ($tours as $tour) {
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
