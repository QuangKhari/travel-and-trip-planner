<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mô tả 1–2 câu cho 120 địa điểm của gói demo (data/demo_place_text.json). Chỉ điền nơi CHƯA có mô tả, ghi nguồn
 * "Travela biên tập (cần kiểm tra)"; mô tả admin đã sửa được giữ nguyên. Chạy lại an toàn.
 *   php artisan db:seed --class=DemoPlaceTextSeeder
 * Ảnh không nằm trong gói (cần giấy phép): php artisan places:media-suggest rồi duyệt ở Quản trị → Dữ liệu địa điểm → Ảnh & mô tả.
 */
class DemoPlaceTextSeeder extends Seeder
{
    public function run(): void
    {
        $pack = json_decode((string) file_get_contents(__DIR__ . '/data/demo_place_text.json'), true);
        $hasSrc = Schema::hasColumn('tbl_external_places', 'descriptionSource');
        $n = 0;
        foreach ($pack['cities'] as $destId => $rows) {
            foreach ($rows as $name => $text) {
                $patch = ['description' => $text] + ($hasSrc ? ['descriptionSource' => $pack['source']] : []);
                $n += DB::table('tbl_external_places')->where('destinationId', (int) $destId)->where('name', $name)
                    ->where(fn($q) => $q->whereNull('description')->orWhere('description', ''))->update($patch);
            }
        }
        $this->command?->line("  điền mô tả cho $n nơi");
    }
}
