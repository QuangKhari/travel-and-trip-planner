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
        ]);
    }
}
