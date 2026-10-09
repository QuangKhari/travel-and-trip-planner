<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dọn ảnh tour mồ côi (file không còn được tbl_images tham chiếu, cũ hơn 24h) - chạy hằng tuần.
Schedule::command('media:cleanup-orphans')->weekly()->withoutOverlapping();

// Hủy đơn quá hạn giữ chỗ, trả chỗ và nhả mã giảm giá - mỗi 5 phút
Schedule::command('bookings:expire-holds')->everyFiveMinutes()->withoutOverlapping();

// Xóa tài khoản đăng ký quá 7 ngày mà chưa kích hoạt email (và chưa có đơn nào), giải phóng username/email
Schedule::call(function () {
    DB::table('tbl_users')
        ->where('isActive', 'n')
        ->where('createdDate', '<', now()->subDays(7))
        ->whereNotExists(function ($q) {
            $q->select(DB::raw(1))->from('tbl_booking')->whereColumn('tbl_booking.userId', 'tbl_users.userId');
        })
        ->delete();
})->daily()->name('users:purge-unverified')->withoutOverlapping();
