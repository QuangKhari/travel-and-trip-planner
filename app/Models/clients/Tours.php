<?php

namespace App\Models\clients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Tours extends Model
{
    use HasFactory;

    protected $table = 'tbl_tours';

    // Lấy tất cả các tour
    public function getAllTours()
    {
        $allTours = DB::table($this->table)->get();
        foreach ($allTours as $tour) {
            // Lấy danh sách hình ảnh thuộc về tour
            $tour->images = DB::table('tbl_images')
                ->where('tourId', $tour->tourId)
                ->pluck('imageURL');
        }
        return $allTours;
    }

    //Lấy chi tiết tour
    public function getTourDetail($id)
    {
        $getTourDetail = DB::table($this->table)
        ->where('tourId', $id)
        ->first();


        if ($getTourDetail) {
        
            // Lấy danh sách hình ảnh thuộc về tour
            $getTourDetail->images = DB::table('tbl_images')
                ->where('tourId', $getTourDetail->tourId)
                ->limit(5)
                ->pluck('imageURL');

            $getTourDetail->timeline = DB::table('tbl_timeline')
                ->where('tourId', $getTourDetail->tourId)
                ->get();
            }

        return $getTourDetail;
        
    }

    //lấy khu vực bắc- trung - nam
    public function getDomain(){
        return DB::table($this->table)
        ->select('domain', DB::raw('count(*) as count'))
        ->whereIn('domain', ['b', 't', 'n'])
        ->groupBy('domain')
        ->get();
    }

    //Filter tours
    public function filterTours($filters = [], $sorting = null, $perPage = null)
    {
        DB::enableQueryLog();

        // Khởi tạo truy vấn với bảng tours
        $getTours = DB::table($this->table)
            ->leftJoin('tbl_reviews', 'tbl_tours.tourId', '=', 'tbl_reviews.tourId') // Join với bảng reviews
            ->select(
                'tbl_tours.tourId',
                'tbl_tours.title',
                'tbl_tours.description',
                'tbl_tours.priceAdult',
                'tbl_tours.priceChild',
                'tbl_tours.time',
                'tbl_tours.destination',
                'tbl_tours.quantity',
                DB::raw('AVG(tbl_reviews.rating) as averageRating')
            )
            ->groupBy(
                'tbl_tours.tourId',
                'tbl_tours.title',
                'tbl_tours.description',
                'tbl_tours.priceAdult',
                'tbl_tours.priceChild',
                'tbl_tours.time',
                'tbl_tours.destination',
                'tbl_tours.quantity'
            );
        $getTours = $getTours->where('availability', 1);

        if (!empty($filters)) {
            foreach ($filters as $filter) {
                if ($filter[0] !== 'averageRating') {
                    $getTours = $getTours->where($filter[0], $filter[1], $filter[2]);
                }
            }
        }

        // Áp dụng điều kiện về averageRating trong phần HAVING
        $ratingFilters = array_filter($filters, fn($f) => $f[0] === 'averageRating');

if (!empty($ratingFilters)) {
    foreach ($ratingFilters as $filter) {
        $getTours = $getTours->having(
            DB::raw('AVG(tbl_reviews.rating)'),
            $filter[1],
            $filter[2]
        );
    }
}

        if (!empty($sorting) && isset($sorting[0]) && isset($sorting[1])) {
            $getTours = $getTours->orderBy($sorting[0], $sorting[1]);
        }

        // Thực hiện truy vấn để ghi log
        $tours = $getTours->get();

        // In ra câu lệnh SQL đã ghi lại (nếu cần thiết)
        $queryLog = DB::getQueryLog();

        // Lấy danh sách hình ảnh cho mỗi tour
        foreach ($tours as $tour) {
            $tour->images = DB::table('tbl_images')
                ->where('tourId', $tour->tourId)
                ->pluck('imageURL');
            $tour->rating = $this->reviewStats($tour->tourId)->averageRating;
        }

        // dd($queryLog); // In ra log truy vấn 
        return $tours;
    }
    public function updateTours($tourId, $data)
    {
        $update = DB::table($this->table)
            ->where('tourId', $tourId)
            ->update($data);

        return $update;
    }
    public function tourBooked($bookingId, $checkoutId)
    {
        $booked = DB::table($this->table)
            ->join('tbl_booking', 'tbl_tours.tourId', '=', 'tbl_booking.tourId')
            ->join('tbl_checkout', 'tbl_booking.bookingId', '=', 'tbl_checkout.bookingId')
            ->where('tbl_booking.bookingId', '=', $bookingId)
            ->where('tbl_checkout.checkoutId', '=', $checkoutId)
            ->first();

        return $booked;
    }
    //Tạo đánh giá về tours
    public function createReviews($data)
    {
        return DB::table('tbl_reviews')->insert($data);
    }

    //Lấy danh sách nội dung reviews 
    public function getReviews($id)
    {
        $getReviews = DB::table('tbl_reviews')
            ->join('tbl_users', 'tbl_users.userId', '=', 'tbl_reviews.userId')
            ->where('tourId', $id)
            ->orderBy('tbl_reviews.timestamp', 'desc')
            ->take(3)
            ->get();

        return $getReviews;
    }

    //Lấy số lượng đánh giá và số sao trung bình của tour
    public function reviewStats($id)
    {
        $reviewStats = DB::table('tbl_reviews')
            ->where('tourId', $id)
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
    public function searchTours($data)
    {
        $tours = DB::table($this->table);


        // Thêm điều kiện cho destination với LIKE
        if (!empty($data['destination'])) {
            $tours->where('destination', 'LIKE', '%' . $data['destination'] . '%');
        }

        // Thêm điều kiện cho startDate và endDate nếu cần so sánh
        if (!empty($data['startDate'])) {
            $tours->whereDate('startDate', '>=', $data['startDate']);
        }
        if (!empty($data['endDate'])) {
            $tours->whereDate('endDate', '<=', $data['endDate']);
        }

        // Thêm điều kiện tìm kiếm với LIKE cho title, time và description
        if (!empty($data['keyword'])) {
            $tours->where(function ($query) use ($data) {
                $query->where('title', 'LIKE', '%' . $data['keyword'] . '%')
                    ->orWhere('description', 'LIKE', '%' . $data['keyword'] . '%')
                    ->orWhere('time', 'LIKE', '%' . $data['keyword'] . '%')
                    ->orWhere('destination', 'LIKE', '%' . $data['keyword'] . '%');
            });
        }

        $tours = $tours->where('availability', 1);
        $tours = $tours->limit(12)->get();

        foreach ($tours as $tour) {
            // Lấy danh sách hình ảnh thuộc về tour
            $tour->images = DB::table('tbl_images')
                ->where('tourId', $tour->tourId)
                ->pluck('imageURL');
            // Lấy số lượng đánh giá và số sao trung bình của tour
            $tour->rating = $this->reviewStats($tour->tourId)->averageRating;
        }
        return $tours;
    }
    public function getPopularTours($limit = 2)
{
    // Lấy tour có rating
    $ratedTours = DB::table($this->table)
        ->leftJoin('tbl_reviews', 'tbl_tours.tourId', '=', 'tbl_reviews.tourId')
        ->select(
            'tbl_tours.tourId',
            'tbl_tours.title',
            'tbl_tours.destination',
            'tbl_tours.priceAdult',
            DB::raw('AVG(tbl_reviews.rating) as averageRating'),
            DB::raw('COUNT(tbl_reviews.reviewId) as reviewCount')
        )
        ->where('tbl_tours.availability', 1)
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
    foreach ($tours as $tour) {
    $tour->images = DB::table('tbl_images')
        ->where('tourId', $tour->tourId)
        ->pluck('imageURL');
    // Ảnh đầu tiên để hiển thị
    $tour->thumbnail = $tour->images->first() ?? 'default.jpg';
    }
    return $tours;
}
}