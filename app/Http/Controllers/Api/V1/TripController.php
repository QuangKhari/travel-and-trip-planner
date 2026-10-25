<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tuần 5-7: REST cho chuyến đi (JWT, guard api). Mọi thao tác kiểm tra quyền sở hữu: chỉ đọc/sửa/xóa chuyến của chính mình;
 * chuyến của người khác trả 404 (không lộ sự tồn tại).
 *
 *  GET    /api/v1/trips            danh sách
 *  POST   /api/v1/trips            tạo  {title, startDate?, days, groupSize, budget?, fromTray?}
 *  GET    /api/v1/trips/{id}       chi tiết đầy đủ (ngày, quãng đường, chi phí, cảnh báo)
 *  PUT    /api/v1/trips/{id}       lưu  {title, startDate?, days, groupSize, budget?, items:[{type,refId,day?,note?,cost?}]}
 *  DELETE /api/v1/trips/{id}
 */
class TripController extends Controller
{
    private function uid(): int
    {
        return (int) auth('api')->id();
    }

    public function index(TripPlanner $planner): JsonResponse
    {
        return response()->json(['trips' => array_map(fn($t) => [
            'id' => (int) $t->tripId, 'title' => $t->title, 'startDate' => $t->startDate, 'days' => $t->days !== null ? (int) $t->days : null,
            'groupSize' => (int) $t->groupSize, 'budget' => $t->budget !== null ? (float) $t->budget : null,
            'status' => $t->status, 'itemCount' => (int) $t->itemCount,
        ], $planner->trips($this->uid()))]);
    }

    public function store(Request $request, TripPlanner $planner): JsonResponse
    {
        $data = $request->validate(TripPlanner::tripRules());
        $id = $planner->create($this->uid(), $data, $request->boolean('fromTray'));
        $trip = $planner->owned($this->uid(), $id);

        return response()->json($planner->payload($trip), 201);
    }

    public function show(int $id, TripPlanner $planner): JsonResponse
    {
        $trip = $planner->owned($this->uid(), $id);

        return $trip ? response()->json($planner->payload($trip)) : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
    }

    public function update(Request $request, int $id, TripPlanner $planner): JsonResponse
    {
        $data = $request->validate(TripPlanner::tripRules() + TripPlanner::itemRules());
        if (!$planner->save($this->uid(), $id, $data, $data['items'] ?? [])) {
            return response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
        }

        return response()->json($planner->payload($planner->owned($this->uid(), $id)));
    }

    public function destroy(int $id, TripPlanner $planner): JsonResponse
    {
        return $planner->delete($this->uid(), $id) ? response()->json(['ok' => true]) : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
    }
}
