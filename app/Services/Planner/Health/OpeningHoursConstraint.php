<?php

namespace App\Services\Planner\Health;

use App\Services\Planner\OpeningHours;

/**
 * Giờ mở cửa (P1): mục rơi vào ngày đóng cửa, tới trước giờ mở, hoặc còn ở lại sau giờ đóng.
 * Chỉ kiểm tra khi chuyến có ngày đi (biết thứ trong tuần) và mục có dữ liệu giờ mở cửa.
 */
final class OpeningHoursConstraint implements Constraint
{
    public function group(): string
    {
        return 'time';
    }

    public function check(TripContext $ctx): array
    {
        $out = [];
        $known = 0;
        foreach ($ctx->days as $d) {
            foreach ($d->rows as $i => $r) {
                if ($d->dow !== null && !empty($r['item']['hours'])) {
                    $known++;
                }
                if ($r['hours'] === null) {
                    continue;
                }
                $name = '“' . $r['item']['name'] . '”';
                $open = OpeningHours::describe($r['item']['hours'] ?? null, $d->dow);
                $out[] = match ($r['hours']) {
                    'closed' => ['level' => 'bad', 'code' => 'hours.closed', 'day' => $d->day, 'index' => $i, 'action' => 'hours',
                        'text' => 'Ngày ' . $d->day . ': ' . $name . ' đóng cửa hôm đó.'],
                    'early' => ['level' => 'warn', 'code' => 'hours.early', 'day' => $d->day, 'index' => $i, 'action' => 'hours',
                        'text' => 'Ngày ' . $d->day . ': tới ' . $name . ' lúc ' . DaySchedule::hhmm($r['start']) . ' nhưng giờ mở cửa ' . $open . '.'],
                    default => ['level' => 'warn', 'code' => 'hours.late', 'day' => $d->day, 'index' => $i, 'action' => 'hours',
                        'text' => 'Ngày ' . $d->day . ': ' . $name . ' (' . DaySchedule::hhmm($r['start']) . '–' . DaySchedule::hhmm($r['end']) . ') quá giờ mở cửa ' . $open . '.'],
                };
            }
        }
        // chưa có dữ liệu giờ mở cửa KHÔNG có nghĩa là đang mở: chỉ là chưa kiểm tra được (INFO)
        $unknown = 0;
        foreach ($ctx->days as $d) {
            if ($d->dow === null) {
                continue;
            }
            foreach ($d->rows as $r) {
                $h = $r['item']['hours'] ?? null;
                if (in_array($r['item']['type'] ?? '', \App\Services\Planner\OpeningHours::TYPES, true) && (!$h || !array_key_exists((string) $d->dow, $h))) {
                    $unknown++;
                }
            }
        }
        if ($unknown) {
            $out[] = ['level' => 'info', 'code' => 'hours.unknown', 'text' => $unknown . ' địa điểm chưa có dữ liệu giờ mở cửa nên chưa kiểm tra được giờ.'];
        }
        if (!array_filter($out, fn($x) => $x['level'] !== 'info') && $known) {
            $out[] = ['level' => 'ok', 'code' => 'hours.ok', 'text' => 'Các mục có giờ mở cửa đều được xếp trong giờ mở.'];
        }

        return $out;
    }
}
