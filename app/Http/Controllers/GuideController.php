<?php

namespace App\Http\Controllers;

use App\Services\IncidentService;
use App\Services\TourStops;
use App\Support\AdminBadges;
use App\Support\BookingPii;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Trang cho hướng dẫn viên (mở bằng đường dẫn bí mật, không cần tài khoản quản trị; hết hạn sau ngày về 2 ngày):
 *   - danh sách khách của đoàn (đơn đã xác nhận / đang giữ chỗ), gọi điện một chạm
 *   - điểm danh theo ngày (số khách có mặt của từng đơn)
 *   - lịch trình theo giờ của ngày đó (TourStops)
 *   - báo sự cố tại chỗ -> vào trang Sự cố của quản trị (tbl_tour_incidents), ghi người báo là HDV
 */
class GuideController extends Controller
{
    public static function ready(): bool
    {
        return Schema::hasTable('tbl_guide_links');
    }

    /** Admin tạo đường dẫn cho một ngày khởi hành. */
    public function create(Request $request, int $availabilityId)
    {
        $dep = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')->where('a.availabilityId', $availabilityId)
            ->first(['a.*', 't.time', 't.durationDays', 't.startDate as tStart', 't.endDate as tEnd']) ?? abort(404);
        $v = $request->validate(['guideName' => 'nullable|string|max:120']);
        $days = TourStops::tourDays((object) ['durationDays' => $dep->durationDays, 'time' => $dep->time, 'startDate' => $dep->tStart, 'endDate' => $dep->tEnd]) ?? 1;
        $token = Str::random(40);
        DB::table('tbl_guide_links')->insert(['availabilityId' => $availabilityId, 'token' => $token, 'guideName' => $v['guideName'] ?? null,
            'expiresOn' => \Carbon\Carbon::parse($dep->departureDate)->addDays($days + 1)->toDateString(), 'createdBy' => (int) session('adminId') ?: null, 'createdAt' => now()]);

        return back()->with('success', 'Đường dẫn cho hướng dẫn viên: ' . route('guide.show', $token) . ' (hết hạn sau ngày về 2 ngày).');
    }

    private function link(string $token): object
    {
        abort_unless(self::ready(), 404);
        $l = DB::table('tbl_guide_links')->where('token', $token)->first();
        abort_if(!$l || $l->expiresOn < now()->toDateString(), 404);

        return $l;
    }

    public function show(Request $request, string $token)
    {
        $l = $this->link($token);
        $dep = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')->where('a.availabilityId', $l->availabilityId)
            ->first(['a.*', 't.title', 't.time', 't.durationDays', 't.startDate as tStart', 't.endDate as tEnd', 't.tourId']);
        $days = TourStops::tourDays((object) ['durationDays' => $dep->durationDays, 'time' => $dep->time, 'startDate' => $dep->tStart, 'endDate' => $dep->tEnd]) ?? 1;
        $today = (int) \Carbon\Carbon::parse($dep->departureDate)->startOfDay()->diffInDays(now()->startOfDay(), false) + 1;
        $day = max(1, min($days, (int) $request->query('ngay', max(1, min($days, $today)))));
        $bookings = DB::table('tbl_booking')->where('availabilityId', $l->availabilityId)->whereIn('bookingStatus', ['n', 'y'])->orderBy('bookingId')
            ->get(['bookingId', 'bookingCode', 'fullName', 'phoneNumber', 'numAdults', 'numChildren', 'bookingStatus']);
        BookingPii::decryptAll($bookings->all());
        $checks = DB::table('tbl_departure_checkins')->where('availabilityId', $l->availabilityId)->where('dayNo', $day)->pluck('present', 'bookingId')->all();

        return view('guide', ['l' => $l, 'dep' => $dep, 'day' => $day, 'days' => $days, 'bookings' => $bookings, 'checks' => $checks,
            'stops' => app(TourStops::class)->forTour((int) $dep->tourId)[$day] ?? [], 'types' => IncidentService::TYPES, 'sev' => IncidentService::SEVERITIES]);
    }

    public function checkin(Request $request, string $token)
    {
        $l = $this->link($token);
        $v = $request->validate(['bookingId' => 'required|integer', 'dayNo' => 'required|integer|between:1,30', 'present' => 'required|integer|between:0,100']);
        $b = DB::table('tbl_booking')->where('bookingId', $v['bookingId'])->where('availabilityId', $l->availabilityId)->first(['numAdults', 'numChildren']) ?? abort(404);
        $present = min((int) $v['present'], (int) $b->numAdults + (int) $b->numChildren);
        DB::table('tbl_departure_checkins')->updateOrInsert(['availabilityId' => $l->availabilityId, 'bookingId' => $v['bookingId'], 'dayNo' => $v['dayNo']],
            ['present' => $present, 'by' => $l->guideName, 'checkedAt' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true, 'present' => $present]) : back();
    }

    public function incident(Request $request, string $token)
    {
        $l = $this->link($token);
        $v = $request->validate(['type' => 'required|in:' . implode(',', array_keys(IncidentService::TYPES)), 'severity' => 'required|in:' . implode(',', array_keys(IncidentService::SEVERITIES)),
            'title' => 'required|string|min:5|max:200', 'description' => 'nullable|string|max:3000']);
        $tourId = DB::table('tbl_tour_availability')->where('availabilityId', $l->availabilityId)->value('tourId');
        $id = DB::table('tbl_tour_incidents')->insertGetId(['availabilityId' => $l->availabilityId, 'tourId' => $tourId, 'type' => $v['type'], 'severity' => $v['severity'],
            'status' => 'open', 'title' => $v['title'], 'description' => trim(($v['description'] ?? '') . "\n\n(Hướng dẫn viên " . ($l->guideName ?: '') . ' báo từ hiện trường)'),
            'confidential' => $v['type'] === 'health', 'reportedBy' => null, 'createdAt' => now(), 'updatedAt' => now()]);
        IncidentService::log($id, null, 'created', 'Hướng dẫn viên ' . ($l->guideName ?: '') . ' báo từ hiện trường.');
        AdminBadges::forget();

        return back()->with('success', 'Đã gửi sự cố #' . $id . ' về điều hành. Điều hành sẽ gọi lại cho bạn.');
    }
}
