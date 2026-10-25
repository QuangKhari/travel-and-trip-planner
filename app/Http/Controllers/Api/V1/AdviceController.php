<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Content\TravelAdvice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tuần 5: GET /api/v1/destinations/{slug}/advice?month=N  (month bỏ trống: trả cả 12 tháng). Công khai, chỉ đọc. */
class AdviceController extends Controller
{
    public function show(Request $request, string $slug, TravelAdvice $advice): JsonResponse
    {
        if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
            return response()->json(['message' => 'Điểm đến không hợp lệ.'], 422);
        }
        $id = DB::table('tbl_destinations')->where('slug', $slug)->where('isActive', 1)->value('destinationId');
        if (!$id) {
            return response()->json(['message' => 'Không tìm thấy điểm đến.'], 404);
        }

        $month = $request->query('month');
        if ($month !== null && $month !== '') {
            if (!ctype_digit((string) $month) || (int) $month < 1 || (int) $month > 12) {
                return response()->json(['message' => 'month phải từ 1 đến 12.'], 422);
            }

            return response()->json(['slug' => $slug, 'advice' => $advice->forMonth((int) $id, (int) $month), 'disclaimer' => TravelAdvice::DISCLAIMER]);
        }

        $all = $advice->forDestination((int) $id);
        $best = array_keys(array_filter($all, fn($a) => $a['level'] === 'best'));

        return response()->json(['slug' => $slug, 'bestMonths' => $best, 'bestRange' => TravelAdvice::ranges($best), 'months' => array_values($all), 'disclaimer' => TravelAdvice::DISCLAIMER]);
    }
}
