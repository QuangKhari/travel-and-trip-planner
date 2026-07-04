<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TourTimelineSeeder extends Seeder
{
    public function run(): void
    {
        $tours = DB::table('tbl_tours')
            ->orderBy('tourId', 'desc')
            ->take(5)
            ->get();

        foreach ($tours as $tour) {
            DB::table('tbl_timeline')->insert([
                [
                    'tourId' => $tour->tourId,
                    'title' => 'Ngày 1: Khởi hành',
                    'description' => 'Khởi hành đến điểm du lịch, nhận phòng khách sạn và nghỉ ngơi.',
                ],
                [
                    'tourId' => $tour->tourId,
                    'title' => 'Ngày 2: Tham quan',
                    'description' => 'Tham quan các địa điểm nổi bật, trải nghiệm văn hóa và ẩm thực địa phương.',
                ],
                [
                    'tourId' => $tour->tourId,
                    'title' => 'Ngày 3: Kết thúc hành trình',
                    'description' => 'Tự do mua sắm, trả phòng và trở về.',
                ],
            ]);
        }
    }
}
