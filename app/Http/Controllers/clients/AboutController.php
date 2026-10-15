<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Trang giới thiệu. Mọi con số và lời đánh giá hiển thị đều lấy từ DB (không dùng số quảng cáo).
 * Lỗi truy vấn không làm hỏng trang: số liệu rơi về 0 và ô đó tự ẩn.
 */
class AboutController extends Controller
{
    public function index()
    {
        $title = 'Giới thiệu';

        $stats = ['tours' => 0, 'destinations' => 0, 'completed' => 0, 'reviews' => 0, 'rating' => 0.0];
        $review = null;

        try {
            $stats['tours'] = (int) DB::table('tbl_tours')->where('availability', 1)->count();
            $stats['destinations'] = (int) DB::table('tbl_tours')->where('availability', 1)->distinct()->count('destination');
            $stats['completed'] = (int) DB::table('tbl_booking')->where('bookingStatus', 'f')->count();
            $stats['reviews'] = (int) DB::table('tbl_reviews')->count();
            $stats['rating'] = round((float) DB::table('tbl_reviews')->avg('rating'), 1);

            // Một đánh giá thật để làm lời trích: điểm cao nhất, có nội dung, mới nhất
            $review = DB::table('tbl_reviews as r')
                ->join('tbl_users as u', 'u.userId', '=', 'r.userId')
                ->whereNotNull('r.comment')
                ->whereRaw('CHAR_LENGTH(r.comment) >= 20')
                ->orderByDesc('r.rating')
                ->orderByDesc('r.timestamp')
                ->select('r.comment', 'r.rating', 'u.fullName', 'u.avatar')
                ->first();
        } catch (\Throwable $e) {
            report($e);
        }

        return view('clients.about', compact('title', 'stats', 'review'));
    }
}
