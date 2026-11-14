<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripSharing;
use App\Services\Planner\TripWatch;
use Illuminate\Http\JsonResponse;

/** Chuông thông báo của chuyến: sự kiện do hệ thống theo dõi (trips:watch, trips:digest) và thao tác như "xếp lại hôm nay". */
class TripWatchController extends Controller
{
    private function uid(): int
    {
        return (int) session('userId');
    }

    /** GET /len-ke-hoach/{id}/thong-bao */
    public function feed(int $id, TripWatch $watch): JsonResponse
    {
        (app(TripSharing::class)->access($this->uid(), $id) ?? abort(404));

        return response()->json($watch->feed($id, $this->uid()));
    }

    /** POST /len-ke-hoach/{id}/thong-bao/da-xem */
    public function seen(int $id, TripWatch $watch): JsonResponse
    {
        (app(TripSharing::class)->access($this->uid(), $id) ?? abort(404));
        $watch->markSeen($id, $this->uid());

        return response()->json(['ok' => true, 'unread' => 0]);
    }

    /** POST /len-ke-hoach/{id}/kiem-tra : kiểm tra ngay (không chờ lịch 3 giờ), không gửi email. */
    public function check(int $id, TripWatch $watch): JsonResponse
    {
        $trip = (app(TripSharing::class)->access($this->uid(), $id) ?? abort(404))['trip'];
        if (!TripWatch::ready()) {
            return response()->json(['message' => 'Cần chạy php artisan migrate.'], 503);
        }
        $res = $watch->observe($trip);

        return response()->json(['ok' => true, 'new' => count($res['new']), 'resolved' => count($res['resolved']), 'current' => $res['current'],
            'message' => $res['new'] ? count($res['new']) . ' thay đổi mới cần xem.' : ($res['current'] ? 'Không có gì mới. Vẫn còn ' . $res['current'] . ' cảnh báo đang theo dõi.' : 'Không có cảnh báo nào cho chuyến.')]
            + $watch->feed($id, $this->uid()));
    }
}
