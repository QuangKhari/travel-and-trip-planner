<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Mật khẩu: bcrypt cho tài khoản mới, vẫn đọc được MD5 cũ để nâng cấp dần (L-A-02).
 */
class PasswordHasher
{
    public static function make(string $plain): string
    {
        return Hash::make($plain);
    }

    /** Hash MD5 cũ: đúng 32 ký tự thập lục phân */
    public static function isLegacyMd5(?string $stored): bool
    {
        return is_string($stored) && preg_match('/^[a-f0-9]{32}$/i', $stored) === 1;
    }

    public static function check(string $plain, ?string $stored): bool
    {
        if (!is_string($stored) || $stored === '') {
            return false;
        }

        // Kiểm tra MD5 TRƯỚC: Hash::check có thể ném lỗi khi gặp chuỗi không phải bcrypt
        if (self::isLegacyMd5($stored)) {
            return hash_equals(strtolower($stored), md5($plain));
        }

        try {
            return Hash::check($plain, $stored);
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /** Cần ghi lại bằng hash mới (còn là MD5, hoặc bcrypt với chi phí cũ)? */
    public static function needsUpgrade(?string $stored): bool
    {
        if (self::isLegacyMd5($stored)) {
            return true;
        }

        return is_string($stored) && Hash::needsRehash($stored);
    }
}
