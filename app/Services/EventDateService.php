<?php

namespace App\Services;

use App\Support\LunarCalendar;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Khung ngày dương của lễ hội (tài liệu mục 4.5: "khung ngày dương 3 năm").
 * DB chỉ lưu QUY TẮC (isLunar, lunarMonth, lunarDay, solarMonth, solarDay, durationDays); ngày dương tính ở đây.
 *  - lunarMonth = null  -> lặp mỗi tháng âm (vd đêm rằm phố cổ Hội An 14 âm hằng tháng).
 *  - solarDay  = null   -> chỉ biết tháng (lễ hội không cố định ngày); trả approx = true, bắt đầu từ ngày 1.
 */
class EventDateService
{
    /**
     * @param object|array $event  hàng tbl_events (hoặc mảng cùng khóa)
     * @return list<array{start:string,end:string,approx:bool}> sắp theo thời gian, chỉ các đợt chưa kết thúc tính từ $from
     */
    public function upcoming($event, ?DateTimeInterface $from = null, int $limit = 3): array
    {
        $e = (object) $event;
        $from = $from ? DateTimeImmutable::createFromInterface($from)->setTime(0, 0) : new DateTimeImmutable('today');
        $fromY = (int) $from->format('Y');
        $dur = max(1, (int) ($e->durationDays ?? 1));
        $out = [];

        for ($y = $fromY - 1; $y <= $fromY + 3; $y++) {
            foreach ($this->startsInYear($e, $y) as [$start, $approx]) {
                $end = $start->modify('+' . ($dur - 1) . ' days');
                if ($end >= $from) {
                    $out[] = ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'approx' => $approx];
                }
            }
        }
        usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
        $out = array_values(array_unique($out, SORT_REGULAR));

        return array_slice($out, 0, $limit);
    }

    /** Các đợt bắt đầu của năm $y (năm âm với lễ hội âm lịch, năm dương với lễ hội dương lịch). */
    private function startsInYear(object $e, int $y): array
    {
        $res = [];
        if (!empty($e->isLunar)) {
            if (empty($e->lunarDay)) {
                return [];
            }
            $months = !empty($e->lunarMonth) ? [(int) $e->lunarMonth] : range(1, 12);
            foreach ($months as $m) {
                $d = LunarCalendar::toSolar((int) $e->lunarDay, $m, $y);
                if ($d) {
                    $res[] = [new DateTimeImmutable(sprintf('%04d-%02d-%02d', $d['y'], $d['m'], $d['d'])), false];
                }
            }

            return $res;
        }
        if (empty($e->solarMonth)) {
            return [];
        }
        $approx = empty($e->solarDay);
        $res[] = [new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, (int) $e->solarMonth, $approx ? 1 : (int) $e->solarDay)), $approx];

        return $res;
    }
}
