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
 *  PUT    /api/v1/trips/{id}       lưu  {rev, title, startDate?, days, groupSize, budget?, items:[{type,refId,day?,note?,cost?}]}
 *                                  rev (bản đang sửa, bắt buộc) cũ hơn bản trên máy chủ -> 409 {code:REVISION_CONFLICT, conflict:true, rev} (cùng cơ chế với trang web /luu)
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

    /** GET /api/v1/trips/{id}/readiness : mức sẵn sàng + việc có hạn chót (trước / trong chuyến) hoặc tổng kết (sau chuyến). */
    public function readiness(int $id, TripPlanner $planner, \App\Services\Planner\TripLifecycle $life): JsonResponse
    {
        $trip = $planner->owned($this->uid(), $id);

        return $trip ? response()->json($life->forTrip($trip)) : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
    }

    /**
     * GET /api/v1/trips/{id}/today?date=YYYY-MM-DD : lịch của MỘT ngày cho app di động ("Hôm nay" khi đang đi) — giờ bắt đầu / kết thúc
     * tính bằng đúng DaySchedule của máy chủ, trạng thái đã ghé / bỏ qua, mục kế tiếp. Ngày ngoài chuyến -> 404 kèm khoảng ngày của chuyến.
     */
    public function today(Request $request, int $id, TripPlanner $planner, \App\Services\Planner\Health\TripHealth $health): JsonResponse
    {
        $trip = $planner->owned($this->uid(), $id);
        if (!$trip) {
            return response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
        }
        if (!$trip->startDate) {
            return response()->json(['message' => 'Chuyến chưa có ngày đi.'], 422);
        }
        $date = \Carbon\Carbon::parse(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? $request->query('date') : now()->toDateString())->startOfDay();
        $start = \Carbon\Carbon::parse($trip->startDate)->startOfDay();
        $day = (int) $start->diffInDays($date, false) + 1;
        if ($day < 1 || $day > max(1, (int) $trip->days)) {
            return response()->json(['message' => 'Ngày này không thuộc chuyến.', 'from' => $start->toDateString(), 'to' => $start->copy()->addDays(max(1, (int) $trip->days) - 1)->toDateString()], 404);
        }
        $h = collect($health->forTrip($trip)['days'] ?? [])->firstWhere('day', $day);
        $items = collect($planner->payload($trip)['days'])->firstWhere('day', $day)['items'] ?? [];
        $rows = [];
        foreach (array_values($items) as $i => $it) {
            $r = $h['rows'][$i] ?? null;
            $rows[] = ['index' => $i, 'name' => $it['name'], 'type' => $it['type'], 'refId' => $it['refId'] ?? null, 'lat' => $it['lat'] ?? null, 'lng' => $it['lng'] ?? null,
                'start' => $r['start'] ?? null, 'end' => $r['end'] ?? null, 'travelMin' => $r['travelMin'] ?? null,
                'visitedAt' => $it['visitedAt'] ?? null, 'skippedAt' => $it['skippedAt'] ?? null];
        }
        $next = collect($rows)->first(fn($r) => !$r['visitedAt'] && !$r['skippedAt']);

        return response()->json(['date' => $date->toDateString(), 'day' => $day, 'items' => $rows, 'next' => $next, 'loadPct' => $h['loadPct'] ?? null]);
    }

    public function update(Request $request, int $id, TripPlanner $planner): JsonResponse
    {
        $data = $request->validate(TripPlanner::tripRules() + TripPlanner::itemRules() + ['rev' => [config('planner.require_revision', true) ? 'required' : 'nullable', 'integer', 'min:0']]);
        try {
            $ok = $planner->save($this->uid(), $id, $data, $data['items'] ?? []);
        } catch (TripConflict $e) {
            return response()->json(['code' => 'REVISION_CONFLICT', 'message' => $e->getMessage(), 'conflict' => true, 'rev' => $e->currentRev, 'by' => $e->by], 409);
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
