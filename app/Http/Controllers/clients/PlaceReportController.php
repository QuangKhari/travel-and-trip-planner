<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\PlaceReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** POST /dia-diem/bao-sai : người dùng báo thông tin địa điểm sai (từ thẻ địa điểm trong Trip Planner). */
class PlaceReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(PlaceReports::ready(), 404);
        $v = $request->validate([
            'type' => 'required|in:place,experience',
            'refId' => 'required|integer|min:1',
            'kind' => 'required|in:' . implode(',', array_keys(PlaceReports::KINDS)),
            'detail' => 'nullable|string|max:500',
            'suggested' => 'nullable|string|max:120',
            'tripId' => 'nullable|integer|min:1',
        ]);
        if (PlaceReports::name($v['type'], (int) $v['refId']) === null) {
            return response()->json(['message' => 'Không tìm thấy địa điểm này.'], 404);
        }
        $uid = (int) session('userId');
        // một người không báo trùng cùng một nơi cùng loại khi báo trước còn chờ duyệt
        $dup = DB::table('tbl_place_reports')->where(['refType' => $v['type'], 'refId' => $v['refId'], 'userId' => $uid, 'kind' => $v['kind'], 'status' => 'open'])->exists();
        if (!$dup) {
            DB::table('tbl_place_reports')->insert(['refType' => $v['type'], 'refId' => (int) $v['refId'], 'userId' => $uid, 'kind' => $v['kind'],
                'detail' => isset($v['detail']) ? strip_tags($v['detail']) : null, 'suggested' => isset($v['suggested']) ? strip_tags($v['suggested']) : null,
                'tripId' => $v['tripId'] ?? null, 'status' => 'open', 'createdAt' => now()]);
        }

        return response()->json(['ok' => true, 'message' => 'Cảm ơn bạn. Travela sẽ kiểm tra và cập nhật cho mọi người.']);
    }
}
