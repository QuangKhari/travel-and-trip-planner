<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\DestinationService;

class DestinationDetailController extends Controller
{
    /** GET /destination/{slug} */
    public function show(string $slug, DestinationService $service)
    {
        $destination = $service->find($slug);
        if (!$destination) {
            abort(404);
        }

        $title = $destination->name;
        $data = $service->payload($destination);
        $tours = $service->tours($destination);

        return view('clients.destination-detail', compact('title', 'destination', 'data', 'tours'));
    }
}
