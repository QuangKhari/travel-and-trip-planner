<?php

namespace App\Services\Planner;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chỗ ở và đi / về của chuyến, dùng để xếp lịch từng ngày cho thật:
 *  - đêm nào ngủ ở đâu (đặt chỗ khách sạn có vị trí, kể cả đang "cần đặt"): ngày d xuất phát từ nơi ngủ đêm trước và về nơi ngủ đêm d;
 *  - vé đi (khởi hành từ ngày đầu trở về trước): ngày tới nơi không xếp hoạt động trước giờ tới + thời gian ra ga / sân bay;
 *  - vé về (khởi hành ngày cuối): mọi hoạt động phải xong trước giờ khởi hành - thời gian có mặt sớm.
 *
 * Dữ liệu thô (raw) gửi nguyên cho trình duyệt; dayContext() là CÙNG quy tắc với TpSchedule.dayCtx() trong planner-schedule.js
 * (PlannerContractTest so sánh) vì người dùng có thể đổi ngày đi / số ngày trên trang trước khi lưu.
 * Mục đi lại / nhận phòng (type transport, lưu trú) không bị ràng buộc giờ tới / giờ đi: chính chúng là chuyến bay, nhận phòng.
 */
final class TripLogistics
{
    public static function ready(): bool
    {
        return Schema::hasColumn('tbl_trip_external', 'latitude') && Schema::hasColumn('tbl_trip_external', 'status');
    }

    /**
     * @return array{stays:list<array{id:int,name:string,lat:?float,lng:?float,in:string,out:string,status:string}>,
     *               moves:list<array{id:int,kind:string,name:string,dep:string,arr:?string,status:string}>}
     */
    public function raw(int $tripId): array
    {
        if (!self::ready()) {
            return ['stays' => [], 'moves' => [], 'anchors' => []];
        }
        $rows = DB::table('tbl_trip_external')->where('tripId', $tripId)->where(fn($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->orderBy('startAt')->get();
        $bp = app(BookingPlan::class);
        $stays = [];
        $moves = [];
        $anchors = [];
        foreach ($rows as $r) {
            if (($r->status ?? 'booked') === 'booked') {
                // mục lịch tạo kèm đặt chỗ: đổi ngày đi thì trình duyệt đặt lại đúng ngày theo ngày thật (cùng BookingPlan::realignRows)
                $anchors[] = ['label' => \App\Http\Controllers\clients\TravelController::itemLabel($r), 'date' => Carbon::parse($r->startAt)->toDateString()];
            }
            if ($r->kind === 'hotel') {
                $in = Carbon::parse($r->startAt)->startOfDay();
                $stays[] = ['id' => (int) $r->externalId, 'name' => self::short($r->title), 'lat' => $r->latitude !== null ? (float) $r->latitude : null,
                    'lng' => $r->longitude !== null ? (float) $r->longitude : null, 'in' => $in->toDateString(),
                    'out' => $in->copy()->addDays($bp->nights($r))->toDateString(), 'status' => $r->status ?? 'booked'];
            } elseif (in_array($r->kind, BookingPlan::TRANSPORT, true)) {
                $moves[] = ['id' => (int) $r->externalId, 'kind' => $r->kind, 'name' => self::short($r->title), 'dep' => Carbon::parse($r->startAt)->format('Y-m-d H:i'),
                    'arr' => !empty($r->endAt) ? Carbon::parse($r->endAt)->format('Y-m-d H:i') : null, 'status' => $r->status ?? 'booked'];
            }
        }

        return ['stays' => $stays, 'moves' => $moves, 'anchors' => $anchors];
    }

    /**
     * Nhãn (chữ thường) của các mục lịch tạo kèm đặt chỗ: "Nhận phòng …", "Bay …", "Khởi hành …", "Tàu …".
     * Đây là thông tin riêng (số hiệu chuyến bay, khách sạn đang ở): không đưa lên Cộng đồng, không vào bản sao của người khác,
     * và chỉ hiện ở bản chia sẻ khi chủ chuyến bật "hiện đặt chỗ".
     *
     * @return array<string,true>
     */
    public static function bookingLabels(int $tripId): array
    {
        if (!Schema::hasTable('tbl_trip_external')) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbl_trip_external')->where('tripId', $tripId)->get() as $x) {
            $out[mb_strtolower(\App\Http\Controllers\clients\TravelController::itemLabel($x))] = true;
        }

        return $out;
    }

    /** Bỏ các mục lịch của đặt chỗ khỏi payload (TripPayloadBuilder::build). */
    public static function stripBookingItems(array $payload, int $tripId): array
    {
        $labels = self::bookingLabels($tripId);
        if (!$labels) {
            return $payload;
        }
        $keep = fn(array $it) => !isset($labels[mb_strtolower((string) ($it['name'] ?? ''))]) || !in_array($it['type'] ?? '', TripPlanner::CUSTOM_TYPES, true);
        foreach ($payload['days'] as &$d) {
            $d['items'] = array_values(array_filter($d['items'], $keep));
        }
        unset($d);
        $payload['unassigned'] = array_values(array_filter($payload['unassigned'], $keep));

        return $payload;
    }

    /** Lọc các dòng tbl_trip_items (khi chép chuyến cho người khác). @param iterable<object> $rows */
    public static function withoutBookingRows(iterable $rows, int $tripId): array
    {
        $labels = self::bookingLabels($tripId);
        $out = [];
        foreach ($rows as $r) {
            if ($labels && in_array($r->refType, TripPlanner::CUSTOM_TYPES, true) && isset($labels[mb_strtolower((string) $r->label)])) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /** Tên ngắn: bỏ phần "(2 đêm)" của khách sạn tìm qua API. */
    private static function short(string $t): string
    {
        return trim(preg_replace('/\s*\(\d+\s*đêm\)\s*$/u', '', $t) ?? $t);
    }

    /**
     * Ngữ cảnh một ngày. Cùng quy tắc với TpSchedule.dayCtx (trình duyệt).
     *
     * @param array{stays:list<array>,moves:list<array>} $raw
     * @return array{from:?array,to:?array,arrive:?int,arriveText:?string,leave:?int,leaveText:?string}
     */
    public static function dayContext(array $raw, ?string $startDate, int $day, int $days, ?PlannerRules $rules = null): array
    {
        $out = ['from' => null, 'to' => null, 'arrive' => null, 'arriveText' => null, 'leave' => null, 'leaveText' => null];
        if (!$startDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return $out;
        }
        $tr = ($rules ?? PlannerRules::fromConfig())->transfer();
        $start = self::addDays($startDate, 0);
        $date = self::addDays($startDate, $day - 1);
        $prev = self::addDays($startDate, $day - 2);
        $last = self::addDays($startDate, max(1, $days) - 1);

        foreach ($raw['stays'] ?? [] as $s) {
            if ($s['lat'] === null || $s['lng'] === null) {
                continue;
            }
            $pt = ['name' => $s['name'], 'lat' => (float) $s['lat'], 'lng' => (float) $s['lng']];
            if ($out['from'] === null && $s['in'] <= $prev && $prev < $s['out']) {
                $out['from'] = $pt;   // đêm trước ngủ ở đây
            }
            if ($out['to'] === null && $s['in'] <= $date && $date < $s['out']) {
                $out['to'] = $pt;     // đêm nay ngủ ở đây
            }
        }

        foreach ($raw['moves'] ?? [] as $m) {
            $kind = $m['kind'];
            $depDate = substr($m['dep'], 0, 10);
            $depMin = self::minOf($m['dep']);
            // vé về: khởi hành ngày cuối (chuyến 1 ngày: vé muộn hơn trong ngày); vé đi: khởi hành từ ngày đầu trở về trước
            $isBack = $depDate === $last && ($depDate > $start || self::isBack($m, $raw));
            if (!$isBack && $depDate <= $start) {
                $est = $m['arr'] === null;
                if (!$est) {
                    $arrDate = substr($m['arr'], 0, 10);
                    $arrMin = self::minOf($m['arr']);
                } else {
                    $arrDate = $depDate;
                    $arrMin = $depMin + ($tr['typical'][$kind] ?? 0);
                }
                if ($arrDate === $date && $arrMin < 1440) {
                    $ready = $arrMin + ($tr['exit'][$kind] ?? 0);
                    if ($out['arrive'] === null || $ready > $out['arrive']) {
                        $out['arrive'] = $ready;
                        $out['arriveText'] = $m['name'] . ($est ? ' tới khoảng ' : ' tới ') . self::hhmm($arrMin) . ', cộng ' . ($tr['exit'][$kind] ?? 0) . ' phút ra khỏi ' . self::station($kind);
                    }
                }
            }
            if ($isBack && $depDate === $date) {
                $need = $depMin - ($tr['before'][$kind] ?? 0);
                if ($out['leave'] === null || $need < $out['leave']) {
                    $out['leave'] = max(0, $need);
                    $out['leaveText'] = $m['name'] . ' khởi hành ' . self::hhmm($depMin) . ', cần có mặt ở ' . self::station($kind) . ' trước ' . ($tr['before'][$kind] ?? 0) . ' phút';
                }
            }
        }

        return $out;
    }

    /** Chuyến 1 ngày: vé khởi hành muộn nhất trong ngày (không phải vé đầu tiên) là vé về. */
    private static function isBack(array $m, array $raw): bool
    {
        $same = array_values(array_filter($raw['moves'] ?? [], fn($x) => substr($x['dep'], 0, 10) === substr($m['dep'], 0, 10)));
        usort($same, fn($a, $b) => strcmp($a['dep'], $b['dep']));

        return count($same) > 1 && end($same)['id'] === $m['id'];
    }

    private static function station(string $kind): string
    {
        return ['flight' => 'sân bay', 'train' => 'ga', 'bus' => 'bến xe'][$kind] ?? 'bến';
    }

    private static function addDays(string $ymd, int $n): string
    {
        return date('Y-m-d', strtotime($ymd . ' 00:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' days'));
    }

    private static function minOf(string $dt): int
    {
        return (int) substr($dt, 11, 2) * 60 + (int) substr($dt, 14, 2);
    }

    private static function hhmm(int $m): string
    {
        return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60);
    }

    /**
     * Chặng từ chỗ ở tới mục đầu tiên (khi mục đầu tiên có vị trí, ngày không có giờ tới nơi) và từ mục có vị trí cuối cùng về chỗ ở.
     * MỘT chỗ tính cho DaySchedule (sức khỏe) và TripPayloadBuilder (km / phút đi lại của ngày); trình duyệt: TpSchedule.day().
     *
     * @param list<array> $items
     * @return array{start:?array{min:int,km:float,name:string},end:?array{min:int,km:float,name:string}}
     */
    public static function baseLegs(array $items, ?array $ctx, Route\RouteService $route): array
    {
        $out = ['start' => null, 'end' => null];
        if (!$ctx || !$items) {
            return $out;
        }
        $items = array_values($items);
        $has = fn($x) => ($x['lat'] ?? null) !== null && ($x['lng'] ?? null) !== null;
        $leg = function (array $a, array $b) use ($route) {
            return $route->travelTime([[(float) $a['lat'], (float) $a['lng']], [(float) $b['lat'], (float) $b['lng']]])['legs'][0] ?? null;
        };
        if (!empty($ctx['from']) && !isset($ctx['arrive']) && $has($items[0])) {
            if ($l = $leg($ctx['from'], $items[0])) {
                $out['start'] = ['min' => (int) $l['min'], 'km' => (float) $l['km'], 'name' => (string) $ctx['from']['name']];
            }
        }
        $pos = array_values(array_filter($items, $has));
        if (!empty($ctx['to']) && $pos) {
            if ($l = $leg(end($pos), $ctx['to'])) {
                $out['end'] = ['min' => (int) $l['min'], 'km' => (float) $l['km'], 'name' => (string) $ctx['to']['name']];
            }
        }

        return $out;
    }

    /** Mục không bị ràng buộc giờ tới / giờ đi: chính là chặng đi lại hoặc nhận / trả phòng. */
    public static function exempt(array $it, PlannerRules $rules): bool
    {
        $k = $rules->kindOf($it);

        return ($it['type'] ?? null) === 'transport' || $k === 'transport' || $k === 'lodging';
    }
}
