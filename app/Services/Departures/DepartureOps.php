<?php

namespace App\Services\Departures;

use App\Modules\Commerce\CommerceReader;
use App\Services\DepartureService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng điều hành khởi hành (phân hệ Tour Ops): MỘT màn hình cho các ngày khởi hành trong N ngày tới (và đang đi), gom dữ liệu đã có:
 * số khách / tối thiểu, tiền đã thu / còn phải thu (qua CommerceReader, không đọc thẳng bảng đơn), giữ chỗ chưa trả, hướng dẫn viên,
 * nguồn lực (xe, phòng...), sự cố đang mở, phương án xử lý thiếu khách, điểm danh. Mỗi dòng có cờ đỏ / vàng và thứ tự ưu tiên xử lý.
 */
final class DepartureOps
{
    public function __construct(private CommerceReader $commerce) {}

    /** @return array{rows:list<array>, counts:array{red:int,amber:int,ok:int}, from:string, to:string} */
    public function board(int $days = 14, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $to = $today->copy()->addDays($days);
        // đang đi: khởi hành trước hôm nay nhưng chưa về (tối đa 30 ngày trước)
        $deps = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->whereBetween('a.departureDate', [$today->copy()->subDays(30)->toDateString(), $to->toDateString()])
            ->where('a.status', '!=', 'cancelled')->orderBy('a.departureDate')
            ->get(['a.*', 't.title', 't.durationDays', 't.startDate as tStart', 't.endDate as tEnd']);
        $ids = $deps->pluck('availabilityId')->all();
        $guides = Schema::hasTable('tbl_guide_links') ? DB::table('tbl_guide_links')->whereIn('availabilityId', $ids)->where(fn($q) => $q->whereNull('expiresOn')->orWhere('expiresOn', '>=', $today->toDateString()))
            ->get(['availabilityId', 'guideName'])->groupBy('availabilityId') : collect();
        $res = Schema::hasTable('tbl_resource_allocations') ? DB::table('tbl_resource_allocations')->where('ownerType', 'departure')->whereIn('ownerId', $ids)->where('status', '!=', 'cancelled')
            ->selectRaw('ownerId, COUNT(*) n')->groupBy('ownerId')->pluck('n', 'ownerId') : collect();
        $inc = Schema::hasTable('tbl_tour_incidents') ? DB::table('tbl_tour_incidents')->whereIn('availabilityId', $ids)->whereIn('status', ['open', 'handling'])
            ->get(['incidentId', 'availabilityId', 'severity', 'title'])->groupBy('availabilityId') : collect();
        $rescue = Schema::hasTable('tbl_departure_rescue_plans') ? DB::table('tbl_departure_rescue_plans')->whereIn('availabilityId', $ids)->whereIn('status', ['pending', 'ready'])
            ->get(['planId', 'availabilityId', 'status', 'responseDeadline'])->keyBy('availabilityId') : collect();
        $checks = Schema::hasTable('tbl_departure_checkins') ? DB::table('tbl_departure_checkins')->whereIn('availabilityId', $ids)->where('present', 1)
            ->selectRaw('availabilityId, COUNT(DISTINCT bookingId) n')->groupBy('availabilityId')->pluck('n', 'availabilityId') : collect();

        $rows = [];
        foreach ($deps as $d) {
            $len = DepartureService::durationDays((object) ['durationDays' => $d->durationDays, 'startDate' => $d->tStart, 'endDate' => $d->tEnd]);
            $end = DepartureService::endDate($d->departureDate, $len);
            if ($end < $today->toDateString()) {
                continue;   // đã về
            }
            $in = (int) Carbon::parse($d->departureDate)->startOfDay()->diffInDays($today, false) * -1;
            $ongoing = $in <= 0;
            $m = $this->commerce->departureMoney((int) $d->availabilityId);
            $seats = (int) $d->bookedSlots + (int) $d->heldSlots;
            $min = (int) ($d->minParticipants ?? 1);
            $flags = [];
            $flag = function (string $tone, string $text, ?string $link = null) use (&$flags) {
                $flags[] = ['tone' => $tone, 'text' => $text, 'link' => $link];
            };
            if (!$ongoing && $seats > 0 && $seats < $min) {
                $flag($in <= 7 ? 'red' : 'amber', 'Thiếu khách: ' . $seats . '/' . $min . ' tối thiểu', route('admin.departures.rescue'));
            }
            if ($m['unpaidHolds'] > 0) {
                $flag($in <= 3 ? 'red' : 'amber', $m['unpaidHolds'] . ' đơn giữ chỗ chưa thanh toán', route('admin.booking.departures'));
            }
            if ($m['due'] > 0 && $in <= 3) {
                $flag('amber', 'Còn phải thu ' . number_format($m['due'], 0, ',', '.') . 'đ');
            }
            if ($m['people'] > 0 && empty($guides[$d->availabilityId]) && $in <= 7) {
                $flag($in <= 2 ? 'red' : 'amber', 'Chưa giao hướng dẫn viên');
            }
            if ($m['people'] > 0 && empty($res[$d->availabilityId]) && $in <= 7) {
                $flag('amber', 'Chưa xếp nguồn lực (xe, phòng…)', route('admin.resources'));
            }
            foreach ($inc[$d->availabilityId] ?? [] as $i) {
                $flag(in_array($i->severity, ['high', 'critical'], true) ? 'red' : 'amber', 'Sự cố: ' . $i->title, route('admin.incidents.show', ['id' => $i->incidentId]));
            }
            if ($p = $rescue[$d->availabilityId] ?? null) {
                $flag('amber', 'Đang chờ khách trả lời phương án #' . $p->planId . ($p->responseDeadline ? ' (hạn ' . Carbon::parse($p->responseDeadline)->format('d/m H:i') . ')' : ''), route('admin.departures.rescue'));
            }
            if ($ongoing && $m['people'] > 0 && (int) ($checks[$d->availabilityId] ?? 0) < $m['bookings']) {
                $flag('amber', 'Điểm danh ' . (int) ($checks[$d->availabilityId] ?? 0) . '/' . $m['bookings'] . ' đơn');
            }
            $tone = collect($flags)->contains('tone', 'red') ? 'red' : ($flags ? 'amber' : 'ok');
            $rows[] = ['availabilityId' => (int) $d->availabilityId, 'tourId' => (int) $d->tourId, 'title' => $d->title, 'date' => $d->departureDate, 'end' => $end,
                'inDays' => $in, 'ongoing' => $ongoing, 'status' => $d->status, 'seats' => $seats, 'total' => (int) $d->totalSlots, 'min' => $min,
                'people' => $m['people'], 'bookings' => $m['bookings'], 'paid' => $m['paid'], 'due' => $m['due'],
                'guide' => collect($guides[$d->availabilityId] ?? [])->pluck('guideName')->filter()->implode(', ') ?: null,
                'resources' => (int) ($res[$d->availabilityId] ?? 0), 'flags' => $flags, 'tone' => $tone];
        }
        usort($rows, fn($a, $b) => [['red' => 0, 'amber' => 1, 'ok' => 2][$a['tone']], $a['date']] <=> [['red' => 0, 'amber' => 1, 'ok' => 2][$b['tone']], $b['date']]);
        $c = array_count_values(array_column($rows, 'tone'));

        return ['rows' => $rows, 'counts' => ['red' => $c['red'] ?? 0, 'amber' => $c['amber'] ?? 0, 'ok' => $c['ok'] ?? 0],
            'from' => $today->toDateString(), 'to' => $to->toDateString()];
    }
}
