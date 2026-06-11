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

        $search = $request->get('search', '');

        $query = Review::with(['user', 'tour'])
            ->orderBy('timestamp', 'desc');

        if (!empty($search)) {

            $query->where(function ($q) use ($search) {

                $q->where('comment', 'like', "%{$search}%")

                  ->orWhereHas('user', function ($user) use ($search) {
                      $user->where('fullName', 'like', "%{$search}%");
                  })

                  ->orWhereHas('tour', function ($tour) use ($search) {
                      $tour->where('tourName', 'like', "%{$search}%");
                  });

            });
        }

        $reviews = $query->paginate(10)->withQueryString();

        $stats = [
            'total'      => Review::count(),
            'avg_rating' => round(Review::avg('rating'), 1),
        ];

        return view(
            'admin.reviews.index',
            compact(
                'title',
                'reviews',
                'stats',
                'search'
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

    public function destroy($id)
    {
        $review = Review::findOrFail($id);

        $review->delete();

        return redirect()
            ->route('admin.reviews')
            ->with('success', 'Đã xóa đánh giá thành công!');
    }
}
