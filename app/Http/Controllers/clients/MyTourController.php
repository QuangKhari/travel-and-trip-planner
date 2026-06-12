<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Models\clients\Tours;

class MyTourController extends Controller
{
    private $tours;

    public function __construct()
    {
        parent::__construct(); // Gọi constructor của Controller để khởi tạo $user
        $this->tours = new Tours();
    }

    public function index()
    {
        $title = 'Tours đã đặt';
        $userId = $this->getUserId();
        
        $myTours = $this->user->getMyTours($userId);
        $userId = $this->getUserId();
        $toursPopular = $this->tours->getPopularTours(2);

        return view('clients.my-tours', compact('title', 'myTours', 'toursPopular'));
    }
}
