<?php

namespace App\Services\Planner\Health;

use App\Services\Planner\OpeningHours;
use App\Services\Planner\PlannerRules;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TripPlanner;

/**
 * Xếp giờ cho một ngày, CÙNG QUY TẮC với planner-schedule.js (TpSchedule.day()); mọi hằng số lấy từ PlannerRules:
 *  - bắt đầu từ day.start_min (08:00); mục có giờ cố định (startMin) giữ giờ đó; mục có buổi không bắt đầu trước đầu buổi; còn lại nối tiếp, làm tròn 5 phút;
 *  - thời lượng: durationMin của người dùng, không có thì mặc định theo loại (lưu trú tự nhập 30 phút);
 *  - di chuyển: đoạn giữa hai mục có vị trí liên tiếp (RouteService), bỏ đoạn cùng khu < 0,5 km;
 *  - xung đột: giờ cố định sớm hơn lúc mục trước kết thúc = trùng giờ; sớm hơn lúc kịp đi tới = không kịp di chuyển;
 *  - giờ mở cửa (khi biết thứ của ngày): mục tự xếp tới sớm thì chờ tới giờ mở; mỗi dòng có hours = closed|early|late|null;
 *  - thời gian trống: khoảng chờ trước một mục (do giờ cố định / đầu buổi) từ 15 phút trở lên.
 */
final class DaySchedule
{
    private PlannerRules $rules;

    /** @var list<array{item:array,start:int,end:int,dur:int,fixed:bool,travelMin:int,travelKm:float,waitMin:int,conflict:?string,hours:?string}> */
    public array $rows = [];
    public int $activityMin = 0;
    public int $travelMin = 0;
    public float $travelKm = 0.0;
    /** @var array<string,int> số chặng theo trạng thái số liệu tuyến (verified/estimated/unreachable/unknown) */
    public array $routeQuality = [];
    public string $routeConfidence = 'verified';
    public int $mealMin = 0;
    public int $bufferMin = 0;
    public int $freeMin = 0;
    public int $availableMin;
    public int $start;
    public int $end;
    public float $cost = 0.0;
    public int $outdoor = 0;
    public int $noPos = 0;

    /** @param ?int $dow thứ ISO (1-7) của ngày này; null = chuyến chưa có ngày đi (không kiểm tra giờ mở cửa) */
    public function __construct(public readonly int $day, array $items, RouteService $route, ?PlannerRules $rules = null, public readonly ?int $dow = null)
    {
        $this->rules = $rules ?? PlannerRules::fromConfig();
        $this->availableMin = $this->rules->availableMin();
        $clock = $this->rules->dayStart();
        $this->start = $clock;

        // đoạn đi tới mục có vị trí kế tiếp, gắn vào mục xuất phát
        $posIdx = [];
        foreach ($items as $i => $it) {
            if (($it['lat'] ?? null) !== null && ($it['lng'] ?? null) !== null) {
                $posIdx[] = $i;
            } else {
                $this->noPos++;
            }
        }
        $legAfter = [];
        $tt = $route->travelTime(array_map(fn($i) => [(float) $items[$i]['lat'], (float) $items[$i]['lng']], $posIdx));
        // T-05: chặng có phương tiện riêng (legMode của mục xuất phát) thì tính lại phút theo tốc độ phương tiện đó (ước tính)
        $defMode = $this->rules->route()['mode'];
        $travelSum = 0;
        foreach ($tt['legs'] as $l) {
            $from = $posIdx[$l['from']];
            $mode = $items[$from]['legMode'] ?? null;
            if ($mode !== null && $mode !== $defMode && (float) $l['km'] > 0 && ($m = $this->rules->legMinutes((float) $l['km'], (string) $mode)) !== null) {
                $l['min'] = $m;
                $l['mode'] = $mode;
            }
            $legAfter[$from] = $l;
            $travelSum += (int) $l['min'];
        }
        $tt['min'] = $tt['legs'] ? $travelSum : $tt['min'];

        $hasMeal = false;
        $prevEnd = null;
        foreach (array_values($items) as $i => $it) {
            $fixed = isset($it['startMin']) && $it['startMin'] !== null;
            $conflict = null;
            if ($fixed) {
                $start = (int) $it['startMin'];
                if ($prevEnd !== null && $start < $prevEnd - 5) {
                    $conflict = 'overlap';
                } elseif ($prevEnd !== null && $start < $clock - 5) {
                    $conflict = 'travel';
                }
            } else {
                $start = (int) (ceil($clock / 5) * 5);
                $ss = $this->rules->slotStart($it['slot'] ?? null);
                if ($ss !== null && $ss > $start) {
                    $start = $ss;
                }
                // tới trước giờ mở cửa: chờ tới khoảng mở đầu tiên còn đủ thời gian (giờ cố định thì không tự dời, chỉ cảnh báo)
                $fit = OpeningHours::fit($it['hours'] ?? null, $this->dow, $start, $this->durationOf($it));
                if ($fit !== null && $fit > $start) {
                    $start = $fit;
                }
            }
            $wait = $prevEnd === null ? 0 : max(0, $start - $clock);
            if ($wait >= 15) {
                $this->freeMin += $wait;
            }
            $dur = $this->durationOf($it);
            $leg = $legAfter[$i] ?? null;
            $this->rows[] = ['item' => $it, 'start' => $start, 'end' => $start + $dur, 'dur' => $dur, 'fixed' => $fixed,
                'travelMin' => $leg['min'] ?? 0, 'travelKm' => $leg['km'] ?? 0.0, 'routeStatus' => $leg['status'] ?? null, 'waitMin' => $wait, 'conflict' => $conflict,
                'hours' => OpeningHours::check($it['hours'] ?? null, $this->dow, $start, $start + $dur)];
            $this->activityMin += $dur;
            $this->cost += (float) ($it['cost'] ?? 0);
            if (($it['outdoor'] ?? null) === true) {
                $this->outdoor++;
            }
            if ($this->rules->kindOf($it) === 'meal') {
                $hasMeal = true;
            }
            $prevEnd = $start + $dur;
            $clock = $prevEnd + ($leg['min'] ?? 0);
        }
        $this->travelMin = $tt['min'];
        $this->travelKm = $tt['km'];
        $this->routeQuality = $tt['quality'] ?? [];
        $this->routeConfidence = $tt['confidence'] ?? 'verified';
        if ($this->rows) {
            $this->start = $this->rows[0]['start'];
        }
        $this->end = $this->rows ? end($this->rows)['end'] : $this->start;
        $o = $this->rules->occupied($this->activityMin, $this->travelMin, $hasMeal, (bool) $this->rows);
        $this->mealMin = $o['meal'];
        $this->bufferMin = $o['buffer'];
    }

    /** Thời gian đã dùng (phút) = hoạt động + di chuyển + bữa ăn chừa sẵn + đệm. */
    public function occupiedMin(): int
    {
        return $this->activityMin + $this->travelMin + $this->mealMin + $this->bufferMin;
    }

    public function load(): float
    {
        return $this->availableMin > 0 ? $this->occupiedMin() / $this->availableMin : 0.0;
    }

    /** ok (< 85%) | warn (85-100%) | bad (>= 100%). */
    public function level(): string
    {
        return $this->rules->level($this->load());
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** Thời lượng: người dùng nhập > lưu trú tự thêm 30 phút (nhận/trả phòng) > mặc định theo loại (defDur, giống trình duyệt). */
    public function durationOf(array $it): int
    {
        if (!empty($it['durationMin'])) {
            return (int) $it['durationMin'];
        }
        if ($this->rules->kindOf($it) === 'lodging') {
            return 30;
        }

        return !empty($it['defDur']) ? (int) $it['defDur'] : TripPlanner::defaultDuration($it);
    }

    public static function kindOf(array $it): ?string
    {
        return PlannerRules::fromConfig()->kindOf($it);
    }

    /**
     * Hợp đồng lịch một ngày, CÙNG cấu trúc với TpSchedule.contract() ở trình duyệt (PlannerContractTest so sánh hai bên).
     * @return array{day:int,rows:list<array{key:string,start:int,end:int,dur:int,fixed:bool,slot:string,travelMin:int,travelKm:float,waitMin:int,conflict:?string,hours:?string}>,activityMin:int,travelMin:int,travelKm:float,mealMin:int,bufferMin:int,freeMin:int,occupiedMin:int,availableMin:int,loadPct:int,level:string,start:int,end:int}
     */
    public function contract(): array
    {
        return [
            'day' => $this->day,
            'rows' => array_map(fn($r) => [
                'key' => (string) ($r['item']['key'] ?? (($r['item']['type'] ?? '') . ':' . ($r['item']['refId'] ?? $r['item']['name'] ?? ''))),
                'start' => $r['start'], 'end' => $r['end'], 'dur' => $r['dur'], 'fixed' => $r['fixed'], 'slot' => $this->rules->slotOf($r['start']),
                'travelMin' => $r['travelMin'], 'travelKm' => round($r['travelKm'], 1), 'waitMin' => $r['waitMin'], 'conflict' => $r['conflict'], 'hours' => $r['hours'],
            ], $this->rows),
            'activityMin' => $this->activityMin, 'travelMin' => $this->travelMin, 'travelKm' => round($this->travelKm, 1),
            'mealMin' => $this->mealMin, 'bufferMin' => $this->bufferMin, 'freeMin' => $this->freeMin,
            'occupiedMin' => $this->occupiedMin(), 'availableMin' => $this->availableMin,
            'loadPct' => (int) round($this->load() * 100), 'level' => $this->count() ? $this->level() : 'ok',
            'loadLabel' => $this->rules->loadLabel($this->count() ? $this->load() : 0.0),
            'start' => $this->start, 'end' => $this->end,
        ];
    }

    public static function hhmm(int $m): string
    {
        $m = (($m % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
}
