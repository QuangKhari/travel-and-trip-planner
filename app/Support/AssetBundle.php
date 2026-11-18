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
        // (giờ sửa tệp mới hơn nhưng nội dung không đổi, ví dụ sau khi chép / giải nén: vẫn dùng bản gộp)
        if (config('app.debug')) {
            $srcs = (array) (config('assets.bundles')[$name] ?? []);
            foreach ($srcs as $src) {
                if (is_file(public_path($src)) && filemtime(public_path($src)) > (int) $m['built']) {
                    if (!isset($m['src']) || $m['src'] !== self::sourceHash($srcs)) {
                        return null;
                    }
                    break;
                }
            }
        }

        return $m['file'];
    }

    /** Mã băm nội dung các tệp nguồn (không phụ thuộc giờ sửa tệp, đúng cả sau khi chép / giải nén). */
    public static function sourceHash(array $files): string
    {
        $h = hash_init('md5');
        foreach ($files as $f) {
            hash_update($h, $f . "\0" . (is_file(public_path($f)) ? str_replace("\r\n", "\n", (string) file_get_contents(public_path($f))) : ''));
        }

        return hash_final($h);
    }

    /** Bản gộp đã cũ so với nguồn (hoặc chưa gộp): dùng cho trang Bảo trì và demo:check. */
    public static function stale(string $name): bool
    {
        $p = self::manifestPath();
        $m = is_file($p) ? ((json_decode((string) file_get_contents($p), true) ?: [])[$name] ?? null) : null;
        if (!$m || !is_file(public_path($m['file']))) {
            return true;
        }

        return isset($m['src']) && $m['src'] !== self::sourceHash((array) (config('assets.bundles')[$name] ?? []));
    }

    /**
     * URL cho một tệp JS lẻ: bản rút gọn (assets:bundle + esbuild) nếu còn khớp nội dung nguồn, không thì tệp gốc kèm ?v=.
     * Kiểm "còn khớp" rẻ: giờ sửa tệp nguồn mới hơn lúc rút gọn thì mới băm lại nội dung (sửa nguồn mà quên chạy lại lệnh vẫn đúng).
     */
    public static function js(string $file, int|string $v): string
    {
        $orig = asset($file) . '?v=' . $v;
        if (!config('assets.enabled', true)) {
            return $orig;
        }
        if (self::$manifest === null) {
            $p = self::manifestPath();
            self::$manifest = is_file($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];
        }
        $m = self::$manifest['min:' . $file] ?? null;
        $src = public_path($file);
        if (!$m || !is_file(public_path($m['file'])) || !is_file($src)) {
            return $orig;
        }
        if (filemtime($src) > (int) $m['built'] && ($m['src'] ?? '') !== md5(str_replace("\r\n", "\n", (string) file_get_contents($src)))) {
            return $orig;
        }

        return asset($m['file']);
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
