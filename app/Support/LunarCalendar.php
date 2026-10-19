<?php

namespace App\Support;

/**
 * Đổi âm lịch <-> dương lịch Việt Nam (múi giờ UTC+7), thuật toán thiên văn của Hồ Ngọc Đức.
 * Hợp lệ cho khoảng 1800-2199. Không phụ thuộc Laravel nên test nhanh (tests/Unit/LunarCalendarTest.php).
 * Mọi ngày trả về dạng ['y'=>int,'m'=>int,'d'=>int].
 */
final class LunarCalendar
{
    private const TZ = 7;
    private const SYNODIC = 29.530588853;
    private const EPOCH = 2415021.076998695;

    /** Âm -> dương. Trả null nếu ngày không tồn tại (tháng 29 ngày mà đòi 30, tháng nhuận không có...). */
    public static function toSolar(int $day, int $month, int $year, bool $leap = false): ?array
    {
        if ($day < 1 || $day > 30 || $month < 1 || $month > 12) {
            return null;
        }
        if ($month < 11) {
            $a11 = self::lunarMonth11($year - 1);
            $b11 = self::lunarMonth11($year);
        } else {
            $a11 = self::lunarMonth11($year);
            $b11 = self::lunarMonth11($year + 1);
        }
        $k = (int) floor(0.5 + ($a11 - self::EPOCH) / self::SYNODIC);
        $off = $month - 11;
        if ($off < 0) {
            $off += 12;
        }
        if ($b11 - $a11 > 365) {                       // năm có tháng nhuận
            $leapOff = self::leapMonthOffset($a11);
            $leapMonth = $leapOff - 2;
            if ($leapMonth < 0) {
                $leapMonth += 12;
            }
            if ($leap && $month !== $leapMonth) {
                return null;
            }
            if ($leap || $off >= $leapOff) {
                $off += 1;
            }
        } elseif ($leap) {
            return null;                               // năm không nhuận
        }
        $start = self::newMoon($k + $off);
        $length = self::newMoon($k + $off + 1) - $start;
        if ($day > $length) {
            return null;
        }

        return self::fromJd($start + $day - 1);
    }

    /** Dương -> âm: ['d','m','y','leap'=>bool] */
    public static function toLunar(int $day, int $month, int $year): array
    {
        $dn = self::toJd($day, $month, $year);
        $k = (int) floor(($dn - self::EPOCH) / self::SYNODIC);
        $start = self::newMoon($k + 1);
        if ($start > $dn) {
            $start = self::newMoon($k);
        }
        $a11 = self::lunarMonth11($year);
        $b11 = $a11;
        if ($a11 >= $start) {
            $ly = $year;
            $a11 = self::lunarMonth11($year - 1);
        } else {
            $ly = $year + 1;
            $b11 = self::lunarMonth11($year + 1);
        }
        $ld = $dn - $start + 1;
        $diff = (int) floor(($start - $a11) / 29);
        $leap = false;
        $lm = $diff + 11;
        if ($b11 - $a11 > 365) {
            $leapDiff = self::leapMonthOffset($a11);
            if ($diff >= $leapDiff) {
                $lm = $diff + 10;
                if ($diff === $leapDiff) {
                    $leap = true;
                }
            }
        }
        if ($lm > 12) {
            $lm -= 12;
        }
        if ($lm >= 11 && $diff < 4) {
            $ly -= 1;
        }

        return ['d' => $ld, 'm' => $lm, 'y' => $ly, 'leap' => $leap];
    }

    private static function toJd(int $dd, int $mm, int $yy): int
    {
        $a = (int) floor((14 - $mm) / 12);
        $y = $yy + 4800 - $a;
        $m = $mm + 12 * $a - 3;
        $jd = $dd + (int) floor((153 * $m + 2) / 5) + 365 * $y + (int) floor($y / 4) - (int) floor($y / 100) + (int) floor($y / 400) - 32045;
        if ($jd < 2299161) {
            $jd = $dd + (int) floor((153 * $m + 2) / 5) + 365 * $y + (int) floor($y / 4) - 32083;
        }

        return $jd;
    }

    private static function fromJd(int $jd): array
    {
        if ($jd > 2299160) {
            $a = $jd + 32044;
            $b = (int) floor((4 * $a + 3) / 146097);
            $c = $a - (int) floor($b * 146097 / 4);
        } else {
            $b = 0;
            $c = $jd + 32082;
        }
        $d = (int) floor((4 * $c + 3) / 1461);
        $e = $c - (int) floor(1461 * $d / 4);
        $m = (int) floor((5 * $e + 2) / 153);

        return [
            'd' => $e - (int) floor((153 * $m + 2) / 5) + 1,
            'm' => $m + 3 - 12 * (int) floor($m / 10),
            'y' => $b * 100 + $d - 4800 + (int) floor($m / 10),
        ];
    }

    /** Ngày Julius của lần trăng mới thứ k (kể từ 1/1/1900). */
    private static function newMoon(int $k): int
    {
        $T = $k / 1236.85;
        $T2 = $T * $T;
        $T3 = $T2 * $T;
        $dr = M_PI / 180;
        $jd1 = 2415020.75933 + 29.53058868 * $k + 0.0001178 * $T2 - 0.000000155 * $T3;
        $jd1 += 0.00033 * sin((166.56 + 132.87 * $T - 0.009173 * $T2) * $dr);
        $M = 359.2242 + 29.10535608 * $k - 0.0000333 * $T2 - 0.00000347 * $T3;
        $Mpr = 306.0253 + 385.81691806 * $k + 0.0107306 * $T2 + 0.00001236 * $T3;
        $F = 21.2964 + 390.67050646 * $k - 0.0016528 * $T2 - 0.00000239 * $T3;
        $s = fn(float $x) => sin($dr * $x);
        $c1 = (0.1734 - 0.000393 * $T) * $s($M) + 0.0021 * $s(2 * $M)
            - 0.4068 * $s($Mpr) + 0.0161 * $s(2 * $Mpr) - 0.0004 * $s(3 * $Mpr)
            + 0.0104 * $s(2 * $F) - 0.0051 * $s($M + $Mpr) - 0.0074 * $s($M - $Mpr)
            + 0.0004 * $s(2 * $F + $M) - 0.0004 * $s(2 * $F - $M) - 0.0006 * $s(2 * $F + $Mpr)
            + 0.0010 * $s(2 * $F - $Mpr) + 0.0005 * $s(2 * $Mpr + $M);
        $dt = $T < -11
            ? 0.001 + 0.000839 * $T + 0.0002261 * $T2 - 0.00000845 * $T3 - 0.000000081 * $T * $T3
            : -0.000278 + 0.000265 * $T + 0.000262 * $T2;

        return (int) floor($jd1 + $c1 - $dt + 0.5 + self::TZ / 24);
    }

    /** Kinh độ Mặt Trời tại ngày jdn, chia thành 12 cung (0..11). */
    private static function sunLongitude(int $jdn): int
    {
        $T = ($jdn - 2451545.5 - self::TZ / 24) / 36525;
        $T2 = $T * $T;
        $dr = M_PI / 180;
        $M = 357.52910 + 35999.05030 * $T - 0.0001559 * $T2 - 0.00000048 * $T * $T2;
        $L0 = 280.46645 + 36000.76983 * $T + 0.0003032 * $T2;
        $dl = (1.914600 - 0.004817 * $T - 0.000014 * $T2) * sin($dr * $M)
            + (0.019993 - 0.000101 * $T) * sin($dr * 2 * $M) + 0.000290 * sin($dr * 3 * $M);
        $L = $L0 + $dl - 0.00569 - 0.00478 * sin((125.04 - 1934.136 * $T) * $dr);
        $L = $L * $dr;
        $L -= M_PI * 2 * floor($L / (M_PI * 2));

        return (int) floor($L / M_PI * 6);
    }

    /** Ngày bắt đầu tháng 11 âm của năm $year. */
    private static function lunarMonth11(int $year): int
    {
        $off = self::toJd(31, 12, $year) - 2415021;
        $k = (int) floor($off / self::SYNODIC);
        $nm = self::newMoon($k);
        if (self::sunLongitude($nm) >= 9) {
            $nm = self::newMoon($k - 1);
        }

        return $nm;
    }

    private static function leapMonthOffset(int $a11): int
    {
        $k = (int) floor(($a11 - self::EPOCH) / self::SYNODIC + 0.5);
        $i = 1;
        $arc = self::sunLongitude(self::newMoon($k + $i));
        do {
            $last = $arc;
            $i++;
            $arc = self::sunLongitude(self::newMoon($k + $i));
        } while ($arc !== $last && $i < 14);

        return $i - 1;
    }
}
