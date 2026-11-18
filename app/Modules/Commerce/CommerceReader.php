<?php

namespace App\Modules\Commerce;

use App\Services\DepartureService;
use App\Services\TourStops;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giao diện ĐỌC của phân hệ Commerce (đơn, thanh toán) cho phân hệ khác (Planner, AI). Phân hệ khác không đọc thẳng tbl_booking /
 * tbl_checkout mà gọi qua đây (xem config/modules.php và tests/Feature/ModuleBoundaryTest). Chỉ trả dữ liệu tối thiểu cần dùng.
 */
final class CommerceReader
{
    /**
     * Các ngày đi tour (đơn còn hiệu lực: chờ thanh toán / đã xác nhận) của một chuyến tự lập, quy về số ngày trong chuyến.
     * @return list<array{bookingId:int,code:string,title:string,from:int,to:int,status:string}>
     */
    public function tourDaysForTrip(int $tripId): array
    {
        if (!Schema::hasColumn('tbl_booking', 'tripId')) {
            return [];
        }
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first(['startDate', 'days']);
        if (!$trip || !$trip->startDate) {
            return [];
        }
        $start = Carbon::parse($trip->startDate)->startOfDay();
        $out = [];
        $rows = DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->where('b.tripId', $tripId)->whereIn('b.bookingStatus', ['n', 'y'])->whereNotNull('b.departureDate')
            ->get(['b.bookingId', 'b.bookingCode', 'b.bookingStatus', 'b.departureDate', 't.title', 't.durationDays', 't.time', 't.startDate', 't.endDate']);
        foreach ($rows as $b) {
            $len = max(1, (int) ($b->durationDays ?: TourStops::tourDays($b) ?: 1));
            $first = (int) $start->diffInDays(Carbon::parse($b->departureDate)->startOfDay(), false) + 1;
            $from = max(1, $first);
            $to = min(max(1, (int) $trip->days), $first + $len - 1);
            if ($to >= $from) {
                $out[] = ['bookingId' => (int) $b->bookingId, 'code' => (string) $b->bookingCode, 'title' => (string) $b->title, 'from' => $from, 'to' => $to, 'status' => (string) $b->bookingStatus];
            }
        }

        return $out;
    }

    /** Số liệu tóm tắt một ngày khởi hành cho bảng điều hành (phân hệ Tour Ops gọi). @return array{bookings:int,people:int,paid:int,due:int,unpaidHolds:int} */
    public function departureMoney(int $availabilityId): array
    {
        $rows = DB::table('tbl_booking as b')->leftJoin('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
            ->where('b.availabilityId', $availabilityId)->whereIn('b.bookingStatus', ['n', 'y', 'f'])
            ->get(['b.bookingStatus', 'b.numAdults', 'b.numChildren', 'b.totalPrice', 'c.paymentStatus', 'b.holdExpiresAt']);
        $paid = 0;
        $due = 0;
        $holds = 0;
        foreach ($rows as $r) {
            if ($r->paymentStatus === 'y') {
                $paid += (int) $r->totalPrice;
            } else {
                $due += (int) $r->totalPrice;
                $holds += $r->bookingStatus === 'n' ? 1 : 0;
            }
        }

        return ['bookings' => $rows->count(), 'people' => (int) $rows->sum(fn($r) => (int) $r->numAdults + (int) $r->numChildren), 'paid' => $paid, 'due' => $due, 'unpaidHolds' => $holds];
    }

    /** Đơn tour gắn với một chuyến tự lập (null nếu đơn không gắn chuyến). @return ?object{bookingId,bookingCode,tripId,departureDate,tourId,title,durationDays,time,startDate,endDate} */
    public function linkedBooking(int $bookingId): ?object
    {
        if (!Schema::hasColumn('tbl_booking', 'tripId')) {
            return null;
        }
        $b = DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')->where('b.bookingId', $bookingId)
            ->first(['b.bookingId', 'b.bookingCode', 'b.tripId', 'b.departureDate', 'b.tourId', 't.title', 't.durationDays', 't.time', 't.startDate', 't.endDate']);

        return $b && $b->tripId ? $b : null;
    }

    /** Báo cáo Planner: đơn đặt từ chuyến tự lập (có tripId) kể từ $since, và tổng số đơn cùng kỳ. @return array{fromTrip:?object,all:int} */
    public function plannerConversion(string $since): array
    {
        $fromTrip = Schema::hasColumn('tbl_booking', 'tripId')
            ? DB::table('tbl_booking as b')->leftJoin('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
                ->whereNotNull('b.tripId')->where('b.bookingDate', '>=', $since)
                ->selectRaw("count(*) n, sum(b.bookingStatus <> 'c') live, avg(b.matchScore) score, sum(case when c.paymentStatus = 'y' then b.totalPrice else 0 end) paid")->first()
            : null;

        return ['fromTrip' => $fromTrip, 'all' => DB::table('tbl_booking')->where('bookingDate', '>=', $since)->count()];
    }

    /**
     * Đơn còn hiệu lực của MỘT người cho các tour có trong chuyến (xuất PDF / in chuyến).
     * @param list<int> $tourIds
     * @return list<array{code:string,title:string,date:string,people:int,total:float,status:string,paid:bool}>
     */
    public function userBookingsForTours(int $userId, array $tourIds): array
    {
        if (!$tourIds) {
            return [];
        }

        return DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->leftJoin('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
            ->where('b.userId', $userId)->whereIn('b.tourId', $tourIds)->where('b.bookingStatus', '!=', 'c')
            ->orderBy('b.bookingId')->get(['b.bookingCode', 't.title', 'b.departureDate', 't.startDate', 'b.numAdults', 'b.numChildren', 'b.totalPrice', 'b.bookingStatus', 'c.paymentStatus'])
            ->map(fn($b) => [
                'code' => $b->bookingCode,
                'title' => $b->title,
                'date' => Carbon::parse($b->departureDate ?: $b->startDate)->format('d/m/Y'),
                'people' => (int) $b->numAdults + (int) $b->numChildren,
                'total' => (float) $b->totalPrice,
                'status' => ['n' => 'Chờ xác nhận', 'y' => 'Đã xác nhận', 'f' => 'Hoàn tất'][$b->bookingStatus] ?? $b->bookingStatus,
                'paid' => $b->paymentStatus === 'y',
            ])->all();
    }

    /** Ngày kết thúc chuyến của một ngày khởi hành (dùng lại quy tắc của DepartureService). */
    public static function endDate(string $departureDate, int $days): string
    {
        return DepartureService::endDate($departureDate, $days);
    }
}
