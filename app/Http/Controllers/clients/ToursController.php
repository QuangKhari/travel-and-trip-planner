<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;
use Illuminate\Pagination\LengthAwarePaginator;

class ToursController extends Controller
{
    private $tours;
    public function __construct(){
        $this->tours = new Tours();
    }    



    public function index(Request $request)
    {
        $title = 'Tours';
        $tours = $this->tours->getAllTours();
        $domain = $this->tours->getDomain();
        $domainsCount = [
            'mien_bac' => optional($domain->firstWhere('domain', 'b'))->count,
            'mien_trung' => optional($domain->firstWhere('domain', 't'))->count,
            'mien_nam' => optional($domain->firstWhere('domain', 'n'))->count,
        ];

        $popularTours = $this->tours->getPopularTours(2);

        return view('clients.tours', compact('title', 'tours', 'domainsCount', 'popularTours'));
    }

    //filter tours
    public function filterTours(Request $req)
    {

        $conditions = [];
        $sorting = [];

        // Handle price filter
        if ($req->filled('minPrice') && $req->filled('maxPrice')) {
            $minPrice = $req->minPrice;
            $maxPrice = $req->maxPrice;
            $conditions[] = ['priceAdult', '>=', $minPrice];
            $conditions[] = ['priceAdult', '<=', $maxPrice];
        }

        // Handle domain filter
        if ($req->filled('domain')) {
            $domain = $req->domain;
            $conditions[] = ['domain', '=', $domain];
        }

        // Handle star rating filter
        if ($req->filled('star')) {
    $star = (int) $req->star;
    $conditions[] = ['averageRating', '>=', $star];
    $conditions[] = ['averageRating', '<', $star + 1];
}

        // Handle duration filter
        if ($req->filled('time')) {
            $duration = $req->time;
            $time = [
                '3n2d' => '3 ngày 2 đêm',
                '4n3d' => '4 ngày 3 đêm',
                '6n5d' => '6 ngày 5 đêm'
            ];
            $conditions[] = ['time', '=', $time[$duration]];
        }
        // Handle orderby filter
        if ($req->sorting && $req->sorting != 'default') {

    $sortingOption = trim($req->sorting);

    if ($sortingOption == 'new') {
        $sorting = ['tourId', 'DESC'];

    } elseif ($sortingOption == 'old') {
        $sorting = ['tourId', 'ASC'];

    } elseif ($sortingOption == 'hight-to-low') {
        $sorting = ['priceAdult', 'DESC'];

    } elseif ($sortingOption == 'low-to-high') {
        $sorting = ['priceAdult', 'ASC'];
    }
}

        

        //dd($req->all(), $sorting);
        $tours = $this->tours->filterTours($conditions, $sorting);
        //dd($tours);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 9;
        $currentItems = $tours->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $tours = new LengthAwarePaginator(
            $currentItems,
            $tours->count(),
            $perPage,
            $currentPage,
            ['path' => url()->current()]
        );

        return view('clients.partials.filter-tours', compact('tours'));

    }

    
}
