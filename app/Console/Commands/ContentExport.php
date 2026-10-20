<?php

namespace App\Console\Commands;

use App\Services\Content\ContentExporter;
use Illuminate\Console\Command;

class ContentExport extends Command
{
    protected $signature = 'content:export
        {--status=published : published | all (all = cả nháp, để chuyển dữ liệu đang làm dở)}
        {--out= : Tệp đích (mặc định database/seeders/data/content_export.json)}';

    protected $description = 'A11: xuất nội dung Khám phá ra JSON (tham chiếu bằng slug) để máy khác nạp bằng ContentSeeder';

    public function handle(ContentExporter $exporter): int
    {
        $status = $this->option('status');
        if (!in_array($status, ['published', 'all'], true)) {
            $this->error('--status chỉ nhận published hoặc all');

            return self::FAILURE;
        }
        $out = $this->option('out') ?: database_path('seeders/data/content_export.json');
        $data = $exporter->build($status);
        file_put_contents($out, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->info("Đã ghi {$out}");
        $this->table(['Mục', 'Số dòng'], [
            ['điểm đến có tháng đẹp', count($data['months'])],
            ['điểm đến có khí hậu', count($data['climate'])],
            ['món ăn', count($data['foods'])],
            ['lễ hội', count($data['events'])],
            ['bài cẩm nang', count($data['guides'])],
            ['lịch trình', count($data['itineraries'])],
            ['địa điểm', count($data['places'])],
        ]);
        $this->warn('Ảnh không nằm trong JSON: chép thư mục discover/ của MEDIA_PUBLIC_ROOT (vd D:\travel-data\public\discover) sang máy đích.');
        $this->line('Máy đích: php artisan migrate && php artisan db:seed --class=ContentSeeder (điểm đến phải có sẵn).');

        return self::SUCCESS;
    }
}
