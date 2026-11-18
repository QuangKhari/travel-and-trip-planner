<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Nhịp của queue worker: schedule đẩy job này 5 phút một lần; worker chạy được thì ghi thời điểm (SystemHealth đọc). */
class Heartbeat implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put('ops:hb:queue', time(), 86400);
    }
}
