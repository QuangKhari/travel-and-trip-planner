<?php

namespace App\Models\clients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class Home extends Model
{
    use HasFactory;

    protected $table = 'tbl_tours';

    public function getHomeTours()
    {
        $tours = DB::table($this->table)
->where('availability', 1)
->inRandomOrder()
->limit(6)
->get();

foreach ($tours as $tour) {
    // Lấy danh sách ảnh của tour
    $tour->images = DB::table('tbl_images')
        ->where('tourId', $tour->tourId)
        ->pluck('imageURL');

    // Lấy tiêu đề lịch trình của tour
    $tour->timelines = DB::table('tbl_timeline')
        ->where('tourId', $tour->tourId)
        ->pluck('title');
}

return $tours;
    }


}
