<?php

namespace App\Services\Travel;

use App\Services\Planner\ExternalGuard;
use Illuminate\Support\Facades\DB;

/**
 * Phần dùng chung của các API du lịch: cầu dao (PF-07, dùng lại ExternalGuard), đếm hạn mức theo tháng, nhãn giả lập.
 */
final class TravelApi
{
    public const SIM_LABEL = 'Dữ liệu thử nghiệm';

    public static function simulateOnly(): bool
    {
        return (bool) config('travel_apis.simulate', true);
    }

    public static function timeoutSeconds(): float
    {
        return max(1, (int) config('travel_apis.fallback_timeout_ms', 5000)) / 1000;
    }

    /** Dịch vụ đang bị ngắt (lỗi gần đây) thì không gọi. */
    public static function available(string $service): bool
    {
        return ExternalGuard::allowed($service);
    }

    public static function failed(string $service, string $why): void
    {
        ExternalGuard::trip($service, $why);
    }

    public static function ok(string $service): void
    {
        ExternalGuard::reset($service);
    }

    /** Còn được gọi trong tháng? (dừng ở quota_stop_ratio để chừa lượt) */
    public static function quotaLeft(string $provider): bool
    {
        $limit = (int) config('travel_apis.flight_status.quota.' . $provider, 0);
        if ($limit <= 0) {
            return true;
        }
        $used = (int) DB::table('tbl_api_quota')->where(['provider' => $provider, 'period' => now()->format('Y-m')])->value('used');

        return $used < (int) floor($limit * (float) config('travel_apis.flight_status.quota_stop_ratio', 0.9));
    }

    public static function countCall(string $provider, int $units = 1): void
    {
        $period = now()->format('Y-m');
        DB::statement('INSERT INTO tbl_api_quota (provider, period, used) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE used = used + VALUES(used)', [$provider, $period, $units]);
    }

    /** Số giả lập ổn định theo đầu vào (cùng tìm kiếm -> cùng kết quả, không nhảy giá mỗi lần tải lại). */
    public static function seeded(string $seed, int $min, int $max, int $salt = 0): int
    {
        $h = hexdec(substr(md5($seed . '#' . $salt), 0, 8));

        return $min + ($h % max(1, $max - $min + 1));
    }

    public static function airportName(string $code): string
    {
        return (string) (config('travel_apis.airport_names.' . strtoupper($code)) ?? strtoupper($code));
    }
}
