<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripConflict;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tuần 5-7: REST cho chuyến đi (JWT, guard api). Mọi thao tác kiểm tra quyền sở hữu: chỉ đọc/sửa/xóa chuyến của chính mình;
 * chuyến của người khác trả 404 (không lộ sự tồn tại).
 *
 *  GET    /api/v1/trips            danh sách
 *  POST   /api/v1/trips            tạo  {title, startDate?, days, groupSize, budget?, fromTray?}
 *  GET    /api/v1/trips/{id}       chi tiết đầy đủ (ngày, quãng đường, chi phí, cảnh báo)
 *  PUT    /api/v1/trips/{id}       lưu  {rev?, title, startDate?, days, groupSize, budget?, items:[{type,refId,day?,note?,cost?}]}
 *                                  rev (bản đang sửa) cũ hơn bản trên máy chủ -> 409 {conflict:true, rev} (cùng cơ chế với trang web /luu)
 *  GET / PUT trả thêm "rev" để lần lưu sau gửi lại.
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
        try {
            $id = $planner->create($this->uid(), $data, $request->boolean('fromTray'));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $trip = $planner->owned($this->uid(), $id);

        return response()->json($planner->payload($trip), 201);
    }

    public function show(int $id, TripPlanner $planner): JsonResponse
    {
        $trip = $planner->owned($this->uid(), $id);

        return $trip ? response()->json($planner->payload($trip) + ['rev' => app(TripSharing::class)->revision($id)['rev']])
            : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
    }

    public function update(Request $request, int $id, TripPlanner $planner): JsonResponse
    {
        $data = $request->validate(TripPlanner::tripRules() + TripPlanner::itemRules() + ['rev' => ['nullable', 'integer', 'min:0']]);
        try {
            $ok = $planner->save($this->uid(), $id, $data, $data['items'] ?? []);
        } catch (TripConflict $e) {
            return response()->json(['message' => $e->getMessage(), 'conflict' => true, 'rev' => $e->currentRev, 'by' => $e->by], 409);
        }
        if (!$ok) {
            return response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
        }

        return response()->json($planner->payload($planner->owned($this->uid(), $id)) + ['rev' => app(TripSharing::class)->revision($id)['rev']]);
    }

    public function destroy(int $id, TripPlanner $planner): JsonResponse
    {
        return $planner->delete($this->uid(), $id) ? response()->json(['ok' => true]) : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
    }
}
