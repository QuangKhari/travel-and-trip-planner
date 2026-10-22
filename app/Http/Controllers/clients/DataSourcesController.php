<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** C5: /nguon-du-lieu — nguồn, giấy phép và số lượng nội dung đã duyệt. */
class DataSourcesController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $published = fn(string $t) => DB::table($t)->where('reviewStatus', 'published')->count();

        return view('clients.guide.sources', [
            'title' => 'Nguồn dữ liệu',
            'counts' => [
                'Điểm đến' => DB::table('tbl_destinations')->where('isActive', 1)->count(),
                'Địa điểm' => $published('tbl_external_places'),
                'Món ăn' => $published('tbl_foods'),
                'Lễ hội' => $published('tbl_events'),
                'Lịch trình' => $published('tbl_itineraries'),
                'Bài cẩm nang' => $published('tbl_guides'),
            ],
        ]);
    }
}
