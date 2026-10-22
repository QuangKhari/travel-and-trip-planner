<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TourSeeder::class,
            TourImageSeeder::class,
            TourTimelineSeeder::class,
            UserSeeder::class,
            // Lớp Khám phá (A11): điểm đến + cảm hứng, mùa đẹp mồi từ config, rồi nội dung đã xuất ra JSON.
            // Nội dung nạp vào ở trạng thái đã lưu trong tệp (thường là draft): duyệt ở Admin > Duyệt nội dung.
            DiscoverSeeder::class,
            DestinationMonthSeeder::class,
            ContentSeeder::class,
            ItineraryDraftSeeder::class,
        ]);
    }
}
