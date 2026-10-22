<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * URL ảnh bìa của cảm hứng / điểm đến ("Khám phá theo cảm hứng").
 *
 * Ảnh nằm ngoài source, trong disk public:
 *   storage/app/public/discover/inspirations/{slug}.webp
 *   storage/app/public/discover/destinations/{slug}.webp
 *
 * Chưa có file → trả null, view tự vẽ nền gradient thay thế (trang vẫn đẹp khi chưa đủ ảnh).
 */
class DiscoverImage
{
    /**
     * Bản thu nhỏ (khoảng 48 px, đuôi -s.webp) do content:import-images --thumb sinh ra, dùng cho nền mờ.
     * Không có file thì trả null để nơi gọi dùng ảnh đầy đủ.
     */
    public static function thumbUrl(?string $path): ?string
    {
        if ($path === null || !str_ends_with($path, '.webp')) {
            return null;
        }

        return self::url(substr($path, 0, -5) . '-s.webp');
    }

    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '' || str_contains($path, '..')) {
            return null;
        }

        $disk = config('media.disk', 'public');

        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        if ($disk === 'public') {
            return asset('storage/' . ltrim($path, '/'));
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $fs */
        $fs = Storage::disk($disk);

        return $fs->url($path);
    }
}
