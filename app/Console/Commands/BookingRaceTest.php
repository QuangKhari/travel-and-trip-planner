<?php

namespace App\Console\Commands;

use App\Support\BookingPii;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Chứng minh bằng thực nghiệm: tour còn 1 chỗ, N người đặt CÙNG LÚC => đúng 1 người thành công.
 * Chỉ chạy ở APP_ENV=local. Lệnh tự đặt quantity, chạy test, rồi khôi phục quantity và xóa các đơn RACE-TEST.
 *   php artisan bookings:race-test 5 --users=3
 */
class BookingRaceTest extends Command
{
    protected $signature = 'bookings:race-test {tourId} {--users=2 : số người đặt cùng lúc} {--slots=1 : số chỗ còn lại ban đầu}';

    protected $description = 'Test tranh chỗ cuối cùng: nhiều tiến trình đặt cùng lúc';

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Chỉ chạy ở môi trường local (APP_ENV=local).');
            return self::FAILURE;
        }

        $tourId = (int) $this->argument('tourId');
        $n      = max(2, (int) $this->option('users'));
        $slots  = max(1, (int) $this->option('slots'));

        $tour = DB::table('tbl_tours')->where('tourId', $tourId)->first();
        if (!$tour) {
            $this->error('Không có tour này.');
            return self::FAILURE;
        }

        $userIds = DB::table('tbl_users')->orderBy('userId')->limit($n)->pluck('userId')->all();
        if (!$userIds) {
            $this->error('Chưa có user nào trong tbl_users.');
            return self::FAILURE;
        }
        // Thiếu user thì dùng lại user đầu: cùng một người đặt song song vẫn là phép thử tranh chỗ hợp lệ
        $userIds = array_pad($userIds, $n, $userIds[0]);

        $original = (int) $tour->quantity;
        DB::table('tbl_tours')->where('tourId', $tourId)->update(['quantity' => $slots]);

        $this->info("Tour #{$tourId}: đặt quantity = {$slots}; {$n} tiến trình cùng đặt 1 chỗ mỗi người...");

        $startAt = microtime(true) + 4;   // chừa thời gian cho các tiến trình khởi động xong
        $procs = [];
        foreach ($userIds as $uid) {
            $p = new Process(
                [PHP_BINARY, base_path('artisan'), 'bookings:race-worker', (string) $tourId, (string) $uid, '1', sprintf('%.4f', $startAt)],
                base_path()
            );
            $p->start();
            $procs[] = $p;
        }

        $success = [];
        foreach ($procs as $i => $p) {
            $p->wait();
            $lines = array_values(array_filter(array_map('trim', explode("\n", $p->getOutput()))));
            $json  = $lines ? json_decode(end($lines), true) : null;
            $this->line(sprintf('  người %d: %s', $i + 1, $json ? json_encode($json, JSON_UNESCAPED_UNICODE) : 'LỖI: ' . $p->getErrorOutput()));
            if (!empty($json['ok'])) {
                $success[] = $json['bookingId'];
            }
        }

        $left = (int) DB::table('tbl_tours')->where('tourId', $tourId)->value('quantity');

        // Dọn dẹp: xóa đơn test (chỉ những đơn có tên RACE-TEST), khôi phục quantity gốc
        $ids = DB::table('tbl_booking')->where('tourId', $tourId)->whereNotNull('requestToken')->get()
            ->filter(fn($b) => BookingPii::decrypt($b)->fullName === 'RACE-TEST')
            ->pluck('bookingId');

        DB::transaction(function () use ($ids, $tourId, $original) {
            DB::table('tbl_checkout')->whereIn('bookingId', $ids)->delete();
            DB::table('tbl_booking')->whereIn('bookingId', $ids)->delete();
            DB::table('tbl_tours')->where('tourId', $tourId)->update(['quantity' => $original]);
        });

        $expectOk   = min($slots, $n);
        $expectLeft = $slots - $expectOk;

        $this->newLine();
        $this->line('Thành công: ' . count($success) . " (kỳ vọng {$expectOk}) | quantity sau test: {$left} (kỳ vọng {$expectLeft})");

        $pass = count($success) === $expectOk && $left === $expectLeft;
        $pass ? $this->info('PASS: không bán lố chỗ.') : $this->error('FAIL: có bán lố hoặc sai số chỗ!');

        return $pass ? self::SUCCESS : self::FAILURE;
    }
}
