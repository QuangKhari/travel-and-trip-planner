<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class BookingPii
{
    public const FIELDS = ['fullName', 'email', 'phoneNumber', 'address'];

    private static function context(string $f): string
    {
        return 'tbl_booking.' . $f;
    }

    public static function encrypt(array $row): array
    {
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $row) && is_string($row[$f])) {
                $row[$f] = AesGcm::encrypt($row[$f], self::context($f));
            }
        }
        return $row;
    }

    public static function decrypt(?object $row): ?object
    {
        if ($row === null) return null;

        foreach (self::FIELDS as $f) {
            if (!isset($row->$f) || !is_string($row->$f)) continue;
            try {
                $row->$f = AesGcm::decrypt($row->$f, self::context($f));
            } catch (\Throwable $e) {
                Log::error('Giải mã PII thất bại: ' . $e->getMessage(), ['field' => $f, 'bookingId' => $row->bookingId ?? null]);
                $row->$f = '[không giải mã được]';
            }
        }
        return $row;
    }

    public static function decryptAll(iterable $rows): iterable
    {
        foreach ($rows as $row) self::decrypt($row);
        return $rows;
    }
}
