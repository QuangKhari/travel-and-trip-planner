<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Cầu dao" cho dịch vụ ngoài (Overpass, Photon, OSRM).
 *
 * Vì sao cần: `php artisan serve` (nhất là trên Windows) chỉ xử lý MỘT yêu cầu một lúc. Một cuộc gọi mạng treo 40 giây
 * làm mọi trang khác (trang chủ, tour...) xếp hàng chờ theo, người dùng thấy "cả web bị treo".
 *
 * Quy tắc:
 *  - mỗi dịch vụ có hạn thời gian ngắn (connect 2–3 giây, tổng vài giây);
 *  - lỗi một lần thì "ngắt" dịch vụ đó trong `planner.external.down_seconds` giây: các lần gọi sau trả ngay, dùng dữ liệu dự phòng;
 *  - hết thời gian ngắt thì thử lại một lần; thành công thì đóng cầu dao.
 */
final class ExternalGuard
{
    private const PREFIX = 'ext:down:';

    public static function isDown(string $service): bool
    {
        try {
            return Cache::has(self::PREFIX . $service);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Ghi nhận lỗi: ngắt dịch vụ trong một khoảng thời gian (mặc định theo cấu hình). */
    public static function trip(string $service, string $why, ?int $seconds = null): void
    {
        $seconds ??= (int) config('planner.external.down_seconds', 300);
        Log::info("ExternalGuard: tạm ngắt $service {$seconds}s ($why)");
        try {
            Cache::put(self::PREFIX . $service, $why, now()->addSeconds(max(5, $seconds)));
        } catch (\Throwable) {
        }
    }

    public static function reset(string $service): void
    {
        try {
            Cache::forget(self::PREFIX . $service);
        } catch (\Throwable) {
        }
    }

    /** Lý do đang ngắt (null nếu đang bình thường). */
    public static function reason(string $service): ?string
    {
        try {
            $v = Cache::get(self::PREFIX . $service);

            return is_string($v) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Có được gọi mạng thật cho dịch vụ này không (tắt hẳn bằng PLANNER_EXTERNAL=off khi demo không có mạng). */
    public static function allowed(string $service): bool
    {
        return (bool) config('planner.external.enabled', true) && !self::isDown($service);
    }
}
