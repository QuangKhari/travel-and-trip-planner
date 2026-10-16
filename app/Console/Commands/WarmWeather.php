<?php

namespace App\Console\Commands;

use App\Services\Discover\WeatherService;
use Illuminate\Console\Command;

class WarmWeather extends Command
{
    protected $signature = 'discover:warm-weather {--slug= : Chỉ một điểm đến}';

    protected $description = 'Lấy thời tiết Open-Meteo cho các điểm đến và lưu cache vào DB (chạy theo lịch mỗi 3 giờ)';

    public function handle(WeatherService $weather): int
    {
        $r = $weather->refreshAll($this->option('slug') ?: null);
        $this->info("Thời tiết: cập nhật {$r['updated']}, lỗi {$r['failed']}.");

        return $r['updated'] > 0 || $r['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
