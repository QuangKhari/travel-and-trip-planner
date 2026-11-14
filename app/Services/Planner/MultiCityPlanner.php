<?php

namespace App\Services\Planner;

use App\Services\Planner\Route\HaversineProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tự tạo lịch chuyến NHIỀU thành phố: mỗi chặng (điểm đến + số ngày) xếp bằng ĐÚNG bộ xếp lịch một điểm đến (PlannerService::generate),
 * rồi ghép thành một chuyến:
 *   - ngày chuyển thành phố: đi lúc 08:00 bằng xe / máy bay (tự chọn theo khoảng cách, hoặc theo lựa chọn), giờ tới = giờ đi + thời gian di chuyển
 *     (+ thời gian ra khỏi sân bay / bến) -> chặng sau chỉ xếp từ giờ tới (arriveMin, cùng cơ chế với vé ngày đầu);
 *   - vé giữa chuyến được ghi vào "Cần đặt" (tbl_trip_external), nên khung giờ đang di chuyển được chặn trên lịch (TripLogistics blocks)
 *     và chỗ ở từng thành phố được nhắc ở phần Đặt chỗ;
 *   - ngân sách chia theo số ngày từng chặng.
 */
final class MultiCityPlanner
{
    public const MAX_LEGS = 5;
    public const DEPART_MIN = 8 * 60;

    public function __construct(private PlannerService $service) {}

    /** Chọn cách đi giữa hai thành phố. @return array{mode:string,km:float,min:int} */
    public static function hop(array $a, array $b, string $mode = 'auto'): array
    {
        $km = HaversineProvider::straightKm($a, $b) * 1.3;
        if ($mode === 'auto') {
            $mode = $km <= 300 ? 'bus' : 'flight';
        }
        $min = match ($mode) {
            'flight' => 60 + (int) round($km / 700 * 60),   // bay + lăn bánh; thời gian có mặt sớm / ra sân bay cộng ở PlannerRules::transfer
            'train' => (int) round($km / 45 * 60),
            'self' => (int) round($km / 50 * 60),
            default => (int) round($km / 45 * 60),            // xe khách đường dài Việt Nam ~45 km/h kể cả nghỉ
        };

        return ['mode' => $mode, 'km' => round($km, 1), 'min' => max(30, $min)];
    }

    /**
     * @param list<array{destinationId:int,days:int}> $legs
     * @return array{ok:bool,message?:string,result?:array,hops?:list<array>}
     */
    public function generate(array $legs, array $base, string $hopMode = 'auto'): array
    {
        if (count($legs) < 2 || count($legs) > self::MAX_LEGS) {
            return ['ok' => false, 'message' => 'Chuyến nhiều thành phố cần từ 2 đến ' . self::MAX_LEGS . ' điểm đến.'];
        }
        $total = array_sum(array_column($legs, 'days'));
        if ($total > TripPlanner::MAX_DAYS) {
            return ['ok' => false, 'message' => 'Tổng số ngày tối đa ' . TripPlanner::MAX_DAYS . '.'];
        }
        if (empty($base['startDate'])) {
            return ['ok' => false, 'message' => 'Chuyến nhiều thành phố cần ngày đi để tính ngày chuyển thành phố.'];
        }
        $dest = DB::table('tbl_destinations')->whereIn('destinationId', array_column($legs, 'destinationId'))->get()->keyBy('destinationId');
        $start = Carbon::parse($base['startDate']);
        $rules = PlannerRules::fromConfig();
        $merged = ['days' => [], 'unplaced' => [], 'warnings' => [], 'budget' => ['knownMin' => 0, 'knownMax' => 0, 'status' => null, 'message' => null]];
        $hops = [];
        $offset = 0;
        $first = null;
        $prev = null;
        foreach ($legs as $i => $leg) {
            $d = $dest[(int) $leg['destinationId']] ?? null;
            if (!$d) {
                return ['ok' => false, 'message' => 'Không tìm thấy điểm đến thứ ' . ($i + 1) . '.'];
            }
            $req = $base;
            unset($req['arriveAt'], $req['leaveAt'], $req['arriveMin'], $req['leaveMin']);
            $req['destinationId'] = (int) $d->destinationId;
            $req['days'] = (int) $leg['days'];
            $req['startDate'] = $start->copy()->addDays($offset)->toDateString();
            $req['month'] = (int) date('n', strtotime($req['startDate']));
            if (isset($base['budget']) && $base['budget'] !== null && $base['budget'] !== '') {
                $req['budget'] = round((float) $base['budget'] * (int) $leg['days'] / $total);
            }
            if ($i === 0) {
                foreach (['arriveAt', 'arriveMin', 'moveKind'] as $k) {
                    if (isset($base[$k])) {
                        $req[$k] = $base[$k];
                    }
                }
            }
            if ($i === count($legs) - 1) {
                foreach (['leaveAt', 'leaveMin'] as $k) {
                    if (isset($base[$k])) {
                        $req[$k] = $base[$k];
                    }
                }
            }
            if ($prev) {
                $h = self::hop([(float) $prev->latitude, (float) $prev->longitude], [(float) $d->latitude, (float) $d->longitude], $hopMode);
                $tr = $rules->transfer();
                $exit = (int) ($tr['exit'][$h['mode']] ?? 15);
                $before = (int) ($tr['before'][$h['mode']] ?? 30);
                $dep = $h['mode'] === 'flight' ? 10 * 60 : self::DEPART_MIN;   // bay: đi muộn hơn để kịp ra sân bay (có mặt sớm $before phút)
                $arr = $dep + $h['min'];
                $req['arriveMin'] = min(20 * 60, $arr + $exit);
                $hops[] = $h + ['from' => $prev->name, 'to' => $d->name, 'date' => $req['startDate'], 'dep' => $dep, 'arr' => $arr, 'before' => $before];
            }
            $r = $this->service->generate($req);
            if (empty($r['ok'])) {
                return ['ok' => false, 'message' => $d->name . ': ' . ($r['message'] ?? 'chưa xếp được lịch.')];
            }
            $first ??= $r;
            foreach ($r['plan']['days'] as $day) {
                $day['day'] = (int) $day['day'] + $offset;
                foreach ($day['items'] as &$it) {
                    $it['reasons'] = array_values(array_merge(['Chặng ' . ($i + 1) . ': ' . $d->name], $it['reasons'] ?? []));
                }
                unset($it);
                $merged['days'][] = $day;
            }
            $merged['unplaced'] = array_merge($merged['unplaced'], $r['plan']['unplaced'] ?? []);
            foreach ($r['plan']['warnings'] ?? [] as $w) {
                $merged['warnings'][] = $d->name . ': ' . $w;
            }
            $merged['budget']['knownMin'] += (float) ($r['plan']['budget']['knownMin'] ?? 0);
            $merged['budget']['knownMax'] += (float) ($r['plan']['budget']['knownMax'] ?? 0);
            $offset += (int) $leg['days'];
            $prev = $d;
        }
        $names = array_map(fn($l) => $dest[(int) $l['destinationId']]->name, $legs);
        $result = $first;
        $result['plan'] = $merged + $first['plan'];
        $result['request']['days'] = $total;
        $result['request']['startDate'] = $start->toDateString();
        $result['request']['month'] = (int) $start->month;
        $result['request']['budget'] = $base['budget'] ?? null;
        $result['destination'] = ['id' => (int) $legs[0]['destinationId'], 'name' => implode(' → ', $names)];

        return ['ok' => true, 'result' => $result, 'hops' => $hops];
    }

    /** Ghi vé giữa các thành phố vào "Cần đặt" của chuyến (chặn giờ đang đi trên lịch). */
    public static function recordHops(int $tripId, array $hops): void
    {
        if (!BookingPlan::ready()) {
            return;
        }
        $label = ['flight' => 'Vé máy bay', 'train' => 'Vé tàu', 'bus' => 'Vé xe khách', 'self' => 'Tự lái'];
        foreach ($hops as $h) {
            if (!in_array($h['mode'], BookingPlan::TRANSPORT, true)) {
                continue;
            }
            $date = Carbon::parse($h['date']);
            DB::table('tbl_trip_external')->insert(['tripId' => $tripId, 'kind' => $h['mode'], 'provider' => 'manual', 'simulated' => false,
                'title' => ($label[$h['mode']] ?? 'Vé') . ' ' . $h['from'] . ' → ' . $h['to'] . ' (ước tính ' . sprintf('%02d:%02d', intdiv($h['dep'], 60), $h['dep'] % 60) . ')',
                'payload' => json_encode(['manual' => true, 'fromPlanner' => true, 'multiCity' => true, 'km' => $h['km']]),
                'startAt' => $date->copy()->addMinutes($h['dep']), 'endAt' => $date->copy()->addMinutes($h['arr']), 'price' => 0, 'createdAt' => now(),
                'status' => 'planned', 'needBy' => Carbon::today()->max($date->copy()->subDays(7))->toDateString()]);
        }
    }
}
