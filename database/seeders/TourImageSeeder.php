<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TourImageSeeder extends Seeder
{
    public function run(): void
    {
        // Lấy 5 tour mới nhất
        $tours = DB::table('tbl_tours')
            ->orderBy('tourId', 'desc')
            ->take(5)
            ->get();

        $images = [
            'con-dao1.jpg',
            'con-dao2.jpg',
            'con-dao3.jpg',
            'con-dao4.jpg',
            'con-dao5.png',
        ];

        foreach ($tours as $tour) {
            // Xóa ảnh cũ để không bị trùng khi chạy seed lại
            DB::table('tbl_images')
                ->where('tourId', $tour->tourId)
                ->delete();

            // Mỗi tour có đủ 5 ảnh
            foreach ($images as $index => $image) {
                DB::table('tbl_images')->insert([
                    'tourId' => $tour->tourId,
                    'imageURL' => $image,
                    'description' => 'Ảnh ' . ($index + 1) . ' của tour ' . $tour->title,
                ]);
            }
        }
    }
}
