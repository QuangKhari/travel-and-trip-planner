<?php

namespace App\Support;

/**
 * Mã dùng một lần theo thời gian (TOTP, RFC 6238 trên HOTP RFC 4226): HMAC-SHA1, 6 chữ số, bước 30 giây.
 * Khớp với Google Authenticator, Microsoft Authenticator, 1Password... Không cần gói ngoài.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Khóa bí mật ngẫu nhiên 160 bit, mã base32 (32 ký tự). */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $msg = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $h = hash_hmac('sha1', $msg, $key, true);
        $o = ord($h[19]) & 0x0F;
        $bin = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string) ($bin % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Bước thời gian khớp với mã (cho lệch đồng hồ ±$window bước), hoặc null.
     * $after: bước đã dùng lần trước; mã của bước đó hoặc cũ hơn bị từ chối (chống dùng lại mã vừa bị nhìn trộm).
     */
    public static function verify(string $secret, string $code, ?int $after = null, int $window = 1, ?int $time = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $now = self::step($time);
        for ($i = -$window; $i <= $window; $i++) {
            $s = $now + $i;
            if ($after !== null && $s <= $after) {
                continue;
            }
            if (hash_equals(self::code($secret, $s), $code)) {
                return $s;
            }
        }

        return null;
    }

    /** Liên kết otpauth:// để ứng dụng xác thực quét mã QR. */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=-]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $v = strpos(self::B32, $c);
            if ($v === false) {
                continue;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
