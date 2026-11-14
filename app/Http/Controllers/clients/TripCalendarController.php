<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripCalendar;
use App\Services\Planner\TripSharing;
use Illuminate\Http\Response;

/** Xuất / đăng ký lịch chuyến đi dạng .ics */
class TripCalendarController extends Controller
{
    /** GET /len-ke-hoach/{id}/lich.ics : tải một lần (cần đăng nhập, xem được chuyến) */
    public function download(int $id, TripCalendar $cal): Response
    {
        $acc = app(TripSharing::class)->access((int) session('userId'), $id) ?? abort(404);
        if (empty($acc['trip']->startDate)) {
            abort(422, 'Chuyến chưa có ngày đi nên chưa xuất được lịch.');
        }

        return response($cal->ics($acc['trip']), 200, ['Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . TripCalendar::fileName($acc['trip']) . '"']);
    }

    /** GET /lich-chuyen-di/{id}/{user}/{sig}.ics : đường dẫn đăng ký cho ứng dụng lịch (không có phiên đăng nhập) */
    public function feed(int $id, int $user, string $sig, TripCalendar $cal): Response
    {
        if (!hash_equals(TripCalendar::sign($id, $user), $sig) || !TripCalendar::hasAccess($id, $user)) {
            abort(404);
        }
        $trip = TripCalendar::trip($id) ?? abort(404);

        return response($cal->ics($trip), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=900']);
    }
}
