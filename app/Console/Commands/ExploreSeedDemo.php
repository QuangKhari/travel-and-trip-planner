<?php

namespace App\Console\Commands;

use Database\Seeders\DemoExploreSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExploreSeedDemo extends Command
{
    protected $signature = 'explore:seed-demo
        {--publish : Xuất bản các món, trải nghiệm, lịch trình đạt điều kiện (mặc định để draft)}
        {--publish-events : Xuất bản cả lễ hội (chỉ dùng sau khi đã đối chiếu ngày)}';

    protected $description = 'Nạp dữ liệu mẫu Khám phá: điểm đến đủ 34 tỉnh/thành, món ăn, trải nghiệm, lễ hội, lịch trình';

    public function handle(): int
    {
        if (!DB::table('tbl_inspirations')->exists()) {
            $this->call('db:seed', ['--class' => \Database\Seeders\DiscoverSeeder::class, '--force' => true]);
        }
        $seeder = (new DemoExploreSeeder())->with((bool) $this->option('publish') || (bool) $this->option('publish-events'), (bool) $this->option('publish-events'));
        $seeder->setContainer(app())->setCommand($this)->run();

        return self::SUCCESS;
    }
}
