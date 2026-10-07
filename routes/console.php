<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dọn ảnh tour mồ côi (file không còn được tbl_images tham chiếu, cũ hơn 24h) - chạy hằng tuần.
Schedule::command('media:cleanup-orphans')->weekly()->withoutOverlapping();

// Hủy đơn quá hạn giữ chỗ, trả chỗ và nhả mã giảm giá - mỗi 5 phút
Schedule::command('bookings:expire-holds')->everyFiveMinutes()->withoutOverlapping();
