<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Planner\TourMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A-01 AI Tour Match (công khai, không cần đăng nhập):
 *   GET /api/v1/tour-match?destination=da-lat&days=3&adults=2&children=0&budget=8000000&interests[]=nature&startDate=2026-12-20&limit=5
 * -> {weights, threshold, results:[{tourId,title,score,pct,components:{budget,interest,travel,group},bookUrl,...}]}
 *
 * Cùng công thức với khối "Tour Travela hợp chuyến này" trong trang sửa chuyến (TourMatch::score), nên kết quả kiểm chứng được.
 */
class TourMatchController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $v = Validator::make($r->query(), [
            'destination' => 'required_without:destinationId|nullable|string|max:80|regex:/^[a-z0-9-]+$/',
            'destinationId' => 'required_without:destination|nullable|integer|min:1',
            'days' => 'required|integer|min:1|max:30',
            'adults' => 'nullable|integer|min:1|max:50',
            'children' => 'nullable|integer|min:0|max:50',
            'budget' => 'nullable|numeric|min:0|max:10000000000',
            'interests' => 'nullable|array|max:6',
            'interests.*' => 'string|max:30',
            'startDate' => 'nullable|date_format:Y-m-d',
            'month' => 'nullable|integer|min:1|max:12',
            'limit' => 'nullable|integer|min:1|max:' . (int) config('planner.tour_match.api_max', 10),
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }
        $d = $v->validated();
        $dest = DB::table('tbl_destinations')->where('isActive', 1)
            ->when(!empty($d['destinationId']), fn($q) => $q->where('destinationId', (int) $d['destinationId']), fn($q) => $q->where('slug', (string) $d['destination']))
            ->first(['destinationId', 'slug', 'name']);
        if (!$dest) {
            return response()->json(['message' => 'Không tìm thấy điểm đến.'], 404);
        }
        $want = [
            'startDate' => $d['startDate'] ?? null, 'month' => isset($d['month']) ? (int) $d['month'] : null,
            'days' => (int) $d['days'], 'adults' => (int) ($d['adults'] ?? 1), 'children' => (int) ($d['children'] ?? 0),
            'budget' => isset($d['budget']) && (float) $d['budget'] > 0 ? (float) $d['budget'] : null,
            'interests' => array_values(array_intersect((array) ($d['interests'] ?? []), array_keys((array) config('interests')))),
            'placeNames' => [],
        ];
        $results = app(TourMatch::class)->rank($want, [(int) $dest->destinationId], (int) ($d['limit'] ?? config('planner.tour_match.limit', 3)));

        return response()->json([
            'destination' => ['id' => (int) $dest->destinationId, 'slug' => $dest->slug, 'name' => $dest->name],
            'weights' => TourMatch::weights(),
            'threshold' => TourMatch::threshold(),
            'formula' => 'Match = 0.35×Ngân sách + 0.30×Sở thích + 0.20×Thời gian di chuyển + 0.15×Nhóm',
            'results' => array_map(fn($x) => array_intersect_key($x, array_flip([
                'tourId', 'title', 'destName', 'time', 'days', 'departureDate', 'availabilityId', 'remaining', 'enoughSeats',
                'priceAdult', 'forGroup', 'score', 'pct', 'components', 'matchedInterests', 'url', 'bookUrl',
            ])), $results),
        ]);
    }
}
