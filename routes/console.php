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

// Đối soát thanh toán trực tuyến (MoMo, ZaloPay) cho đơn sắp / vừa hết hạn giữ chỗ mà chưa nhận được thông báo của cổng — chạy TRƯỚC expire-holds (A1).
// QR chuyển khoản không có API hỏi trạng thái: chờ webhook SePay hoặc admin xác nhận.
Artisan::command('payments:reconcile', function () {
    $svc = app(\App\Services\Payments\OnlinePaymentService::class);
    $ids = DB::table('tbl_booking as b')->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
        ->where('b.bookingStatus', 'n')->where('c.paymentStatus', 'n')->whereIn('c.paymentMethod', ['momo', 'zalopay'])
        ->whereNotNull('c.providerTxnRef')->whereNotNull('b.holdExpiresAt')
        ->where('b.holdExpiresAt', '<=', now()->addMinutes(2))->limit(50)->pluck('b.bookingId');
    foreach ($ids as $id) {
        $r = $svc->queryAndSettle((int) $id);
        $this->line("#{$id}: {$r['message']}");
    }
})->purpose('Hỏi MoMo / ZaloPay trạng thái các đơn sắp hết hạn giữ chỗ');
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();

// Hủy đơn quá hạn giữ chỗ, trả chỗ và nhả mã giảm giá - mỗi phút (A2: lệnh nhẹ, chỉ quét đơn n đã quá hạn)
Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();

// C-10: email nhắc khách 3 ngày và 1 ngày trước ngày khởi hành (không gửi trùng)
Schedule::command('bookings:remind-departure')->dailyAt('08:00')->withoutOverlapping();

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

// Chạy thử QR chuyển khoản trên máy cá nhân (không có tiền thật): giả một thông báo SePay "tiền vào" cho mã đơn, đi qua đúng đường webhook.
// Chỉ chạy ngoài production.  php artisan payments:simulate-transfer TOUR20261103...  [--amount=]
Artisan::command('payments:simulate-transfer {bookingCode} {--amount= : Số tiền (mặc định = số tiền của đơn)}', function () {
    if (app()->isProduction()) {
        $this->error('Không chạy ở production.');

        return 1;
    }
    $code = strtoupper((string) $this->argument('bookingCode'));
    $c = DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->where('b.bookingCode', $code)->first(['c.amount']);
    if (!$c) {
        $this->error('Không có đơn ' . $code);

        return 1;
    }
    $key = (string) config('payments.qr.sepay_api_key');
    if ($key === '') {
        $this->warn('Chưa đặt SEPAY_API_KEY nên webhook đang tắt; đặt một chuỗi bất kỳ trong .env để thử, hoặc xác nhận tay ở Admin → Thanh toán trực tuyến.');

        return 1;
    }
    $payload = ['id' => random_int(100000, 999999), 'gateway' => 'MBBank', 'transactionDate' => now()->format('Y-m-d H:i:s'),
        'accountNumber' => (string) config('payments.qr.account_no'), 'code' => null, 'content' => $code . ' thanh toan tour', 'transferType' => 'in',
        'description' => 'CHUYEN TIEN ' . $code, 'transferAmount' => (int) ($this->option('amount') ?: round((float) $c->amount)), 'accumulated' => 0, 'referenceCode' => 'SIM' . time()];
    $req = Illuminate\Http\Request::create('/thanh-toan/thong-bao/qr', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Apikey ' . $key], json_encode($payload));
    $res = app(\App\Services\Payments\OnlinePaymentService::class)->handleNotify('qr', $req);
    $this->info('Webhook trả về ' . $res->getStatusCode() . ' ' . $res->getContent());
    $st = DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->where('b.bookingCode', $code)->first(['c.paymentStatus', 'b.bookingStatus']);
    $this->line("Đơn {$code}: thanh toán = {$st->paymentStatus}, trạng thái = {$st->bookingStatus}");

    return 0;
})->purpose('Giả lập tiền chuyển khoản QR về tài khoản (thử webhook SePay trên máy cá nhân)');
