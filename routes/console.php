<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dọn ảnh tour mồ côi, ảnh tạm quá hạn và tour nháp bỏ dở (chúng chiếm hạn mức ảnh) - mỗi đêm.
Schedule::command('media:cleanup-orphans')->dailyAt('04:00')->withoutOverlapping();

// Tự hoàn tất đơn đã thanh toán của tour đã kết thúc, để khách đánh giá được ngay (L-F-11) - mỗi đêm.
Schedule::command('bookings:complete-finished')->dailyAt('00:30')->withoutOverlapping();

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

// Thời tiết điểm đến (Open-Meteo): 1-2 request mỗi lần, lưu cache DB; web không gọi trực tiếp.
Schedule::command('discover:warm-weather')->everyThreeHours()->withoutOverlapping();

Schedule::command('rag:build')->dailyAt('03:00')->withoutOverlapping();

// Có nội dung mới được duyệt (cờ rag.dirty do PublicCache đặt) thì dựng lại chỉ mục, tối đa mỗi 10 phút
Schedule::command('rag:build')->everyTenMinutes()->when(fn() => Cache::pull('rag.dirty') !== null)->withoutOverlapping();
