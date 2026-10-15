<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\InspirationService;

/**
 * Trang "Điểm đến": lưới điểm đến + bản đồ cảm xúc.
 * Dữ liệu từ tbl_inspirations / tbl_destinations / tbl_destination_inspiration (qua InspirationService::mapData()),
 * giống bản đồ ở trang chủ. Lọc và tìm kiếm chạy phía trình duyệt nên không tải lại trang.
 */
class DestinationController extends Controller
{
    public function index(InspirationService $service)
    {
        $title = 'Điểm đến';

        $map = ['exploreUrl' => route('explore'), 'inspirations' => [], 'destinations' => []];
        try {
            $map = $service->mapData();
        } catch (\Throwable $e) {
            report($e); // chưa chạy migration/seeder: trang hiện trạng thái trống thay vì báo lỗi
        }

        // 4 bài mới nhất từ config/blog.php
        $posts = collect(config('blog.posts', []))->sortByDesc('date')->take(4)->values();

        return view('clients.destination', compact('title', 'map', 'posts'));
    }
}
