<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Kiểm tra nhanh các thành phần vận hành (dùng cho /health và trang Bảo trì hệ thống). */
final class SystemHealth
{
    /** @return array{ok:bool, checks:array<string, array{ok:bool, ms:float, detail:string, required:bool}>} */
    public static function check(): array
    {
        $checks = [];
        $run = function (string $name, bool $required, callable $fn) use (&$checks) {
            $t = microtime(true);
            try {
                $detail = (string) $fn();
                $ok = true;
            } catch (\Throwable $e) {
                $detail = $e->getMessage() ?: class_basename($e);
                $ok = false;
            }
            $checks[$name] = ['ok' => $ok, 'ms' => round((microtime(true) - $t) * 1000, 1), 'detail' => $detail, 'required' => $required];
        };

        $run('database', true, function () {
            DB::select('select 1');

            return DB::connection()->getDriverName();
        });
        $run('cache', true, function () {
            $k = 'health:' . bin2hex(random_bytes(4));
            Cache::put($k, 1, 10);
            if ((int) Cache::get($k) !== 1) {   // Redis trả số dạng chuỗi
                throw new \RuntimeException('cache');
            }
            Cache::forget($k);

            return (string) config('cache.default');
        });
        if (config('cache.default') === 'redis' || config('queue.default') === 'redis' || config('session.driver') === 'redis') {
            $run('redis', true, function () {
                $pong = \Illuminate\Support\Facades\Redis::connection()->ping();

                return is_object($pong) ? (string) $pong : 'PONG';
            });
        }
        $run('queue', false, function () {
            $conn = (string) config('queue.default');
            if ($conn === 'redis') {
                $q = (string) config('queue.connections.redis.queue', 'default');
                $n = (int) \Illuminate\Support\Facades\Redis::connection(config('queue.connections.redis.connection', 'default'))->llen('queues:' . $q);
                if ($n > 500) {
                    throw new \RuntimeException("{$n} việc chờ");
                }

                return "redis: {$n} việc chờ";
            }
            if ($conn === 'database' && Schema::hasTable('jobs')) {
                $pending = DB::table('jobs')->count();
                $old = DB::table('jobs')->where('created_at', '<', now()->subMinutes(10)->getTimestamp())->count();
                if ($old > 0) {
                    throw new \RuntimeException('stale');
                }

                return "{$conn}: {$pending} việc chờ";
            }

            return $conn;
        });
        // Nhịp scheduler / worker (routes/console.php): cron hay worker dừng thì các việc định kỳ (hết hạn giữ chỗ, đối soát, hoàn tiền,
        // sự kiện nghiệp vụ, email) lặng lẽ ngừng — phải thấy được ở đây.
        $run('scheduler', false, function () {
            $t = (int) \Illuminate\Support\Facades\Cache::get('ops:hb:scheduler', 0);
            if ($t === 0) {
                throw new \RuntimeException('chưa thấy nhịp: cron "php artisan schedule:run" mỗi phút đã được cài chưa?');
            }
            $ago = time() - $t;
            if ($ago > 180) {
                throw new \RuntimeException('nhịp cuối cách đây ' . intdiv($ago, 60) . ' phút: scheduler có thể đã dừng');
            }

            return 'nhịp cuối ' . $ago . ' giây trước';
        });
        $run('worker', false, function () {
            if (config('queue.default') === 'sync') {
                return 'sync (không cần worker)';
            }
            $t = (int) \Illuminate\Support\Facades\Cache::get('ops:hb:queue', 0);
            if ($t === 0) {
                throw new \RuntimeException('chưa thấy worker xử lý việc nào: "php artisan queue:work" đang chạy chưa?');
            }
            $ago = time() - $t;
            if ($ago > 900) {
                throw new \RuntimeException('worker xử lý việc cuối cách đây ' . intdiv($ago, 60) . ' phút');
            }

            return 'xử lý việc cuối ' . intdiv($ago, 60) . ' phút trước';
        });
        $run('outbox', false, function () {
            if (!Schema::hasTable('tbl_domain_events')) {
                return 'không dùng';
            }
            $late = DB::table('tbl_domain_events')->whereNull('processedAt')->where('createdAt', '<', now()->subMinutes(5))->count();
            if ($late > 0) {
                throw new \RuntimeException($late . ' sự kiện nghiệp vụ chưa xử lý quá 5 phút');
            }

            return 'không tồn đọng';
        });
        $run('failed_jobs', false, function () {
            if (!Schema::hasTable('failed_jobs')) {
                return 'không dùng';
            }
            $n = DB::table('failed_jobs')->count();
            if ($n > 0) {
                throw new \RuntimeException("{$n}");
            }

            return '0';
        });
        $run('storage', true, function () {
            $dir = storage_path('framework');
            if (!is_writable($dir)) {
                throw new \RuntimeException('readonly');
            }
            $free = @disk_free_space($dir);

            return $free ? round($free / 1073741824, 1) . ' GB trống' : 'ghi được';
        });

        // AI (Đợt D mục D6): không bắt buộc — tắt hoặc hỏng thì hệ thống lùi về chế độ luật
        $run('ai', false, function () {
            if (!config('ai.enabled')) {
                return 'tắt (chế độ luật)';
            }
            $open = \App\Services\Ai\AiGateway::openCircuits();
            if (isset($open['*'])) {
                throw new \RuntimeException('ngắt mạch toàn bộ thêm ' . $open['*'] . ' giây, đang dùng chế độ luật');
            }
            if ($open) {
                throw new \RuntimeException('ngắt mạch: ' . implode(', ', array_map(fn($f, $s) => $f . ' (' . $s . ' giây)', array_keys($open), $open)) . ' — các tính năng khác vẫn chạy');
            }
            if (!Schema::hasTable('tbl_ai_runs')) {
                return 'bật';
            }
            $today = DB::table('tbl_ai_runs')->where('createdAt', '>=', now()->startOfDay());
            $llm = (clone $today)->where('engine', 'llm')->count();
            $fail = (clone $today)->where('engine', 'llm')->where('outcome', '!=', 'ok')->count();
            $fallback = (clone $today)->where('engine', '!=', 'llm')->count();
            if ($llm >= 10 && $fail / max(1, $llm) > 0.5) {
                throw new \RuntimeException("hôm nay $fail/$llm lượt lỗi, có thể hết hạn mức nhà cung cấp");
            }

            $usd = \App\Services\Ai\AiCost::report(now()->toDateString(), now()->toDateString())['total'];

            return "bật · hôm nay $llm lượt AI ($fail lỗi), $fallback lượt chế độ luật, chi phí ~\$" . number_format($usd, 4) . ' · hạn mức ' . (int) config('ai.limits.daily_per_user', 40) . '/người/ngày';
        });

        $ok = collect($checks)->every(fn($c) => $c['ok'] || !$c['required']);

        return ['ok' => $ok, 'checks' => $checks];
    }
}
