<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlannerPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlannerPreviewController extends Controller
{
    /** GET /api/v1/planner-preview?diem-den=sa-pa&so-ngay=3&thang=10 */
    public function index(Request $r, PlannerPreviewService $svc): JsonResponse
    {
        $slug = $r->query('diem-den');
        $days = $r->query('so-ngay');
        $month = $r->query('thang', now()->month);

        if ($slug !== null && $slug !== '' && (!is_string($slug) || !preg_match('/^[a-z0-9-]{1,80}$/', $slug))) {
            return response()->json(['message' => 'diem-den không hợp lệ'], 422);
        }
        if ($days !== null && $days !== '' && (!ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 14)) {
            return response()->json(['message' => 'so-ngay phải từ 1 đến 14'], 422);
        }
        if (!ctype_digit((string) $month) || (int) $month < 1 || (int) $month > 12) {
            return response()->json(['message' => 'thang phải từ 1 đến 12'], 422);
        }

        return response()->json($svc->preview($slug ?: null, $days !== null && $days !== '' ? (int) $days : null, (int) $month));
    }
}
