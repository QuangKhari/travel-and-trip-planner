<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;

class ToursController extends Controller
{
    private $tours;

    public function __construct()
    {
        parent::__construct();
        $this->tours = new Tours();
    }



    public function index(Request $request)
    {
        $title = 'Tours';
        $domain = $this->tours->getDomain();
        $domainsCount = [
            'mien_bac' => optional($domain->firstWhere('domain', 'b'))->count,
            'mien_trung' => optional($domain->firstWhere('domain', 't'))->count,
            'mien_nam' => optional($domain->firstWhere('domain', 'n'))->count,
        ];

        $popularTours = $this->tours->getPopularTours(2);
        $perPage = 9;
        $tours = $this->tours->filterTours([], [], $perPage);
        $tours->withPath(route('filter-tours'))->appends($request->except('page'));

        if ($request->ajax()) {
            return view('clients.partials.filter-tours', compact('tours'));
        }

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

        if ($req->filled('time')) {
            // Giá trị dạng 3n2d, 4n3d...: lọc theo số ngày ở đầu chuỗi "N ngày M đêm"
            if (preg_match('/^(\d{1,2})n\d{1,2}d$/', (string) $req->input('time'), $m)) {
                $conditions[] = ['time', 'like', ((int) $m[1]) . ' ngày %'];
            }
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
        $perPage = 9;
        $tours = $this->tours->filterTours($conditions, $sorting, $perPage);
        $tours->withPath(route('filter-tours'))->appends($req->except('page'));


        return view('clients.partials.filter-tours', compact('tours'));
    }
}
