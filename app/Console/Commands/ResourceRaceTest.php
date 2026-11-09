<?php

namespace App\Console\Commands;

use App\Services\ResourceAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Chứng minh bằng thực nghiệm: một xe C chỗ, N tiến trình CÙNG LÚC xin Q chỗ cùng khung giờ
 * => đúng floor(C / Q) yêu cầu thành công, tổng chỗ đã xếp không vượt C. Chỉ chạy ở APP_ENV=local.
 *   php artisan resources:race-test --workers=20 --capacity=16 --qty=5     (kỳ vọng 3 thành công)
 * Lệnh tự tạo tài nguyên RACE-TEST, chạy, in kết quả rồi xóa dữ liệu thử.
 */
class ResourceRaceTest extends Command
{
    protected $signature = 'resources:race-test {--workers=10} {--capacity=16} {--qty=5} {--worker= : nội bộ} {--resource= : nội bộ} {--at= : nội bộ}';

    protected $description = 'Test tranh chấp tài nguyên: nhiều tiến trình xếp cùng một xe cùng lúc';

    public function handle(ResourceAllocationService $svc): int
    {
        if (!app()->environment('local')) {
            $this->error('Chỉ chạy ở môi trường local (APP_ENV=local).');

            return self::FAILURE;
        }
        if ($this->option('worker') !== null) {
            return $this->worker($svc);
        }
        $n = max(2, (int) $this->option('workers'));
        $cap = max(1, (int) $this->option('capacity'));
        $qty = max(1, (int) $this->option('qty'));
        $rid = DB::table('tbl_rescue_resources')->insertGetId(['name' => 'RACE-TEST xe', 'kind' => 'transport', 'capacity' => $cap, 'active' => true,
            'created_at' => now(), 'updated_at' => now()]);
        $at = microtime(true) + 1.5;
        $procs = [];
        for ($i = 1; $i <= $n; $i++) {
            $p = new Process([PHP_BINARY, base_path('artisan'), 'resources:race-test', '--worker=' . $i, '--resource=' . $rid, '--qty=' . $qty, '--at=' . $at]);
            $p->start();
            $procs[] = $p;
        }
        $ok = 0;
        $rejected = 0;
        foreach ($procs as $p) {
            $p->wait();
            $line = trim($p->getOutput());
            str_starts_with($line, 'OK') ? $ok++ : (str_starts_with($line, 'FULL') ? $rejected++ : $this->warn('Lỗi tiến trình: ' . $line . trim($p->getErrorOutput())));
        }
        $used = (int) DB::table('tbl_resource_allocations')->where('resourceId', $rid)->sum('quantity');
        $expect = intdiv($cap, $qty);
        $this->table(['Tiến trình', 'Sức chứa', 'Mỗi yêu cầu', 'Thành công', 'Bị từ chối', 'Đã xếp', 'Kỳ vọng thành công'], [[$n, $cap, $qty, $ok, $rejected, $used, min($n, $expect)]]);
        DB::table('tbl_resource_allocations')->where('resourceId', $rid)->delete();
        DB::table('tbl_rescue_resources')->where('resourceId', $rid)->delete();
        $pass = $ok === min($n, $expect) && $used <= $cap;
        $pass ? $this->info('ĐẠT: không xếp quá sức chứa.') : $this->error('KHÔNG ĐẠT');

        return $pass ? self::SUCCESS : self::FAILURE;
    }

    private function worker(ResourceAllocationService $svc): int
    {
        while (microtime(true) < (float) $this->option('at')) {
            usleep(200);
        }
        try {
            $svc->allocate((int) $this->option('resource'), 'race', (int) $this->option('worker'), '2026-12-12 06:00', '2026-12-12 18:00', (int) $this->option('qty'));
            $this->line('OK');
        } catch (ValidationException) {
            $this->line('FULL');
        }

        return self::SUCCESS;
    }
}
