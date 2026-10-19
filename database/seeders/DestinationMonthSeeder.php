<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Nạp tbl_destination_months từ config/postcards.php (dữ liệu tạm bạn đã duyệt).
 * Mỗi điểm đến có trong config -> đủ 12 dòng: tháng trong danh sách = best (kèm note), còn lại = ok.
 * source = manual. Chạy lại an toàn (cập nhật theo khóa chính). Điểm đến không có trong config: không tạo dòng.
 * Khi có lệnh luật khí hậu (A5), dòng source=rule sẽ bổ sung cho các điểm đến còn lại.
 */
class DestinationMonthSeeder extends Seeder
{
    public function run(): void
    {
        $ids = DB::table('tbl_destinations')->pluck('destinationId', 'slug');

        foreach (config('postcards.seasons', []) as $slug => $s) {
            if (!isset($ids[$slug])) {
                $this->command?->warn("Bỏ qua '{$slug}': chưa có trong tbl_destinations");
                continue;
            }
            $rows = [];
            for ($m = 1; $m <= 12; $m++) {
                $best = in_array($m, $s['m'], true);
                $rows[] = [
                    'destinationId' => $ids[$slug],
                    'month'  => $m,
                    'level'  => $best ? 'best' : 'ok',
                    'note'   => $best ? $s['w'] : null,
                    'source' => 'manual',
                ];
            }
            DB::table('tbl_destination_months')->upsert($rows, ['destinationId', 'month'], ['level', 'note', 'source']);
        }
    }
}
