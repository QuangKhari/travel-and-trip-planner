<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Models\clients\Home;
use App\Services\HeroPostcardService;
use App\Services\HomeFeedService;
use App\Services\InspirationService;

class HomeController extends Controller
{
    private $homeTours;

    public function __construct()
    {
        parent::__construct();
        $this->homeTours = new Home();
    }

    public function index(InspirationService $inspirationService, HeroPostcardService $heroPostcards, HomeFeedService $feedService)
    {
        $title = 'Trang chủ';
        $tours = $this->homeTours->getHomeTours();
        $inspirationMap = $inspirationService->mapData();

        $month = (int) now()->month;
        $postcards = $heroPostcards->forMonth($month);
        $feed = $feedService->forMonth($month);
        $plannerDestinations = $feedService->destinationOptions();

        return view('clients.home', compact('title', 'tours', 'inspirationMap', 'postcards', 'month', 'feed', 'plannerDestinations'));
    }
}
