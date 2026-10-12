<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Trang giới thiệu. Mọi con số hiển thị đều đếm từ DB (không dùng số quảng cáo).
 * Lỗi truy vấn không làm hỏng trang: số liệu rơi về 0 và ô đó tự ẩn.
 */
class AboutController extends Controller
{
    public function index()
    {
        $title = 'Giới thiệu';

        $stats = ['tours' => 0, 'destinations' => 0, 'completed' => 0, 'reviews' => 0, 'rating' => 0.0];
        $destinations = collect();

        try {
            $stats['tours'] = (int) DB::table('tbl_tours')->where('availability', 1)->count();
            $stats['destinations'] = (int) DB::table('tbl_tours')->where('availability', 1)->distinct()->count('destination');
            $stats['completed'] = (int) DB::table('tbl_booking')->where('bookingStatus', 'f')->count();
            $stats['reviews'] = (int) DB::table('tbl_reviews')->count();
            $stats['rating'] = round((float) DB::table('tbl_reviews')->avg('rating'), 1);

            // 4 điểm đến có nhiều tour nhất, kèm ảnh của một tour thuộc điểm đến đó
            $destinations = DB::table('tbl_tours')
                ->where('availability', 1)
                ->select('destination', DB::raw('COUNT(*) as total'), DB::raw('MIN(tourId) as tourId'))
                ->groupBy('destination')
                ->orderByDesc('total')
                ->limit(4)
                ->get()
                ->map(function ($d) {
                    $d->image = DB::table('tbl_images')
                        ->where('tourId', $d->tourId)
                        ->orderBy('sortOrder')
                        ->value('imageURL');

                    return $d;
                });
        } catch (\Throwable $e) {
            report($e);
        }

        return view('clients.about', compact('title', 'stats', 'destinations'));
    }
}
