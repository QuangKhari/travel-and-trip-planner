<?php

namespace App\Services\Planner;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đặt chỗ NGOÀI Travela (khách sạn, vé máy bay, tàu, xe khách): Travela không bán các dịch vụ này nên không đặt hộ.
 * Việc của hệ thống là: (1) chỉ ra chuyến còn THIẾU gì (đêm chưa có chỗ ở, chưa có vé đi / về), (2) mở đúng trang đặt của
 * đối tác với ngày, số khách điền sẵn, (3) giữ danh sách "cần đặt" có hạn đặt và nhắc qua email, (4) lưu xác nhận khi đã đặt
 * (mã, tệp) rồi tự đưa giờ nhận phòng / giờ đi vào lịch, (5) hủy thì gỡ khỏi lịch nhưng giữ dấu vết.
 *
 * tbl_trip_external.status: planned (cần đặt) | booked (đã đặt) | cancelled (đã hủy).
 */
final class BookingPlan
{
    public const KINDS = ['hotel' => 'Chỗ ở', 'flight' => 'Vé máy bay', 'train' => 'Vé tàu', 'bus' => 'Vé xe khách', 'ticket' => 'Vé tham quan'];
    public const TRANSPORT = ['flight', 'train', 'bus'];

    public static function ready(): bool
    {
        return Schema::hasColumn('tbl_trip_external', 'status');
    }

    /**
     * @return array{needs:list<array>,planned:list<array>,booked:list<array>,cancelled:int,skip:array<string,bool>,phase:string}
     */
    public function forTrip(object $trip): array
    {
        $rows = DB::table('tbl_trip_external')->where('tripId', $trip->tripId)->orderBy('startAt')->get();
        $skip = array_fill_keys((array) json_decode((string) ($trip->bookingSkip ?? ''), true) ?: [], true);
        $dest = $trip->destinationId ? DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->first(['name', 'province', 'latitude', 'longitude']) : null;
        $city = $dest ? ($dest->name ?: $dest->province) : '';
        $active = $rows->filter(fn($r) => ($r->status ?? 'booked') !== 'cancelled');
        $booked = $active->filter(fn($r) => ($r->status ?? 'booked') === 'booked');
        $needs = [];

        if ($trip->startDate) {
            $start = Carbon::parse($trip->startDate)->startOfDay();
            $days = max(1, (int) $trip->days);
            $end = $start->copy()->addDays($days - 1);
            $adults = max(1, (int) ($trip->groupSize ?? 1));

            // ---- chỗ ở: đêm k = ngày (start + k - 1), cần cho mọi đêm trừ đêm cuối (ngày về)
            if ($days >= 2 && empty($skip['stay'])) {
                $covered = [];
                foreach ($booked->where('kind', 'hotel') as $h) {
                    $in = Carbon::parse($h->startAt)->startOfDay();
                    $nights = $this->nights($h);
                    for ($i = 0; $i < $nights; $i++) {
                        $covered[$in->copy()->addDays($i)->toDateString()] = true;
                    }
                }
                $gap = [];
                for ($k = 0; $k < $days - 1; $k++) {
                    $d = $start->copy()->addDays($k)->toDateString();
                    if (empty($covered[$d])) {
                        $gap[] = $d;
                    }
                }
                foreach ($this->ranges($gap) as [$a, $n]) {
                    $in = Carbon::parse($a);
                    $needs[] = ['key' => 'stay', 'kind' => 'hotel', 'title' => 'Chưa có chỗ ở ' . ($n === 1 ? 'đêm ' . $in->format('d/m') : $n . ' đêm từ ' . $in->format('d/m')),
                        'checkIn' => $a, 'nights' => $n, 'links' => $this->stayLinks($city, $a, $n, $adults)];
                }
            }
            // ---- vé đi / về (chỉ nhắc, người tự đi xe máy / ô tô bấm "Không cần")
            $transport = $active->whereIn('kind', self::TRANSPORT);
            $hasGo = $transport->contains(fn($r) => Carbon::parse($r->startAt)->startOfDay()->lte($start));
            $hasBack = $transport->contains(fn($r) => Carbon::parse($r->startAt)->startOfDay()->gte($end) && ($days > 1 || $transport->count() > 1));
            if (!$hasGo && empty($skip['go'])) {
                $needs[] = ['key' => 'go', 'kind' => 'transport', 'title' => 'Chưa có vé đi' . ($city ? ' ' . $city : ''), 'date' => $start->toDateString(), 'links' => $this->moveLinks($city, $start->toDateString(), $adults)];
            }
            if (!$hasBack && empty($skip['back'])) {
                $needs[] = ['key' => 'back', 'kind' => 'transport', 'title' => 'Chưa có vé về' . ($city ? ' từ ' . $city : ''), 'date' => $end->toDateString(), 'links' => $this->moveLinks($city, $end->toDateString(), $adults, true)];
            }
        }

        // việc nào đã được ghi vào "cần đặt" thì đánh dấu, giao diện không mời thêm lần nữa
        $planned = $rows->filter(fn($r) => ($r->status ?? '') === 'planned');
        foreach ($needs as &$n) {
            $day = $n['checkIn'] ?? $n['date'];
            $hit = $planned->first(fn($r) => ($n['kind'] === 'hotel' ? $r->kind === 'hotel' : in_array($r->kind, self::TRANSPORT, true))
                && Carbon::parse($r->startAt)->toDateString() === $day);
            $n['plannedId'] = $hit ? (int) $hit->externalId : null;
        }
        unset($n);

        $now = now()->startOfDay();
        // đổi ngày đi / số ngày sau khi đã đặt: chỉ ra đặt chỗ nằm ngoài chuyến (cho lệch 1 ngày: bay tối hôm trước, về sáng hôm sau)
        $from = $trip->startDate ? Carbon::parse($trip->startDate)->startOfDay()->subDay() : null;
        $to = $from ? $from->copy()->addDays(max(1, (int) $trip->days) + 1) : null;
        $map = fn($r) => [
            'outside' => $from !== null && ($r->status ?? 'booked') !== 'cancelled'
                && !Carbon::parse($r->startAt)->startOfDay()->betweenIncluded($from, $to),
            'id' => (int) $r->externalId, 'kind' => $r->kind, 'kindLabel' => self::KINDS[$r->kind] ?? 'Đặt chỗ', 'title' => $r->title, 'status' => $r->status ?? 'booked',
            'startAt' => $r->startAt, 'endAt' => $r->endAt ?? null, 'needBy' => $r->needBy ?? null, 'bookUrl' => $r->bookUrl ?? null, 'note' => $r->note ?? null,
            'overdue' => ($r->status ?? '') === 'planned' && !empty($r->needBy) && Carbon::parse($r->needBy)->lt($now),
            'code' => $r->confirmationCode ?? null, 'hasFile' => !empty($r->attachPath), 'price' => (int) $r->price, 'simulated' => (bool) $r->simulated,
            'lat' => isset($r->latitude) ? (float) $r->latitude : null, 'lng' => isset($r->longitude) ? (float) $r->longitude : null, 'address' => $r->address ?? null,
        ];

        return [
            'needs' => $needs,
            'planned' => $rows->filter(fn($r) => ($r->status ?? '') === 'planned')->map($map)->values()->all(),
            'booked' => $booked->map($map)->values()->all(),
            'cancelled' => $rows->filter(fn($r) => ($r->status ?? '') === 'cancelled')->count(),
            'skip' => $skip,
            'city' => $city,
            // trang sửa dùng để tính lại lịch ngay (đường đi từ / về khách sạn, giờ tới nơi, giờ phải rời đi)
            'logistics' => app(TripLogistics::class)->raw((int) $trip->tripId),
            'center' => $dest && isset($dest->latitude) ? ['lat' => (float) $dest->latitude, 'lng' => (float) $dest->longitude] : null,
        ];
    }

    /** Số đêm của một khách sạn: endAt (trả phòng) > payload.nights > 1. */
    public function nights(object $h): int
    {
        if (!empty($h->endAt)) {
            return max(1, (int) Carbon::parse($h->startAt)->startOfDay()->diffInDays(Carbon::parse($h->endAt)->startOfDay()));
        }
        $p = json_decode((string) ($h->payload ?? ''), true);

        return max(1, (int) ($p['nights'] ?? 1));
    }

    /** @param list<string> $dates (Y-m-d, tăng dần) @return list<array{0:string,1:int}> */
    private function ranges(array $dates): array
    {
        $out = [];
        foreach ($dates as $d) {
            $last = $out ? count($out) - 1 : null;
            if ($last !== null && Carbon::parse($out[$last][0])->addDays($out[$last][1])->toDateString() === $d) {
                $out[$last][1]++;
            } else {
                $out[] = [$d, 1];
            }
        }

        return $out;
    }

    /** @return list<array{label:string,url:string}> */
    public function stayLinks(string $city, string $checkIn, int $nights, int $adults): array
    {
        $out = Carbon::parse($checkIn)->addDays($nights)->toDateString();
        $rooms = (int) ceil($adults / 2);

        return [
            ['label' => 'Booking.com', 'url' => 'https://www.booking.com/searchresults.html?' . http_build_query(['ss' => $city, 'checkin' => $checkIn, 'checkout' => $out, 'group_adults' => $adults, 'no_rooms' => $rooms])],
            ['label' => 'Agoda', 'url' => 'https://www.agoda.com/search?' . http_build_query(['textToSearch' => $city, 'checkIn' => $checkIn, 'checkOut' => $out, 'adults' => $adults, 'rooms' => $rooms])],
            ['label' => 'Traveloka', 'url' => 'https://www.traveloka.com/vi-vn/hotel'],
        ];
    }

    /** @return list<array{label:string,url:string}> */
    public function moveLinks(string $city, string $date, int $adults, bool $back = false): array
    {
        $q = $back ? 'Chuyến bay từ ' . $city . ' ngày ' . $date : 'Chuyến bay đến ' . $city . ' ngày ' . $date;

        return [
            ['label' => 'Máy bay (Google Flights)', 'url' => 'https://www.google.com/travel/flights?' . http_build_query(['q' => $q, 'hl' => 'vi'])],
            ['label' => 'Tàu (Đường sắt VN)', 'url' => 'https://dsvn.vn/'],
            ['label' => 'Xe khách (Vexere)', 'url' => 'https://vexere.com/vi-VN'],
        ];
    }

    /**
     * Mục lịch tạo kèm đặt chỗ (đã đặt) đặt lại đúng ngày theo ngày giờ thật của đặt chỗ khi chuyến đổi ngày đi.
     * Ngoài khoảng ngày của chuyến thì vào "Đã lưu" (giao diện Đặt chỗ báo "ngoài ngày đi").
     *
     * @param list<array> $rows các dòng tbl_trip_items sắp ghi
     * @return list<array>
     */
    public static function realignRows(int $tripId, array $rows, ?string $startDate, int $days): array
    {
        if (!self::ready()) {
            return $rows;
        }
        $want = [];
        foreach (DB::table('tbl_trip_external')->where('tripId', $tripId)->where(fn($q) => $q->whereNull('status')->orWhere('status', 'booked'))->get() as $x) {
            $day = null;
            if ($startDate) {
                $n = (int) Carbon::parse($startDate)->startOfDay()->diffInDays(Carbon::parse($x->startAt)->startOfDay(), false) + 1;
                $day = $n >= 1 && $n <= $days ? $n : null;
            }
            $want[mb_strtolower(\App\Http\Controllers\clients\TravelController::itemLabel($x))] = $day;
        }
        if (!$want) {
            return $rows;
        }
        foreach ($rows as $i => $r) {
            $k = mb_strtolower((string) ($r['label'] ?? ''));
            if ($k !== '' && array_key_exists($k, $want) && $r['dayNo'] !== $want[$k]) {
                $rows[$i]['dayNo'] = $want[$k];
                $rows[$i]['orderNo'] = min(127, 100 + $i);   // cuối ngày mới (cột orderNo nhỏ); giờ cố định (nếu có) vẫn quyết định vị trí trong lịch
                if ($want[$k] === null && array_key_exists('startMin', $r)) {
                    $rows[$i]['startMin'] = null;
                }
            }
        }

        return $rows;
    }

    /** "Không cần" một nhắc (stay / go / back), hoặc bật lại. */
    public function setSkip(object $trip, string $key, bool $skip): void
    {
        $cur = (array) json_decode((string) ($trip->bookingSkip ?? ''), true) ?: [];
        $cur = array_values(array_unique($skip ? array_merge($cur, [$key]) : array_diff($cur, [$key])));
        DB::table('tbl_trips')->where('tripId', $trip->tripId)->update(['bookingSkip' => $cur ? json_encode($cur) : null]);
    }
}
