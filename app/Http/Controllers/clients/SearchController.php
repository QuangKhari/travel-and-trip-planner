<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;


class SearchController extends Controller
{
    private $tours;

    public function __construct()
    {
        $this->tours = new Tours();
    }
    public function index(Request $request)
    {
        $title = 'Tìm kiếm';

        $destinationMap = [
            'dn' => 'Đà Nẵng',
            'cd' => 'Côn Đảo',
            'hn' => 'Hà Nội',
            'hcm' => 'TP. Hồ Chí Minh',
            'hl' => 'Hạ Long',
            'nb' => 'Ninh Bình',
            'pq' => 'Phú Quốc',
            'dl' => 'Đà Lạt',
            'qt' => 'Quảng Trị',
            'kh' => 'Khánh Hòa',
            'ct' => 'Cần Thơ',
            'vt' => 'Vũng Tàu',
            'qn' => 'Quảng Ninh',
            'la' => 'Lào Cai',
            'bd' => 'Bình Định',
        ];

        $destination = $request->input('destination');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Chuyển đổi định dạng ngày tháng
        $formattedStartDate = $startDate ? Carbon::createFromFormat('d/m/Y', $startDate)->format('Y-m-d') : null;
        $formattedEndDate = $endDate ? Carbon::createFromFormat('d/m/Y', $endDate)->format('Y-m-d') : null;

        // Chuyển đổi giá trị sang tên chi tiết nếu có trong mảng
        $destinationName = $destinationMap[$destination];

        $dataSearch = [
            'destination' => $destinationName,
            'startDate' => $formattedStartDate,
            'endDate' => $formattedEndDate,
        ];

        $tours = $this->tours->searchTours($dataSearch);

        // dd($tours);

        return view('clients.search', compact('title', 'tours'));
    }

    public function searchTours(Request $request)
{
$title = 'Kết quả tìm kiếm';

$keyword = trim($request->input('keyword'));

if (empty($keyword)) {
    return redirect()->route('home')
        ->with('error', 'Vui lòng nhập từ khóa tìm kiếm.');
}

$dataSearch = [
    'keyword' => $keyword
];

$tours = $this->tours->searchTours($dataSearch);

return view('clients.search', compact('title', 'tours', 'keyword'));

}

}
