<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * URL ảnh đại diện cho view.
 *   <img src="{{ \App\Support\Avatar::url($user->avatar) }}">
 *   <img src="{{ \App\Support\Avatar::admin() }}">
 *
 * Giá trị tbl_users.avatar có ba dạng:
 *   - rỗng              → ảnh mặc định unnamed.png (nằm trong source)
 *   - "avatars/..webp"  → storage/app/public/avatars/...  (dạng mới)
 *   - tên file trơn     → public/admin/assets/images/user-profile/{tên}  (legacy / ảnh mặc định)
 */
class Avatar
{
    private const LEGACY_DIR = 'admin/assets/images/user-profile/';

    public static function url(?string $value): string
    {
        if ($value === null || $value === '') {
            return asset(self::LEGACY_DIR . 'unnamed.png');
        }

        if (self::isNew($value)) {
            return self::publicUrl($value);
        }

        return asset(self::LEGACY_DIR . basename($value));
    }

    /** Ảnh admin: ghi đè avatars/admin.webp; thêm ?v=thời-gian-sửa để trình duyệt không giữ ảnh cũ. */
    public static function admin(): string
    {
        $path = trim((string) config('media.avatar.dir', 'avatars'), '/') . '/admin.webp';
        $diskName = config('media.disk', 'public');

        try {
            $disk = Storage::disk($diskName);
            if ($disk->exists($path)) {
                return self::publicUrl($path) . '?v=' . $disk->lastModified($path);
            }
        } catch (\Throwable $e) {
            // rơi về ảnh mặc định
        }

        return asset(self::LEGACY_DIR . 'avt_admin.jpg');
    }

    private static function isNew(string $value): bool
    {
        $dir = trim((string) config('media.avatar.dir', 'avatars'), '/');

        return str_starts_with($value, $dir . '/') && !str_contains($value, '..');
    }

    private static function publicUrl(string $path): string
    {
        $diskName = config('media.disk', 'public');

        if ($diskName === 'public') {
            return asset('storage/' . ltrim($path, '/'));
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $fs */
        $fs = Storage::disk($diskName);

        return $fs->url($path);
    }
}