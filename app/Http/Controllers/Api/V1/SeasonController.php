<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use App\Services\HeroPostcardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeasonController extends Controller
{
    /** GET /api/v1/seasons?month=10 : điểm đến đẹp trong tháng (dùng cho hero và Planner sau này). */
    public function index(Request $request, HeroPostcardService $service): JsonResponse
    {
        $month = (string) $request->query('month', now()->month);

        if (!ctype_digit($month) || (int) $month < 1 || (int) $month > 12) {
            return response()->json(['message' => 'month phải là số từ 1 đến 12'], 422);
        }

        return response()->json([
            'month' => (int) $month,
            'items' => $service->forMonth((int) $month),
        ]);
    }

    /** GET /api/v1/seasons/matrix?region=b|t|n : mức từng tháng (best|ok|avoid|unknown) của mỗi điểm đến đã có dữ liệu. */
    public function matrix(Request $request, ContentCatalog $catalog): JsonResponse
    {
        $raw = $request->query('region');
        $region = ContentCatalog::validRegion($raw);
        if ($raw !== null && $raw !== '' && $region === null) {
            return response()->json(['message' => 'region phải là b, t hoặc n'], 422);
        }

        return response()->json(['region' => $region, 'items' => $catalog->seasonMatrix($region)]);
    }
}
