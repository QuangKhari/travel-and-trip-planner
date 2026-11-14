<?php

namespace App\Services\Planner;

use App\Support\LunarCalendar;

/**
 * Ngày nghỉ lễ ở Việt Nam dùng cho giờ mở cửa "PH" (public holiday) của OpenStreetMap.
 * Dương lịch: 1/1, 30/4, 1/5, 1/9–2/9. Âm lịch: 30 Tết (ngày cuối năm âm) và mùng 1–3 Tết, Giỗ Tổ 10/3.
 * Số ngày nghỉ bù / kéo dài do nhà nước công bố hằng năm thì không đoán được: admin nhập bằng quy tắc "đóng cửa" của điểm đến.
 */
final class Holidays
{
    private const SOLAR = ['01-01' => 'Tết Dương lịch', '04-30' => 'Ngày Giải phóng miền Nam', '05-01' => 'Quốc tế Lao động',
        '09-01' => 'Nghỉ lễ Quốc khánh', '09-02' => 'Quốc khánh'];

    /** @var array<int,array<string,string>> */
    private static array $cache = [];

    public static function name(string $ymd): ?string
    {
        $y = (int) substr($ymd, 0, 4);
        if ($y < 1900 || $y > 2190) {
            return null;
        }
        if (isset(self::SOLAR[substr($ymd, 5, 5)])) {
            return self::SOLAR[substr($ymd, 5, 5)];
        }

        return self::lunarOf($y)[$ymd] ?? null;
    }

    /** @return array<string,string> ngày (Y-m-d) => tên, các ngày lễ âm lịch rơi vào năm dương $y */
    private static function lunarOf(int $y): array
    {
        if (isset(self::$cache[$y])) {
            return self::$cache[$y];
        }
        $out = [];
        $fmt = fn(?array $d) => $d ? sprintf('%04d-%02d-%02d', $d['y'], $d['m'], $d['d']) : null;
        foreach ([$y, $y + 1] as $ly) {   // Tết âm của năm âm $ly có thể rơi vào cuối tháng 1 / tháng 2 năm dương $ly
            $t1 = LunarCalendar::toSolar(1, 1, $ly);
            if ($t1) {
                $first = new \DateTimeImmutable($fmt($t1));
                $out[$first->modify('-1 day')->format('Y-m-d')] = 'Giao thừa / 30 Tết';
                foreach ([1, 2, 3] as $i) {
                    $out[$first->modify('+' . ($i - 1) . ' days')->format('Y-m-d')] = 'Mùng ' . $i . ' Tết';
                }
            }
            if ($g = $fmt(LunarCalendar::toSolar(10, 3, $ly))) {
                $out[$g] = 'Giỗ Tổ Hùng Vương';
            }
        }

        return self::$cache[$y] = array_filter($out, fn($k) => str_starts_with($k, (string) $y), ARRAY_FILTER_USE_KEY);
    }
}
