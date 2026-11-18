<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripLifecycle;
use App\Services\Planner\TripSharing;
use Illuminate\Http\JsonResponse;

/** GET /len-ke-hoach/{id}/san-sang : mức sẵn sàng + việc cần làm (trước / trong chuyến) hoặc tổng kết (sau chuyến). */
class TripLifecycleController extends Controller
{
    public function show(int $id, TripLifecycle $life): JsonResponse
    {
        $trip = (app(TripSharing::class)->access((int) session('userId'), $id) ?? abort(404))['trip'];
        $r = $life->forTrip($trip);
        foreach ($r['recap']['toReview'] ?? [] as $i => $x) {
            $r['recap']['toReview'][$i]['url'] = route('planner.reviews', ['id' => $id]);
        }

        return response()->json(['ok' => true] + $r);
    }
}
