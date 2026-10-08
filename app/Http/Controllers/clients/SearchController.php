<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;
use Carbon\Carbon;

class SearchController extends Controller
{
    private $tours;

    public function __construct()
    {
        parent::__construct();
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

        /*
         * Kiểm tra destination
         */
        if (!empty($destination) && !isset($destinationMap[$destination])) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Điểm đến không hợp lệ.');
        }

        /*
         * Chuyển đổi định dạng ngày tháng
         */
        try {
            $formattedStartDate = $startDate
                ? Carbon::createFromFormat('d/m/Y', $startDate)->format('Y-m-d')
                : null;

            $formattedEndDate = $endDate
                ? Carbon::createFromFormat('d/m/Y', $endDate)->format('Y-m-d')
                : null;
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Ngày tìm kiếm không hợp lệ. Vui lòng nhập ngày theo định dạng dd/mm/yyyy.');
        }

        /*
         * Chuyển đổi mã destination sang tên chi tiết
         */
        $destinationName = !empty($destination)
            ? $destinationMap[$destination]
            : null;

        $dataSearch = [
            'destination' => $destinationName,
            'startDate' => $formattedStartDate,
            'endDate' => $formattedEndDate,
        ];

        $tours = $this->tours->searchTours($dataSearch);

        return view('clients.search', compact('title', 'tours'));
    }

    public function searchTours(Request $request)
    {
        $title = 'Kết quả tìm kiếm';

        $keyword = trim((string) $request->input('keyword'));

        if (empty($keyword)) {
            return redirect()->route('home')
                ->with('error', 'Vui lòng nhập từ khóa tìm kiếm.');
        }

        if (mb_strlen($keyword) > 100) {
            return redirect()->route('home')
                ->with('error', 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.');
        }

        $dataSearch = [
            'keyword' => $keyword
        ];

        $tours = $this->tours->searchTours($dataSearch);

        return view('clients.search', compact('title', 'tours', 'keyword'));
    }
}
