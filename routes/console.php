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

// Đối soát thanh toán trực tuyến (VNPay, MoMo) cho đơn sắp / vừa hết hạn giữ chỗ mà chưa nhận được thông báo của cổng — chạy TRƯỚC expire-holds (A1).
// QR chuyển khoản không có API hỏi trạng thái: chờ webhook SePay hoặc admin xác nhận.
Artisan::command('payments:reconcile', function () {
    $svc = app(\App\Services\Payments\OnlinePaymentService::class);
    $ids = DB::table('tbl_booking as b')->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
        ->where('b.bookingStatus', 'n')->where('c.paymentStatus', 'n')->whereIn('c.paymentMethod', ['vnpay', 'momo'])
        ->whereNotNull('c.providerTxnRef')->whereNotNull('b.holdExpiresAt')
        ->where('b.holdExpiresAt', '<=', now()->addMinutes(2))->limit(50)->pluck('b.bookingId');
    foreach ($ids as $id) {
        $r = $svc->queryAndSettle((int) $id);
        $this->line("#{$id}: {$r['message']}");
    }
})->purpose('Hỏi VNPay / MoMo trạng thái các đơn sắp hết hạn giữ chỗ');
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();

// Hoàn tiền qua API VNPay / MoMo: hỏi lại các lệnh đang xử lý và (nếu PAYMENT_REFUND_AUTO=true) tự gửi lệnh hoàn lần đầu. Mặc định tắt cả hai.
Artisan::command('payments:process-refunds', function () {
    if (!\App\Services\Payments\GatewayRefundService::enabled()) {
        $this->line('Hoàn qua cổng đang tắt (PAYMENT_REFUND_ENABLED=false).');

        return;
    }
    $svc = app(\App\Services\Payments\GatewayRefundService::class);
    $synced = $svc->syncAll();
    $auto = $svc->autoProcess();
    $this->line("Đã hoàn xong sau khi hỏi lại: {$synced}; tự gửi lệnh hoàn thành công: {$auto}");
})->purpose('Đối soát và tự động hóa hoàn tiền qua cổng thanh toán');
Schedule::command('payments:process-refunds')->everyFiveMinutes()->withoutOverlapping();

// Đối soát booking – thanh toán – hoàn tiền: quét điểm không khớp mỗi giờ (xem Admin → Đối soát)
Artisan::command('payments:audit', function () {
    if (!\App\Services\Payments\PaymentReconciliationService::ready()) {
        $this->warn('Chưa chạy migration 2026_11_29_000001.');

        return;
    }
    $r = app(\App\Services\Payments\PaymentReconciliationService::class)->sync();
    $this->line("Điểm không khớp: {$r['found']} (mới {$r['new']}, tự đóng {$r['resolved']}, đang mở {$r['open']})");
})->purpose('Đối soát booking, thanh toán và hoàn tiền');
Schedule::command('payments:audit')->hourly()->withoutOverlapping();

// Danh sách chờ: hết hạn lời mời cũ và mời người kế tiếp khi có chỗ trống
Artisan::command('waitlist:process', function () {
    if (!\App\Services\WaitlistService::ready()) {
        $this->warn('Chưa chạy migration 2026_11_30_000001.');

        return;
    }
    $r = app(\App\Services\WaitlistService::class)->processAll();
    $this->line("Lời mời hết hạn: {$r['expired']}; lời mời mới: {$r['offered']}");
})->purpose('Xử lý danh sách chờ khi lịch khởi hành có chỗ trống');
Schedule::command('waitlist:process')->everyFiveMinutes()->withoutOverlapping();

// Hủy đơn quá hạn giữ chỗ, trả chỗ và nhả mã giảm giá - mỗi phút (A2: lệnh nhẹ, chỉ quét đơn n đã quá hạn)
Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();

// Thông báo phương án thiếu khách và tự đóng phương án hết hạn (cần queue worker và mail config).
Schedule::command('departures:rescue-notify')->everyFiveMinutes()->withoutOverlapping();

// Gắn cờ lịch khởi hành sắp thiếu khách tối thiểu để admin xử lý sớm (ghép đoàn / đổi ngày / hủy hoàn tiền).
Schedule::command('departures:check-shortfall')->dailyAt('07:00')->withoutOverlapping();

// C-10: email nhắc khách 3 ngày và 1 ngày trước ngày khởi hành (không gửi trùng)
Schedule::command('bookings:remind-departure')->dailyAt('08:00')->withoutOverlapping();
// Chuyến tự lên kế hoạch: nhắc việc đặt khách sạn / vé tới hạn hoặc còn thiếu (Travela không đặt hộ)
Schedule::command('trips:booking-reminders')->dailyAt('08:20')->withoutOverlapping();
// theo dõi chuyến sắp đi / đang đi: dự báo, cảnh báo điểm đến, giờ mở cửa, giờ tới / giờ đi -> chuông trong trang + email khi có thay đổi
Schedule::command('trips:watch')->cron('15 */3 * * *')->withoutOverlapping();
// bản tin chuyến đi: trước 7 ngày, trước 1 ngày, sáng mỗi ngày đi
Schedule::command('trips:digest')->dailyAt('06:30')->withoutOverlapping();

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
// PF-11: nạp sẵn cache trang chủ, mùa đi, dự báo theo giờ của chuyến sắp đi (cache dự báo sống tới hết giờ)
Schedule::command('cache:warm --only=home,stats,search,briefs,weather')->hourlyAt(5)->withoutOverlapping();

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
