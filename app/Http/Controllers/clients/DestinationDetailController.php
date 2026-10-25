<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use App\Services\Content\TravelAdvice;
use App\Services\Content\UserMarks;
use App\Services\DestinationService;
use Illuminate\Http\Request;

class DestinationDetailController extends Controller
{
    /** GET /diem-den/{slug} */
    public function show(Request $request, string $slug, DestinationService $service, ContentCatalog $catalog, UserMarks $marks, TravelAdvice $travelAdvice)
    {
        $destination = $service->find($slug);
        if (!$destination) {
            abort(404);
        }

        $title = $destination->name;
        $data = $service->payload($destination);
        $tours = $service->tours($destination);

        // B3: lớp Khám phá (chỉ nội dung published)
        $id = (int) $destination->destinationId;
        $months = $catalog->months($id);
        $foods = $catalog->foodsOf($id, 6);
        $events = $catalog->eventsOf($id, 4);
        $itineraries = $catalog->itinerariesOf($id, 3);
        $nearby = $catalog->nearbyPlaces($destination, 8);
        $uid = $request->session()->has('userId') ? (int) $request->session()->get('userId') : null;
        $myMarks = array_fill_keys($uid ? $marks->typesFor($uid, 'destination', $id) : [], true);
        $bestMonths = collect($months)->where('level', 'best')->pluck('month')->all();
        $advice = $travelAdvice->forDestination($id);
        $adviceBest = TravelAdvice::ranges(array_keys(array_filter($advice, fn($a) => $a['level'] === 'best')));

        return view('clients.destination-detail', compact(
            'title',
            'destination',
            'data',
            'tours',
            'months',
            'foods',
            'events',
            'itineraries',
            'nearby',
            'myMarks',
            'bestMonths',
            'advice',
            'adviceBest'
        ));
    }
}
