<?php

namespace App\Support;

/**
 * Chèn thẻ <link> / <script> cho một nhóm tài nguyên của config/assets.php: một tệp đã gộp nếu có và còn mới,
 * ngược lại từng tệp lẻ như trước (an toàn khi chưa chạy assets:bundle hoặc vừa sửa CSS).
 */
final class AssetBundle
{
    private static ?array $manifest = null;

    public static function manifestPath(): string
    {
        return public_path(config('assets.dir', 'clients/assets/build') . '/manifest.json');
    }

    public static function reset(): void
    {
        self::$manifest = null;
    }

    /** Đường dẫn tương đối (từ public) của bản gộp đang dùng được, hoặc null. */
    public static function built(string $name): ?string
    {
        if (!config('assets.enabled', true)) {
            return null;
        }
        if (self::$manifest === null) {
            $p = self::manifestPath();
            self::$manifest = is_file($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];
        }
        $m = self::$manifest[$name] ?? null;
        if (!$m || !is_file(public_path($m['file']))) {
            return null;
        }
        // đang phát triển: tệp nguồn sửa sau lần gộp -> dùng tệp lẻ, không hiện CSS cũ
        if (config('app.debug')) {
            foreach ((array) (config('assets.bundles')[$name] ?? []) as $src) {
                if (is_file(public_path($src)) && filemtime(public_path($src)) > (int) $m['built']) {
                    return null;
                }
            }
        }

        return $m['file'];
    }

    public static function tags(string $name): string
    {
        $isCss = str_ends_with($name, '.css');
        $one = fn(string $url) => $isCss ? '<link rel="stylesheet" href="' . e($url) . '">' : '<script src="' . e($url) . '"></script>';
        if ($b = self::built($name)) {
            return $one(asset($b));
        }
        $files = (array) (config('assets.bundles')[$name] ?? []);

        return implode("\n    ", array_map(fn($f) => $one(asset($f) . (is_file(public_path($f)) ? '?v=' . filemtime(public_path($f)) : '')), $files));
    }
}
