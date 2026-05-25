<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;
use App\Models\clients\Booking;
use App\Models\clients\Checkout;

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

        return view('clients.my-tours', compact('title', 'myTours'));
    }
}
