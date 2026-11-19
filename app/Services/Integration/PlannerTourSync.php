<?php

namespace App\Services\Integration;

use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\TripWatch;
use App\Support\DomainEvents;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phân hệ Trip Planner phản ứng với sự kiện của Tour và Nội dung (qua outbox, không đọc / ghi chéo bảng nghiệp vụ của nhau).
 *
 * Nguyên tắc: đơn tour là NGUỒN SỰ THẬT về việc đã đặt / đã hủy / ngày khởi hành; Planner chỉ thông báo vào nhật ký chuyến
 * (chuông "Theo dõi chuyến") và chỉ ra mục có thể bị ảnh hưởng — KHÔNG tự sửa lịch tự lập của người dùng.
 * Mỗi thông báo có ref riêng (UNIQUE tripId + kind + ref) nên xử lý lại cùng sự kiện không tạo thông báo trùng.
 */
class PlannerTourSync
{
    public function handle(string $name, array $p): void
    {
        match ($name) {
            DomainEvents::BOOKING_CONFIRMED => $this->bookingConfirmed((int) $p['bookingId']),
            DomainEvents::BOOKING_CANCELLED => $this->bookingCancelled((int) $p['bookingId'], (string) ($p['reason'] ?? '')),
            DomainEvents::DEPARTURE_CHANGED => $this->departureChanged((int) $p['bookingId'], $p['from'] ?? null, $p['to'] ?? null),
            DomainEvents::PLACE_UNPUBLISHED => $this->placeUnpublished((string) $p['type'], (int) $p['refId'], (string) ($p['name'] ?? '')),
            DomainEvents::PLACE_HOURS_CHANGED => $this->placeHoursChanged((string) $p['type'], (int) $p['refId']),
            default => null,
        };
    }

    /** @return ?object{bookingId, bookingCode, tripId, departureDate, tourId, title, durationDays, time, startDate, endDate} */
    private function linked(int $bookingId): ?object
    {
        // đơn đọc qua giao diện của phân hệ Commerce; chuyến là bảng của chính Planner
        $b = app(\App\Modules\Commerce\CommerceReader::class)->linkedBooking($bookingId);

        return $b && DB::table('tbl_trips')->where('tripId', $b->tripId)->exists() ? $b : null;
    }

    private function note(int $tripId, string $tone, string $title, ?string $text, string $ref, ?int $day = null): void
    {
        if (!TripWatch::ready()) {
            return;
        }
        DB::table('tbl_trip_events')->insertOrIgnore(['tripId' => $tripId, 'kind' => 'booking', 'tone' => $tone, 'title' => mb_substr($title, 0, 200),
            'text' => $text, 'day' => $day, 'ref' => mb_substr($ref, 0, 120), 'createdAt' => now()]);
    }

    private static function d(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : 'chưa rõ ngày';
    }

    private function bookingConfirmed(int $bookingId): void
    {
        $b = $this->linked($bookingId);
        if (!$b) {
            return;
        }
        $text = 'Travela đã xác nhận đơn ' . $b->bookingCode . '. Đây là dịch vụ đã đặt: lịch tour do Travela vận hành, các mục khác trong ngày tour nên để trống hoặc là hoạt động ngoài giờ tour.';
        $clash = $this->overlap((int) $b->tripId, $b);
        if ($clash) {
            $text .= ' Trong lịch tự lập đang có mục vào ngày đi tour: ' . $clash . '.' . $this->overlapOptions((int) $b->tripId);
        }
        $this->note((int) $b->tripId, 'ok', 'Đã xác nhận tour “' . $b->title . '”, khởi hành ' . self::d($b->departureDate), $text, 'confirmed:' . $bookingId);
    }

    private function bookingCancelled(int $bookingId, string $reason): void
    {
        $b = $this->linked($bookingId);
        if (!$b) {
            return;
        }
        $why = ['customer' => 'bạn đã hủy', 'admin' => 'Travela hủy', 'expired' => 'hết hạn giữ chỗ', 'shortfall' => 'tour không đủ khách'][$reason] ?? 'đã hủy';
        $this->note(
            (int) $b->tripId,
            'warn',
            'Đơn tour “' . $b->title . '” đã hủy (' . $why . ')',
            'Travela không còn giữ chỗ cho đơn ' . $b->bookingCode . '. Mục tour trong lịch (nếu có) giờ chỉ là kế hoạch, chưa được xác nhận; tiền hoàn (nếu có) theo dõi ở trang đơn đã đặt.',
            'cancelled:' . $bookingId,
        );
    }

    private function departureChanged(int $bookingId, ?string $from, ?string $to): void
    {
        $b = $this->linked($bookingId);
        if (!$b) {
            return;
        }
        $text = 'Ngày khởi hành của đơn ' . $b->bookingCode . ' đổi từ ' . self::d($from) . ' sang ' . self::d($to) . ' (theo phương án bạn đã đồng ý). Lịch tự lập KHÔNG tự đổi.';
        $clash = $this->overlap((int) $b->tripId, $b);
        if ($clash) {
            $text .= ' Các mục có thể trùng ngày tour mới: ' . $clash . '.' . $this->overlapOptions((int) $b->tripId);
        }
        $trip = DB::table('tbl_trips')->where('tripId', $b->tripId)->first(['startDate', 'days']);
        if ($to && $trip && $trip->startDate) {
            $s = Carbon::parse($trip->startDate)->startOfDay();
            $e = $s->copy()->addDays(max(1, (int) $trip->days) - 1);
            if (!Carbon::parse($to)->betweenIncluded($s, $e)) {
                $text .= ' Ngày tour mới nằm ngoài khoảng ngày của chuyến (' . $s->format('d/m') . '–' . $e->format('d/m') . ').';
            }
        }
        $this->note((int) $b->tripId, 'warn', 'Tour “' . $b->title . '” dời ngày: ' . self::d($from) . ' → ' . self::d($to), $text, 'moved:' . $bookingId . ':' . $to);
    }

    /** Mục của chuyến rơi vào các ngày đi tour (trừ chính mục tour). */
    private function overlap(int $tripId, object $b): ?string
    {
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first(['startDate']);
        if (!$trip || !$trip->startDate || !$b->departureDate) {
            return null;
        }
        $len = max(1, (int) ($b->durationDays ?: \App\Modules\Content\TourCatalog::days($b->time ?? null, $b->startDate ?? null, $b->endDate ?? null) ?: 1));
        $first = (int) Carbon::parse($trip->startDate)->startOfDay()->diffInDays(Carbon::parse($b->departureDate)->startOfDay(), false) + 1;
        $rows = DB::table('tbl_trip_items')->where('tripId', $tripId)->whereBetween('dayNo', [$first, $first + $len - 1])->where('refType', '!=', 'tour')
            ->orderBy('dayNo')->orderBy('orderNo')->limit(6)->get(['dayNo', 'label', 'refType']);

        return $rows->isEmpty() ? null : $rows->map(fn($r) => ($r->label ?: $r->refType) . ' (ngày ' . $r->dayNo . ')')->implode(', ');
    }

    /** Câu báo số phương án dời các mục trùng ngày tour (rỗng nếu không có / lỗi). Lịch không tự đổi. */
    private function overlapOptions(int $tripId): string
    {
        try {
            $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
            $n = $trip ? count(app(\App\Services\Planner\Health\TripResolver::class)->forTrip($trip, 'tour.overlap', null, null)['options'] ?? []) : 0;
        } catch (\Throwable $e) {
            report($e);
            $n = 0;
        }

        return $n ? ' Travela đã chuẩn bị ' . $n . ' phương án dời lịch (có so sánh trước / sau), chỉ áp dụng khi bạn bấm.' : '';
    }

    /** Số phương án lập lại kế hoạch cho mục không còn xác thực trong ngày $day (0 nếu lỗi / không có). */
    private function replanCount(int $tripId, ?int $day): int
    {
        try {
            $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();

            return $trip ? count(app(\App\Services\Planner\Health\TripResolver::class)->forTrip($trip, 'content.unavailable', $day, null)['options'] ?? []) : 0;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Giờ mở cửa của một nơi vừa được xác minh / sửa: với chuyến SẮP ĐI hoặc ĐANG ĐI có nơi này trong các ngày, kiểm lại bằng TripHealth;
     * nơi giờ đóng cửa / không kịp giờ ở ngày đã xếp thì báo vào chuông kèm phương án (mở đúng mục bằng "Xem phương án"). Lịch không tự đổi.
     */
    private function placeHoursChanged(string $type, int $refId): void
    {
        if (!TripWatch::ready()) {
            return;
        }
        $today = now()->toDateString();
        $tripIds = DB::table('tbl_trip_items as i')->join('tbl_trips as t', 't.tripId', '=', 'i.tripId')->where('i.refType', $type)->where('i.refId', $refId)
            ->whereNotNull('i.dayNo')->where('t.kind', 'trip')->whereNotNull('t.startDate')->whereNotIn('t.status', ['done', 'cancelled'])
            ->whereRaw('DATE_ADD(t.startDate, INTERVAL GREATEST(t.days, 1) - 1 DAY) >= ?', [$today])
            ->where('t.startDate', '<=', now()->addDays(120)->toDateString())->distinct()->limit(500)->pluck('i.tripId');
        foreach ($tripIds as $tripId) {
            try {
                $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
                $h = app(TripHealth::class)->forTrip($trip);
                $payload = app(\App\Services\Planner\TripPlanner::class)->payload($trip);
                foreach ($h['checks'] as $c) {
                    if (!in_array($c['code'], ['hours.closed', 'hours.late', 'hours.early'], true) || ($c['day'] ?? null) === null || ($c['index'] ?? null) === null) {
                        continue;
                    }
                    $it = collect($payload['days'])->firstWhere('day', $c['day'])['items'][$c['index']] ?? null;
                    if (!$it || ($it['type'] ?? '') !== $type || (int) ($it['refId'] ?? 0) !== $refId) {
                        continue;
                    }
                    $n = count(app(\App\Services\Planner\Health\TripResolver::class)->forTrip($trip, $c['code'], (int) $c['day'], (int) $c['index'])['options'] ?? []);
                    DB::table('tbl_trip_events')->insertOrIgnore(['tripId' => (int) $tripId, 'kind' => 'content', 'tone' => $c['code'] === 'hours.closed' ? 'bad' : 'warn',
                        'title' => mb_substr('Giờ mở cửa của “' . $it['name'] . '” vừa được cập nhật', 0, 200),
                        'text' => $c['text'] . ($n ? ' Travela đã chuẩn bị ' . $n . ' phương án (có so sánh trước / sau), chỉ áp dụng khi bạn bấm.' : ''),
                        'day' => (int) $c['day'], 'ref' => mb_substr('hours:' . $c['code'] . ':' . $type . ':' . $refId . ':' . substr(md5($c['text']), 0, 8), 0, 120), 'createdAt' => now()]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function placeUnpublished(string $type, int $refId, string $name): void
    {
        $trips = DB::table('tbl_trip_items as i')->join('tbl_trips as t', 't.tripId', '=', 'i.tripId')->where('i.refType', $type)->where('i.refId', $refId)
            ->where('t.kind', 'trip')->groupBy('i.tripId')->limit(500)->selectRaw('i.tripId AS tripId, MIN(i.dayNo) AS firstDay')->get()
            ->pluck('firstDay', 'tripId');
        foreach ($trips as $tripId => $day) {
            if (!TripWatch::ready()) {
                return;
            }
            // Lập lại kế hoạch chủ động: chuẩn bị sẵn phương án (thay bằng nơi tương tự / bỏ khỏi ngày), đã mô phỏng bằng TripHealth.
            // Chỉ ghi SỐ phương án vào thông báo; người dùng bấm "Xem phương án" thì máy chủ tính lại trên bản mới nhất rồi mới áp dụng.
            $n = $this->replanCount((int) $tripId, $day !== null ? (int) $day : null);
            DB::table('tbl_trip_events')->insertOrIgnore(['tripId' => (int) $tripId, 'kind' => 'content', 'tone' => 'warn',
                'title' => mb_substr('“' . ($name ?: 'Một địa điểm') . '” không còn được Travela xác thực', 0, 200),
                'text' => 'Mục vẫn được giữ trong chuyến của bạn. ' . ($n > 1
                    ? 'Travela đã chuẩn bị ' . $n . ' phương án (thay bằng nơi tương tự gần đó hoặc bỏ khỏi ngày), có so sánh trước / sau. Lịch chỉ đổi khi bạn bấm Áp dụng.'
                    : 'Hãy kiểm tra lại giờ mở cửa hoặc thay bằng nơi khác.'),
                'day' => $day !== null ? (int) $day : null, 'ref' => mb_substr('unpub:' . $type . ':' . $refId, 0, 120), 'createdAt' => now()]);
        }
    }
}
