<?php

namespace App\Console\Commands;

use App\Services\Push\WebPush;
use Illuminate\Console\Command;

class PushVapid extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Tạo cặp khóa VAPID cho thông báo đẩy (chép 2 dòng in ra vào .env)';

    public function handle(): int
    {
        $k = WebPush::generateKeys();
        $this->line('WEB_PUSH_PUBLIC_KEY=' . $k['public']);
        $this->line('WEB_PUSH_PRIVATE_KEY=' . $k['private']);
        $this->info('Chép vào .env rồi chạy php artisan config:clear. Đổi khóa thì mọi thiết bị phải bật thông báo lại.');

        return self::SUCCESS;
    }
}
