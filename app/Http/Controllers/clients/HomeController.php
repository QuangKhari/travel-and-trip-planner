<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Home;
use App\Services\InspirationService;
use App\Services\HeroPostcardService;

class HomeController extends Controller
{
    private $homeTours;

    public function __construct()
    {
        parent::__construct();
        $this->homeTours = new Home();
    }

    public function index(InspirationService $inspirationService, HeroPostcardService $heroPostcards)
    {
        $title = 'Trang chủ';
        $tours = $this->homeTours->getHomeTours();
        $inspirationMap = $inspirationService->mapData();

        $month = (int) now()->month;
        $postcards = $heroPostcards->forMonth($month);

        return view('clients.home', compact('title', 'tours', 'inspirationMap', 'postcards', 'month'));
    }
}
