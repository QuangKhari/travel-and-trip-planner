<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TourSeeder extends Seeder
{
    public function run(): void
    {
        $tours = [
            [
                'title' => 'Hành trình Đông Bắc 4N3Đ | Hà Nội - Hà Giang - Đồng Văn - Nho Quế',
                'time' => '4 ngày 3 đêm',
                'description' => 'Khám phá vẻ đẹp núi rừng Đông Bắc, đèo Mã Pí Lèng và sông Nho Quế.',
                'quantity' => 30,
                'priceAdult' => 5200000,
                'priceChild' => 3200000,
                'destination' => 'Hà Nội - Hà Giang - Đồng Văn - Nho Quế',
                'domain' => 'b',
                'availability' => 1,
                'startDate' => Carbon::now()->addDays(10)->format('Y-m-d'),
                'endDate' => Carbon::now()->addDays(13)->format('Y-m-d'),
            ],
            [
                'title' => 'Miền Bắc 4N3Đ | Hà Nội - Lào Cai - Sapa',
                'time' => '4 ngày 3 đêm',
                'description' => 'Trải nghiệm Sapa, núi Hàm Rồng, bản Cát Cát và không khí vùng cao.',
                'quantity' => 35,
                'priceAdult' => 6790000,
                'priceChild' => 4200000,
                'destination' => 'Hà Nội - Lào Cai - Sapa',
                'domain' => 'b',
                'availability' => 1,
                'startDate' => Carbon::now()->addDays(15)->format('Y-m-d'),
                'endDate' => Carbon::now()->addDays(18)->format('Y-m-d'),
            ],
            [
                'title' => 'Đà Nẵng - Hội An - Bà Nà Hills 3N2Đ',
                'time' => '3 ngày 2 đêm',
                'description' => 'Tham quan thành phố Đà Nẵng, phố cổ Hội An và khu du lịch Bà Nà Hills.',
                'quantity' => 40,
                'priceAdult' => 6090000,
                'priceChild' => 3800000,
                'destination' => 'Đà Nẵng - Hội An - Bà Nà Hills',
                'domain' => 't',
                'availability' => 1,
                'startDate' => Carbon::now()->addDays(20)->format('Y-m-d'),
                'endDate' => Carbon::now()->addDays(22)->format('Y-m-d'),
            ],
            [
                'title' => 'Miền Trung 4N3Đ | Đà Nẵng - Hội An - Bà Nà - Huế - Phong Nha',
                'time' => '4 ngày 3 đêm',
                'description' => 'Khám phá những địa điểm nổi bật nhất tại miền Trung Việt Nam.',
                'quantity' => 30,
                'priceAdult' => 9000000,
                'priceChild' => 5500000,
                'destination' => 'Đà Nẵng - Hội An - Bà Nà - Huế - Phong Nha',
                'domain' => 't',
                'availability' => 1,
                'startDate' => Carbon::now()->addDays(25)->format('Y-m-d'),
                'endDate' => Carbon::now()->addDays(28)->format('Y-m-d'),
            ],
            [
                'title' => 'TP.HCM - Đà Lạt 3N2Đ',
                'time' => '3 ngày 2 đêm',
                'description' => 'Nghỉ dưỡng tại thành phố ngàn hoa, tham quan các địa điểm nổi tiếng ở Đà Lạt.',
                'quantity' => 25,
                'priceAdult' => 4500000,
                'priceChild' => 2800000,
                'destination' => 'TP.HCM - Đà Lạt',
                'domain' => 'n',
                'availability' => 1,
                'startDate' => Carbon::now()->addDays(30)->format('Y-m-d'),
                'endDate' => Carbon::now()->addDays(32)->format('Y-m-d'),
            ],
        ];

        foreach ($tours as $tour) {
            DB::table('tbl_tours')->insert($tour);
        }
    }
}
