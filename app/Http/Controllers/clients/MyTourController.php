<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Models\clients\Tours;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class MyTourController extends Controller
{
    private $tours;

    private const PER_PAGE = 6;
    private const STATUSES = ['n', 'y', 'f', 'c'];

    public function __construct()
    {
        parent::__construct(); // Gọi constructor của Controller để khởi tạo $user
        $this->tours = new Tours();
    }

    public function index(Request $request)
    {
        $title = 'Tours đã đặt';
        $userId = $this->getUserId();

        $all = collect($this->user->getMyTours($userId));
        $toursPopular = $this->tours->getPopularTours(2);

        // Số đơn theo từng trạng thái (cho các nút lọc)
        $statusCounts = [];
        foreach (self::STATUSES as $s) {
            $statusCounts[$s] = $all->where('bookingStatus', $s)->count();
        }
        $totalAll = $all->count();

        // Lọc theo trạng thái (?status=n|y|f|c) rồi phân trang (?page=)
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : '';
        $filtered = $status === '' ? $all : $all->where('bookingStatus', $status);

        $page = max(1, (int) $request->query('page', 1));
        $myTours = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => route('my-tours'), 'query' => $status !== '' ? ['status' => $status] : []]
        );

        return view('clients.my-tours', compact('title', 'myTours', 'toursPopular', 'statusCounts', 'totalAll', 'status'));
    }
}
