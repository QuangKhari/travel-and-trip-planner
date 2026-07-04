<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeleteSampleTourSeeder extends Seeder
{
    public function run(): void
    {
        // Lấy ID các tour mẫu trước
        $tourIds = DB::table('tbl_tours')
            ->where('title', 'like', '[MẪU]%')
            ->pluck('tourId');

        // Xóa dữ liệu liên quan trước
        DB::table('tbl_images')
            ->whereIn('tourId', $tourIds)
            ->delete();

        DB::table('tbl_timeline')
            ->whereIn('tourId', $tourIds)
            ->delete();

        DB::table('tbl_reviews')
            ->whereIn('tourId', $tourIds)
            ->delete();

        // Cuối cùng mới xóa tour
        DB::table('tbl_tours')
            ->whereIn('tourId', $tourIds)
            ->delete();
    }
}
