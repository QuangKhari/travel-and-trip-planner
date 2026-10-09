<?php

namespace App\Support;

use RuntimeException;

final class AesGcm
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;
    private const PREFIX = 'enc:v1:';

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public static function encrypt(?string $plain, string $context): ?string
    {
        if ($plain === null || $plain === '') return $plain;
        if (self::isEncrypted($plain)) return $plain;   // đã mã hóa thì giữ nguyên

        $kid = (string) config('travela.pii_current_key', 'k1');
        $key = self::key($kid);
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $context, self::TAG_LEN);
        if ($cipher === false || strlen($tag) !== self::TAG_LEN) {
            throw new RuntimeException('Mã hóa AES-GCM thất bại.');
        }

        return self::PREFIX . $kid . ':' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $stored, string $context): ?string
    {
        if ($stored === null || $stored === '' || !self::isEncrypted($stored)) {
            return $stored;   // dữ liệu cũ chưa mã hóa: trả nguyên văn
        }

        $parts = explode(':', substr($stored, strlen(self::PREFIX)), 2);
        if (count($parts) !== 2) throw new RuntimeException('Dữ liệu mã hóa sai định dạng.');

        [$kid, $b64] = $parts;
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Dữ liệu mã hóa sai định dạng.');
        }

        $iv     = substr($raw, 0, self::IV_LEN);
        $tag    = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key($kid), OPENSSL_RAW_DATA, $iv, $tag, $context);
        if ($plain === false) {
            throw new RuntimeException('Giải mã thất bại (sai khóa, sai context hoặc dữ liệu bị sửa).');
        }

        return $plain;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private static function key(string $kid): string
    {
        $raw = ((array) config('travela.pii_keys', []))[$kid] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException("Chưa cấu hình khóa '{$kid}' (DATA_ENCRYPTION_KEY trong .env).");
        }

        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : $raw;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException("Khóa '{$kid}' phải đúng 32 byte.");
        }

        return $key;
    }
}
