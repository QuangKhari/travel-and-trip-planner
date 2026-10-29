<?php

namespace App\Services\Planner;

/**
 * Chọn địa chỉ "style" cho bản đồ vector (MapLibre) theo cấu hình .env.
 * Thứ tự: MAP_STYLE_URL -> MapTiler -> Goong -> OpenFreeMap (miễn phí, không cần khóa).
 * Khóa MapTiler/Goong là khóa dùng ở trình duyệt, nên giới hạn theo tên miền trong trang quản lý của nhà cung cấp.
 */
class MapStyle
{
    public static function url(): ?string
    {
        $cfg = (array) config('services.map', []);

        $custom = trim((string) ($cfg['style_url'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $mt = trim((string) ($cfg['maptiler_key'] ?? ''));
        if ($mt !== '') {
            $style = preg_replace('/[^a-z0-9_-]/i', '', (string) ($cfg['maptiler_style'] ?? 'streets-v2')) ?: 'streets-v2';
            return 'https://api.maptiler.com/maps/' . $style . '/style.json?key=' . rawurlencode($mt);
        }

        $goong = trim((string) ($cfg['goong_maptiles_key'] ?? ''));
        if ($goong !== '') {
            return 'https://tiles.goong.io/assets/goong_map_web.json?api_key=' . rawurlencode($goong);
        }

        $free = trim((string) ($cfg['free_style_url'] ?? ''));
        return $free !== '' ? $free : null;
    }
}
