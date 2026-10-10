<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Models\clients\Tours;
use App\Services\TourCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        $breakdown = $this->ratingBreakdown($id);

        $checkReviewExist = $this->tours->checkReviewExist($id, $userId);
        $checkDisplay = $checkReviewExist ? 'hide' : '';

        $related = $this->relatedTours($tourDetail);

        return view('clients.tour-detail', compact(
            'title',
            'tourDetail',
            'getReviews',
            'reviewStats',
            'breakdown',
            'checkDisplay',
            'related'
        ));
    }

    public function reviews(Request $req)
    {
        $validator = Validator::make($req->all(), [
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

        $completed = DB::table('tbl_booking')
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

        $alreadyReviewed = DB::table('tbl_reviews')
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
        $breakdown = $this->ratingBreakdown($tourId);

        return response()->json([
            'success' => true,
            'message' => 'Đánh giá của bạn đã được gửi thành công!',
            'data' => view('clients.partials.reviews', compact(
                'tourDetail',
                'getReviews',
                'reviewStats',
                'breakdown'
            ))->render(),
        ]);
    }

    /** Số lượt đánh giá theo từng mức sao: [5 => n, 4 => n, ... 1 => n]. */
    private function ratingBreakdown($tourId): array
    {
        $rows = DB::table('tbl_reviews')
            ->where('tourId', $tourId)
            ->selectRaw('ROUND(rating) as star, COUNT(*) as total')
            ->groupBy('star')
            ->pluck('total', 'star');

        $out = [];
        for ($s = 5; $s >= 1; $s--) {
            $out[$s] = (int) ($rows[$s] ?? 0);
        }

        return $out;
    }

    /** 3 tour gợi ý: ưu tiên cùng miền, thiếu thì lấy thêm tour mới nhất. */
    private function relatedTours($tourDetail)
    {
        $catalog = new TourCatalog();
        $exclude = (int) $tourDetail->tourId;

        $same = $catalog->paginate(['domain' => $tourDetail->domain, 'sort' => 'new'], 6)
            ->getCollection()
            ->reject(fn($t) => (int) $t->tourId === $exclude)
            ->values();

        if ($same->count() < 3) {
            $more = $catalog->paginate(['sort' => 'new'], 8)
                ->getCollection()
                ->reject(fn($t) => (int) $t->tourId === $exclude || $same->contains('tourId', $t->tourId))
                ->values();
            $same = $same->concat($more);
        }

        return $same->take(3)->values();
    }
}
