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

// Đối soát VNPay (querydr) cho đơn sắp / vừa hết hạn giữ chỗ mà chưa nhận IPN — chạy TRƯỚC expire-holds (A1)
Artisan::command('payments:reconcile-vnpay', function () {
    if (!\App\Services\VnpayService::enabled()) {
        return;
    }
    $svc = app(\App\Services\VnpayService::class);
    $ids = DB::table('tbl_booking as b')->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
        ->where('b.bookingStatus', 'n')->where('c.paymentStatus', 'n')->where('c.paymentMethod', 'vnpay')
        ->whereNotNull('c.providerTxnRef')->whereNotNull('b.holdExpiresAt')
        ->where('b.holdExpiresAt', '<=', now()->addMinutes(2))->limit(50)->pluck('b.bookingId');
    foreach ($ids as $id) {
        $r = $svc->queryAndSettle((int) $id);
        $this->line("#{$id}: {$r['message']}");
    }
})->purpose('Hỏi VNPay (querydr) trạng thái các đơn VNPay sắp hết hạn giữ chỗ');
Schedule::command('payments:reconcile-vnpay')->everyMinute()->withoutOverlapping();

// Hủy đơn quá hạn giữ chỗ, trả chỗ và nhả mã giảm giá - mỗi phút (A2: lệnh nhẹ, chỉ quét đơn n đã quá hạn)
Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();

// Lịch khởi hành: qua ngày thì "chuyến gần nhất" của từng tour đổi -> ghi lại bản tóm tắt trên tbl_tours
Artisan::command('departures:sync', function () {
    $svc = app(\App\Services\DepartureService::class);
    $n = 0;
    foreach (DB::table('tbl_tours')->pluck('tourId') as $id) {
        $svc->ensureForTour((int) $id);
        $svc->syncTour((int) $id);
        $n++;
    }
    $this->info("Đã đồng bộ {$n} tour.");
})->purpose('Đồng bộ ngày khởi hành gần nhất và số chỗ còn lại lên tbl_tours');
Schedule::command('departures:sync')->dailyAt('00:05')->withoutOverlapping();

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
