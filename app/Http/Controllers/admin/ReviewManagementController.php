<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\admin\Review;

class ReviewManagementController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Quản lý đánh giá';

        $search = trim((string) $request->get('search', ''));
        $visibility = (string) $request->get('visibility', '');

        $query = Review::with(['user', 'tour'])
            ->orderBy('timestamp', 'desc');

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';   // "%" hay "_" trong ô tìm là ký tự thường
            $query->where(function ($q) use ($like) {
                $q->where('comment', 'like', $like)
                    ->orWhereHas('user', fn($user) => $user->where('fullName', 'like', $like))
                    ->orWhereHas('tour', fn($tour) => $tour->where('title', 'like', $like));
            });
        }
        if (\App\Support\ReviewScope::enabled() && in_array($visibility, ['shown', 'hidden'], true)) {
            $query->where('isHidden', $visibility === 'hidden' ? 1 : 0);
        }

        $reviews = $query->paginate(10)->withQueryString();

        $stats = [
            'total'      => Review::count(),
            // điểm khách thấy: chỉ tính đánh giá đang hiện
            'avg_rating' => round((float) (\App\Support\ReviewScope::enabled() ? Review::where('isHidden', 0)->avg('rating') : Review::avg('rating')), 1),
            'hidden'     => \App\Support\ReviewScope::enabled() ? Review::where('isHidden', 1)->count() : 0,
        ];

        return view(
            'admin.reviews.index',
            compact(
                'title',
                'reviews',
                'stats',
                'search',
                'visibility'
            )
        );
    }

    public function show($id)
    {
        $title = 'Chi tiết đánh giá';

        $review = Review::with(['user', 'tour'])
            ->findOrFail($id);

        return view(
            'admin.reviews.show',
            compact(
                'title',
                'review'
            )
        );
    }

    /** Ẩn / hiện một đánh giá (L-F-10). */
    public function toggle($id)
    {
        if (!\App\Support\ReviewScope::enabled()) {
            return back()->with('error', 'Hãy chạy php artisan migrate để dùng tính năng ẩn đánh giá.');
        }
        $review = Review::findOrFail($id);
        $hide = !$review->isHidden;
        \Illuminate\Support\Facades\DB::table('tbl_reviews')->where('reviewId', $review->reviewId)->update(['isHidden' => $hide]);

        return back()->with('success', $hide ? 'Đã ẩn đánh giá: khách không còn thấy, không tính vào điểm trung bình.' : 'Đã hiện lại đánh giá.');
    }

    public function destroy($id)
    {
        $review = Review::findOrFail($id);

        $review->delete();

        return redirect()
            ->route('admin.reviews')
            ->with('success', 'Đã xóa đánh giá thành công!');
    }
}
