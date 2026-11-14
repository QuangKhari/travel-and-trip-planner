<?php

namespace App\Services\Departures;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dự báo sớm lịch khởi hành có nguy cơ thiếu khách (trước khi departures:check-shortfall gắn cờ ở mốc ~14 ngày).
 *
 * Cách tính (đường cong đặt chỗ lịch sử, không cần mô hình học máy):
 *   1. Lấy các lịch ĐÃ khởi hành trong 18 tháng gần nhất. Với mỗi mốc "còn D ngày" (D = 7, 14, 21, 30, 45, 60, 90),
 *      tính tỷ lệ khách đã đặt tại mốc đó so với số khách cuối cùng: f(D). Ưu tiên đường cong của chính tour
 *      (khi có ≥ 4 lịch đã chạy), không thì dùng đường cong chung.
 *   2. Lịch sắp tới còn D ngày, hiện có n khách: dự báo cuối = n / f(D) (nội suy tuyến tính giữa các mốc).
 *   3. Nguy cơ khi dự báo < khách tối thiểu. Độ tin: số lịch lịch sử làm căn cứ.
 * Lịch sử quá ít (< 4 lịch có khách) thì không dự báo, tránh báo bừa.
 */
class ShortfallForecast
{
    public const MARKS = [7, 14, 21, 30, 45, 60, 90];

    /** @return array{curve:array<int,float>,n:int} */
    public function curve(?int $tourId = null): array
    {
        $q = DB::table('tbl_tour_availability as a')->where('a.departureDate', '<', now()->toDateString())
            ->where('a.departureDate', '>=', now()->subMonths(18)->toDateString());
        if ($tourId) {
            $q->where('a.tourId', $tourId);
        }
        $deps = $q->pluck('a.departureDate', 'a.availabilityId');
        if ($deps->isEmpty()) {
            return ['curve' => [], 'n' => 0];
        }
        $rows = DB::table('tbl_booking')->whereIn('availabilityId', $deps->keys())->whereIn('bookingStatus', ['y', 'f'])
            ->get(['availabilityId', 'bookingDate', 'numAdults', 'numChildren']);
        $sum = array_fill_keys(self::MARKS, 0.0);
        $n = 0;
        foreach ($rows->groupBy('availabilityId') as $aid => $bs) {
            $final = $bs->sum(fn($b) => $b->numAdults + $b->numChildren);
            if ($final <= 0) {
                continue;
            }
            $dep = \Carbon\Carbon::parse($deps[$aid])->startOfDay();
            foreach (self::MARKS as $d) {
                $cut = $dep->copy()->subDays($d);
                $sum[$d] += $bs->filter(fn($b) => \Carbon\Carbon::parse($b->bookingDate)->lt($cut))->sum(fn($b) => $b->numAdults + $b->numChildren) / $final;
            }
            $n++;
        }
        if ($n === 0) {
            return ['curve' => [], 'n' => 0];
        }

        return ['curve' => array_map(fn($s) => round($s / $n, 3), $sum), 'n' => $n];
    }

    /** Tỷ lệ đã đặt kỳ vọng khi còn $days ngày (nội suy; 0 ngày = 1). */
    public static function fractionAt(array $curve, int $days): ?float
    {
        if (!$curve) {
            return null;
        }
        $pts = [0 => 1.0] + $curve;
        ksort($pts);
        $prevD = 0;
        $prevF = 1.0;
        foreach ($pts as $d => $f) {
            if ($days <= $d) {
                return $d === $prevD ? $f : $prevF + ($f - $prevF) * ($days - $prevD) / ($d - $prevD);
            }
            [$prevD, $prevF] = [$d, $f];
        }

        return $prevF; // xa hơn 90 ngày: dùng mốc 90
    }

    /** Các lịch mở bán còn 15..90 ngày có dự báo dưới mức tối thiểu. @return Collection<object> */
    public function atRisk(int $fromDays = 15, int $toDays = 90): Collection
    {
        $global = $this->curve();
        if ($global['n'] < 4) {
            return collect();
        }
        $deps = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')->where('a.status', 'open')
            ->whereBetween('a.departureDate', [now()->addDays($fromDays)->toDateString(), now()->addDays($toDays)->toDateString()])
            ->where('a.minParticipants', '>', 0)->orderBy('a.departureDate')->get(['a.*', 't.title']);
        $curves = [];
        $out = collect();
        foreach ($deps as $d) {
            $tc = $curves[$d->tourId] ??= $this->curve((int) $d->tourId);
            [$curve, $basis, $scope] = $tc['n'] >= 4 ? [$tc['curve'], $tc['n'], 'tour'] : [$global['curve'], $global['n'], 'chung'];
            $days = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($d->departureDate)->startOfDay());
            $f = self::fractionAt($curve, $days);
            $now = (int) $d->bookedSlots + (int) $d->heldSlots;
            $forecast = $f && $f > 0.02 ? (int) round($now / $f) : null;
            if ($forecast === null || $forecast >= (int) $d->minParticipants) {
                continue;
            }
            $d->daysLeft = $days;
            $d->forecast = min($forecast, (int) $d->totalSlots);
            $d->expectedNow = (int) round($d->minParticipants * $f);   // muốn đạt tối thiểu thì lúc này cần khoảng bao nhiêu
            $d->basis = $basis;
            $d->scope = $scope;
            $out->push($d);
        }

        return $out;
    }
}
