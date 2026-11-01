<?php

namespace App\Models\clients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use App\Support\BookingPii;

class Tours extends Model
{
    use HasFactory;

    protected $table = 'tbl_tours';

    //Lấy chi tiết tour
    public function getTourDetail($id)
    {
        $getTourDetail = DB::table($this->table)
            ->where('tourId', $id)
            ->where('availability', 1)
            ->first();


        if ($getTourDetail) {

            // Lấy danh sách hình ảnh thuộc về tour
            $getTourDetail->images = DB::table('tbl_images')
                ->where('tourId', $getTourDetail->tourId)
                ->orderBy('sortOrder')
                ->limit(12)
                ->pluck('imageURL');

            $getTourDetail->timeline = DB::table('tbl_timeline')
                ->where('tourId', $getTourDetail->tourId)
                ->orderBy('timeLineId')   // đúng thứ tự ngày đã nhập 
                ->get();
        }

        return $getTourDetail;
    }

    //lấy khu vực bắc- trung - nam
    public function getDomain()
    {
        return DB::table($this->table)
            ->select('domain', DB::raw('count(*) as count'))
            ->whereIn('domain', ['b', 't', 'n'])
            ->where('availability', 1)
            ->where('quantity', '>', 0)
            ->whereDate('startDate', '>', now()->toDateString())
            ->groupBy('domain')
            ->get();
    }

    public function tourBooked($bookingId, $checkoutId, $userId)
    {
        $booked = DB::table($this->table)
            ->join('tbl_booking', 'tbl_tours.tourId', '=', 'tbl_booking.tourId')
            ->join('tbl_checkout', 'tbl_booking.bookingId', '=', 'tbl_checkout.bookingId')
            ->where('tbl_booking.bookingId', '=', $bookingId)
            ->where('tbl_checkout.checkoutId', '=', $checkoutId)
            ->where('tbl_booking.userId', '=', $userId)   // chỉ chủ đơn 
            ->first();

        return BookingPii::decrypt($booked);
    }

    //Tạo đánh giá về tours
    public function createReviews($data)
    {
        return DB::table('tbl_reviews')->insert($data);
    }

    //Lấy danh sách nội dung reviews 
    // Chỉ lấy cột cần hiện: trước đây JOIN tbl_users rồi lấy hết cột (cả mật khẩu băm, email) đưa vào view
    public function getReviews($id, int $page = 1, int $per = self::REVIEWS_PER_PAGE)
    {
        $q = DB::table('tbl_reviews')
            ->join('tbl_users', 'tbl_users.userId', '=', 'tbl_reviews.userId')
            ->where('tbl_reviews.tourId', $id)
            ->orderBy('tbl_reviews.timestamp', 'desc')->orderByDesc('tbl_reviews.reviewId')
            ->offset(max(0, $page - 1) * $per)->limit($per)
            ->select('tbl_reviews.reviewId', 'tbl_reviews.rating', 'tbl_reviews.comment', 'tbl_reviews.timestamp', 'tbl_users.fullName', 'tbl_users.avatar');

        return \App\Support\ReviewScope::visible($q)->get();
    }

    public const REVIEWS_PER_PAGE = 10;

    //Lấy số lượng đánh giá và số sao trung bình của tour
    public function reviewStats($id)
    {
        $reviewStats = \App\Support\ReviewScope::visible(DB::table('tbl_reviews')->where('tourId', $id))
            ->selectRaw('AVG(rating) as averageRating, COUNT(*) as reviewCount')
            ->first();

        return $reviewStats;
    }

    //Kiểm tra xem người dùng đã đánh giá tour này hay chưa?

    public function checkReviewExist($tourId, $userId)
    {
        return DB::table('tbl_reviews')
            ->where('tourId', $tourId)
            ->where('userId', $userId)
            ->exists(); // Trả về true nếu bản ghi tồn tại, false nếu không tồn tại
    }
    public function getPopularTours($limit = 2)
    {
        // Lấy tour có rating
        $ratedTours = DB::table($this->table)
            ->leftJoin('tbl_reviews', fn($j) => \App\Support\ReviewScope::join($j, 'tbl_tours.tourId'))
            ->select(
                'tbl_tours.tourId',
                'tbl_tours.title',
                'tbl_tours.destination',
                'tbl_tours.priceAdult',
                DB::raw('AVG(tbl_reviews.rating) as averageRating'),
                DB::raw('COUNT(tbl_reviews.reviewId) as reviewCount')
            )
            ->where('tbl_tours.availability', 1)
            ->where('tbl_tours.quantity', '>', 0)
            ->whereDate('tbl_tours.startDate', '>', now()->toDateString())
            ->groupBy(
                'tbl_tours.tourId',
                'tbl_tours.title',
                'tbl_tours.destination',
                'tbl_tours.priceAdult'
            )
            ->havingRaw('AVG(tbl_reviews.rating) IS NOT NULL')
            ->orderByDesc('averageRating')
            ->orderByDesc('reviewCount')
            ->limit($limit)
            ->get();

        // Nếu chưa đủ số lượng, lấy thêm tour random để bù
        $remaining = $limit - $ratedTours->count();

        if ($remaining > 0) {
            // Lấy ID các tour đã có để loại trừ
            $excludeIds = $ratedTours->pluck('tourId')->toArray();

            $randomTours = DB::table($this->table)
                ->select(
                    'tourId',
                    'title',
                    'destination',
                    'priceAdult',
                    DB::raw('NULL as averageRating'),
                    DB::raw('0 as reviewCount')
                )
                ->where('availability', 1)
                ->where('quantity', '>', 0)
                ->whereDate('startDate', '>', now()->toDateString())
                ->whereNotIn('tourId', $excludeIds)
                ->inRandomOrder()
                ->limit($remaining)
                ->get();

            // Gộp 2 collection lại
            $tours = $ratedTours->concat($randomTours);
        } else {
            $tours = $ratedTours;
        }

        // Lấy ảnh cho từng tour
        $tourIds = $tours->pluck('tourId')->all();

        if (empty($tourIds)) {
            return $tours;
        }

        $imagesByTour = DB::table('tbl_images')
            ->whereIn('tourId', $tourIds)
            ->orderBy('sortOrder')
            ->get(['tourId', 'imageURL'])
            ->groupBy('tourId');

        foreach ($tours as $tour) {
            $tour->images = collect($imagesByTour->get($tour->tourId, []))
                ->pluck('imageURL')
                ->values();

            $tour->thumbnail = $tour->images->first() ?? 'default.jpg';
        }
        return $tours;
    }
}
