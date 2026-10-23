<?php

namespace App\Services\Rag;

/**
 * Vector nhúng: chuẩn hóa, đóng gói nhị phân (float32 little-endian) và tích vô hướng.
 * Mọi vector lưu vào DB đều đã chuẩn hóa độ dài 1, nên cosine = tích vô hướng.
 */
final class VectorMath
{
    /** @param list<float|int> $v */
    public static function normalize(array $v): array
    {
        $n = 0.0;
        foreach ($v as $x) {
            $n += $x * $x;
        }
        if ($n <= 0.0) {
            return array_map('floatval', $v);
        }
        $n = sqrt($n);

        return array_map(fn($x) => $x / $n, $v);
    }

    /** @param list<float|int> $v */
    public static function pack(array $v): string
    {
        return pack('g*', ...$v);
    }

    /** @return list<float> */
    public static function unpack(string $blob): array
    {
        if ($blob === '') {
            return [];
        }

        return array_values(unpack('g*', $blob));
    }

    /** @param list<float> $a @param list<float> $b */
    public static function dot(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i] * $b[$i];
        }

        return $s;
    }
}
