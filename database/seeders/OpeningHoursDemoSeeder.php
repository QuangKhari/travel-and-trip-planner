<?php

namespace Database\Seeders;

use App\Services\Planner\OpeningHours;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Giờ mở cửa mẫu cho vài địa điểm Hà Nội (dữ liệu demo đồ án; giờ tham khảo, cần kiểm tra lại trước khi dùng thật).
 *   php artisan db:seed --class=OpeningHoursDemoSeeder
 */
class OpeningHoursDemoSeeder extends Seeder
{
    private const HOURS = [
        'Văn Miếu - Quốc Tử Giám' => 'Mo-Su 08:00-17:00',
        'Hoàng thành Thăng Long' => 'Tu-Su 08:00-17:00; Mo off',
        'Bảo tàng Dân tộc học' => 'Tu-Su 08:30-17:30; Mo off',
        'Nhà hát Lớn Hà Nội' => 'Mo-Su 09:00-17:00',
        'Chả cá Thăng Long' => 'Mo-Su 10:00-14:00,17:00-21:00',
        'The Note Coffee' => 'Mo-Su 07:00-23:00',
        'Chùa Trấn Quốc' => 'Mo-Su 08:00-16:00',
    ];

    public function run(): void
    {
        $svc = app(OpeningHours::class);
        foreach (self::HOURS as $name => $h) {
            // tìm theo TÊN (mã placeId khác nhau giữa các CSDL), báo rõ đã nạp cho mục nào
            $rows = DB::table('tbl_external_places')->where('name', $name)->get(['placeId', 'name']);
            if ($rows->isEmpty()) {
                $this->command?->warn("  bỏ qua: không có địa điểm tên “{$name}”");
                continue;
            }
            foreach ($rows as $r) {
                $svc->store('place', (int) $r->placeId, $h, 'manual');
                $this->command?->line("  #{$r->placeId} {$r->name}: {$h}");
            }
        }
    }
}
