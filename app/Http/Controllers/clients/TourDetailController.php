<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\clients\Tours;

class TourDetailController extends Controller
{
    private $tours;

    public function __construct()
    {
        parent::__construct(); // Gọi constructor của Controller để khởi tạo $user
        $this->tours = new Tours();
    }

    public function index($id = 0)
    {
        $title = 'Chi tiết tour';
        $userId = $this->getUserId();

        $tourDetail = $this->tours->getTourDetail($id);

        // Tour không tồn tại -> trả về HTTP 404
        if (!$tourDetail) {
            abort(404);
        }

        $getReviews = $this->tours->getReviews($id);
        $reviewStats = $this->tours->reviewStats($id);

        $avgStar = round($reviewStats->averageRating);
        $countReview = $reviewStats->reviewCount;

        $checkReviewExist = $this->tours->checkReviewExist($id, $userId);
        if (!$checkReviewExist) {
            $checkDisplay = '';
        } else {
            $checkDisplay = 'hide';
        }
        //dd($tourDetail->timeline);
        return view('clients.tour-detail', compact('title', 'tourDetail', 'getReviews', 'avgStar', 'countReview', 'checkDisplay'));
    }

    public function reviews(Request $req)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($req->all(), [
            'tourId' => ['required', 'integer', 'exists:tbl_tours,tourId'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = (int) $this->getUserId();
        $tourId = (int) $req->tourId;

        $completed = \Illuminate\Support\Facades\DB::table('tbl_booking')
            ->where('userId', $userId)
            ->where('tourId', $tourId)
            ->where('bookingStatus', 'f')
            ->exists();

        if (!$completed) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chỉ có thể đánh giá tour đã hoàn tất.',
            ], 403);
        }

        $alreadyReviewed = \Illuminate\Support\Facades\DB::table('tbl_reviews')
            ->where('userId', $userId)
            ->where('tourId', $tourId)
            ->exists();

        if ($alreadyReviewed) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn đã đánh giá tour này rồi.',
            ], 409);
        }

        try {
            $this->tours->createReviews([
                'tourId' => $tourId,
                'userId' => $userId,
                'comment' => trim((string) $req->message),
                'rating' => (int) $req->rating,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Hai request cùng lúc: UNIQUE(userId, tourId) chặn bản thứ hai 
            return response()->json([
                'success' => false,
                'message' => 'Bạn đã đánh giá tour này rồi.',
            ], 409);
        }

        $tourDetail = $this->tours->getTourDetail($tourId);
        $getReviews = $this->tours->getReviews($tourId);
        $reviewStats = $this->tours->reviewStats($tourId);

        return response()->json([
            'success' => true,
            'message' => 'Đánh giá của bạn đã được gửi thành công!',
            'data' => view('clients.partials.reviews', compact(
                'tourDetail',
                'getReviews',
                'reviewStats'
            ))->render(),
        ]);
    }
}
