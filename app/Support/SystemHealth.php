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
            if (Cache::get($k) !== 1) {
                throw new \RuntimeException('cache');
            }
            Cache::forget($k);

            return (string) config('cache.default');
        });
        $run('queue', false, function () {
            $conn = (string) config('queue.default');
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
