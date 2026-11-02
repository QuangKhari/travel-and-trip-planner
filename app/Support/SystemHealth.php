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

        $ok = collect($checks)->every(fn($c) => $c['ok'] || !$c['required']);

        return ['ok' => $ok, 'checks' => $checks];
    }
}
