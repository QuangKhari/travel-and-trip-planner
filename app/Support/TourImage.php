<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Sinh URL ảnh tour cho view. Dùng trong Blade:
 *
 *   <img src="{{ \App\Support\TourImage::url($tour->images[0] ?? null, 800) }}"
 *        srcset="{{ \App\Support\TourImage::srcset($tour->images[0] ?? null) }}"
 *        sizes="(max-width: 768px) 100vw, 400px" loading="lazy" ...>
 *
 * Giá trị tbl_images.imageURL có hai dạng:
 *   - MỚI  : "tours/12/9f3a...c1"  → file storage/app/public/tours/12/9f3a...c1-{320|800|1600}.webp
 *   - CŨ   : "ten-anh.jpg"         → public/admin/assets/images/gallery-tours/ten-anh.jpg
 *            (chỉ còn tồn tại tới khi chạy `php artisan media:migrate-tour-images`)
 */
class TourImage
{
    private const SIZES = [320, 800, 1600];

    /** URL của cỡ nhỏ nhất đủ rộng (>= $width); không có thì lấy cỡ lớn nhất. */
    public static function url(?string $value, int $width = 800): string
    {
        if (self::isPlaceholder($value)) {
            return asset('images/default-tour.svg');
        }

        if (!self::isStem($value)) {
            return asset('admin/assets/images/gallery-tours/' . basename((string) $value));
        }

        return self::publicUrl($value . '-' . self::pickSize($width) . '.webp');
    }

    /** Chuỗi srcset (rỗng với ảnh dạng cũ hoặc ảnh mặc định). */
    public static function srcset(?string $value): string
    {
        if (self::isPlaceholder($value) || !self::isStem($value)) {
            return '';
        }

        return implode(', ', array_map(
            fn(int $w) => self::publicUrl("{$value}-{$w}.webp") . " {$w}w",
            self::SIZES
        ));
    }

    public static function isStem(?string $value): bool
    {
        $dir = trim((string) config('media.tours_dir', 'tours'), '/');

        return is_string($value)
            && str_starts_with($value, $dir . '/')
            && !str_contains($value, '..')
            && !str_contains($value, '\\');
    }

    private static function isPlaceholder(?string $value): bool
    {
        return $value === null || $value === '' || $value === 'default.jpg';
    }

    private static function pickSize(int $width): int
    {
        foreach (self::SIZES as $size) {
            if ($size >= $width) {
                return $size;
            }
        }

        return self::SIZES[array_key_last(self::SIZES)];
    }

    /** Disk "public": URL tương đối theo host của request (không cứng 127.0.0.1:8000). */
    private static function publicUrl(string $path): string
    {
        $disk = config('media.disk', 'public');

        if ($disk === 'public') {
            return asset('storage/' . ltrim($path, '/'));
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $fs */
        $fs = Storage::disk($disk);

        return $fs->url($path);
    }
}
