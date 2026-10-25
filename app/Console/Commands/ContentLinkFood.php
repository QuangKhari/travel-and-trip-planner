<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gắn một món ăn vào nhiều điểm đến (bảng tbl_food_destination).
 *   php artisan content:link-food com-lam hue da-nang
 *   php artisan content:link-food com-lam da-nang --remove
 * Điểm đến "chính" của món vẫn là tbl_foods.destinationId (sửa ở admin); lệnh này chỉ thêm/bớt các nơi khác có món đó.
 */
class ContentLinkFood extends Command
{
    protected $signature = 'content:link-food {food : slug món ăn} {destinations* : slug các điểm đến} {--remove : gỡ liên kết thay vì thêm}';
    protected $description = 'Gắn (hoặc gỡ) một món ăn với nhiều điểm đến';

    public function handle(): int
    {
        $food = DB::table('tbl_foods')->where('slug', $this->argument('food'))->first();
        if (!$food) {
            $this->error('Không thấy món "' . $this->argument('food') . '".');

            return self::FAILURE;
        }
        $n = 0;
        foreach ($this->argument('destinations') as $slug) {
            $dest = DB::table('tbl_destinations')->where('slug', $slug)->first();
            if (!$dest) {
                $this->warn('Bỏ qua: không thấy điểm đến "' . $slug . '".');
                continue;
            }
            if ($this->option('remove')) {
                $n += DB::table('tbl_food_destination')->where(['foodId' => $food->foodId, 'destinationId' => $dest->destinationId])->delete();
            } else {
                $n += DB::table('tbl_food_destination')->insertOrIgnore(['foodId' => $food->foodId, 'destinationId' => $dest->destinationId]);
            }
        }
        $this->info(($this->option('remove') ? 'Đã gỡ ' : 'Đã thêm ') . $n . ' liên kết cho "' . $food->name . '".');

        return self::SUCCESS;
    }
}
