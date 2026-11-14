<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Đăng ký thông báo đẩy và "tin mới nhất" cho service worker (sw.js). */
class PushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(WebPush::enabled(), 404);
        $v = $request->validate(['endpoint' => 'required|url|starts_with:https://|max:500', 'keys.p256dh' => 'required|string|max:120', 'keys.auth' => 'required|string|max:60']);
        DB::table('tbl_push_subscriptions')->updateOrInsert(['endpoint' => $v['endpoint']],
            ['userId' => (int) session('userId'), 'p256dh' => $v['keys']['p256dh'], 'auth' => $v['keys']['auth'], 'createdAt' => now(), 'failedAt' => null]);

        return response()->json(['ok' => true, 'message' => 'Đã bật thông báo trên thiết bị này.']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $v = $request->validate(['endpoint' => 'required|string|max:500']);
        DB::table('tbl_push_subscriptions')->where('endpoint', $v['endpoint'])->where('userId', (int) session('userId'))->delete();

        return response()->json(['ok' => true]);
    }

    /** GET /thong-bao/moi-nhat : sự kiện mới nhất (2 ngày) trong các chuyến người dùng xem được, để hiện thông báo đẩy. */
    public function latest(): JsonResponse
    {
        $uid = (int) session('userId');
        if (!$uid || !\App\Services\Planner\TripWatch::ready()) {
            return response()->json(['event' => null]);
        }
        $trips = DB::table('tbl_trip_members')->where('userId', $uid)->pluck('tripId')
            ->merge(DB::table('tbl_trips')->where('userId', $uid)->pluck('tripId'))->unique()->all();
        $e = $trips ? DB::table('tbl_trip_events as e')->join('tbl_trips as t', 't.tripId', '=', 'e.tripId')
            ->whereIn('e.tripId', $trips)->where('e.createdAt', '>=', now()->subDays(2))->whereIn('e.kind', ['alert', 'digest', 'resolved'])
            ->orderByDesc('e.eventId')->first(['e.*', 't.title as trip']) : null;

        return response()->json(['event' => $e ? ['tripId' => (int) $e->tripId, 'trip' => $e->trip, 'title' => $e->title, 'text' => mb_substr((string) $e->text, 0, 160),
            'url' => route('planner.edit', ['id' => $e->tripId]) . ($e->kind === 'alert' ? '?la-ban=alerts' : '')] : null]);
    }
}
