<?php

namespace App\Services\Planner\Health;

/** Thời gian: trùng giờ, không kịp di chuyển giữa hai mục, kết thúc quá muộn. */
final class TimeConstraint implements Constraint
{
    public function group(): string
    {
        return 'time';
    }

    public function check(TripContext $ctx): array
    {
        $out = [];
        $late = \App\Services\Planner\PlannerRules::fromConfig()->dayEnd();
        foreach ($ctx->days as $d) {
            $prev = null;
            foreach ($d->rows as $i => $r) {
                if ($r['conflict'] === 'overlap') {
                    $out[] = ['level' => 'bad', 'code' => 'time.overlap', 'day' => $d->day, 'index' => $i, 'action' => 'unfix',
                        'text' => 'Ngày ' . $d->day . ': “' . $r['item']['name'] . '” đặt lúc ' . DaySchedule::hhmm($r['start']) . ' nhưng “' . $prev['item']['name'] . '” đến ' . DaySchedule::hhmm($prev['end']) . ' mới xong.'];
                } elseif ($r['conflict'] === 'travel') {
                    $out[] = ['level' => 'warn', 'code' => 'time.travel', 'day' => $d->day, 'index' => $i, 'action' => 'unfix',
                        'text' => 'Ngày ' . $d->day . ': không kịp đi từ “' . $prev['item']['name'] . '” tới “' . $r['item']['name'] . '” trước ' . DaySchedule::hhmm($r['start']) . ' (cần ' . $prev['travelMin'] . ' phút di chuyển).'];
                }
                if ($r['conflict'] === 'arrival') {
                    $out[] = ['level' => 'bad', 'code' => 'time.arrival', 'day' => $d->day, 'index' => $i, 'action' => 'unfix',
                        'text' => 'Ngày ' . $d->day . ': “' . $r['item']['name'] . '” đặt lúc ' . DaySchedule::hhmm($r['start']) . ' nhưng khoảng ' . DaySchedule::hhmm((int) $d->ctx['arrive'])
                            . ' bạn mới tới nơi' . (!empty($d->ctx['arriveText']) ? ' (' . $d->ctx['arriveText'] . ')' : '') . '.'];
                } elseif ($r['conflict'] === 'departure') {
                    $out[] = ['level' => 'bad', 'code' => 'time.departure', 'day' => $d->day, 'index' => $i, 'action' => 'move',
                        'text' => 'Ngày ' . $d->day . ': “' . $r['item']['name'] . '” (' . DaySchedule::hhmm($r['start']) . '–' . DaySchedule::hhmm($r['end']) . ') chưa xong thì đã tới lúc phải rời đi '
                            . DaySchedule::hhmm((int) $d->ctx['leave']) . (!empty($d->ctx['leaveText']) ? ' (' . $d->ctx['leaveText'] . ')' : '') . '.'];
                }
                $prev = $r;
            }
            if ($d->count() && $d->end > $late) {
                $out[] = ['level' => 'warn', 'code' => 'time.late', 'day' => $d->day, 'action' => 'balance',
                    'text' => 'Ngày ' . $d->day . ' kết thúc khoảng ' . DaySchedule::hhmm($d->end) . ', khá muộn.'];
            }
        }
        if (!$out) {
            $out[] = ['level' => 'ok', 'code' => 'time.ok', 'text' => 'Không có trùng giờ hay thiếu thời gian di chuyển.'];
        }

        return $out;
    }
}
