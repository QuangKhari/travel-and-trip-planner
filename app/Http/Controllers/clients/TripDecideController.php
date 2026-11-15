<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripDecide;
use App\Services\Planner\TripSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /len-ke-hoach/{id}/cung-quyet-dinh: bỏ phiếu trước khi có lịch + ai đi (TripDecide).
 * Ai ở trong chuyến cũng xem, đề xuất, bỏ phiếu, báo đi / không đi; tạo câu hỏi, chốt, xóa: chủ chuyến hoặc người được sửa.
 */
class TripDecideController extends Controller
{
    public function __construct(private TripDecide $decide) {}

    private function uid(): int
    {
        return (int) session('userId');
    }

    /** @return array{0:object,1:string} */
    private function access(int $id, bool $edit = false): array
    {
        TripDecide::ready() || abort(404);
        $a = app(TripSharing::class)->access($this->uid(), $id) ?? abort(404);
        if ($edit && !in_array($a['role'], ['owner', 'edit'], true)) {
            abort(403, 'Chỉ chủ chuyến hoặc người được sửa mới làm được việc này.');
        }

        return [$a['trip'], $a['role']];
    }

    private function out(object $trip, string $role, array $extra = []): JsonResponse
    {
        $fresh = DB::table('tbl_trips')->where('tripId', $trip->tripId)->first();

        return response()->json(['ok' => true] + $extra + $this->decide->state($fresh, $this->uid(), $role));
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function show(int $id): JsonResponse
    {
        [$trip, $role] = $this->access($id);

        return $this->out($trip, $role);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        [$trip, $role] = $this->access($id, true);
        $v = $request->validate(['kind' => 'required|in:date,destination,stay,other', 'question' => 'nullable|string|max:160',
            'options' => 'nullable|array|max:8', 'options.*.label' => 'nullable|string|max:160', 'options.*.value' => 'nullable|string|max:255']);

        return $this->run(function () use ($trip, $role, $v) {
            $this->decide->create($trip, $this->uid(), $v['kind'], (string) ($v['question'] ?? ''), $v['options'] ?? []);

            return $this->out($trip, $role, ['said' => 'Đã tạo câu hỏi. Mọi người trong chuyến có thể thêm lựa chọn và bỏ phiếu.']);
        });
    }

    public function option(Request $request, int $id, int $pollId): JsonResponse
    {
        [$trip, $role] = $this->access($id);
        $v = $request->validate(['label' => 'nullable|string|max:160', 'value' => 'nullable|string|max:255']);

        return $this->run(function () use ($trip, $role, $pollId, $v) {
            $this->decide->addOption($trip, $this->uid(), $pollId, (string) ($v['label'] ?? ''), $v['value'] ?? null);

            return $this->out($trip, $role);
        });
    }

    public function vote(Request $request, int $id, int $pollId): JsonResponse
    {
        [$trip, $role] = $this->access($id);
        $v = $request->validate(['optionId' => 'nullable|integer|min:1']);

        return $this->run(function () use ($trip, $role, $pollId, $v) {
            $this->decide->vote($trip, $this->uid(), $pollId, isset($v['optionId']) ? (int) $v['optionId'] : null);

            return $this->out($trip, $role);
        });
    }

    public function close(Request $request, int $id, int $pollId): JsonResponse
    {
        [$trip, $role] = $this->access($id, true);
        $v = $request->validate(['optionId' => 'nullable|integer|min:1']);

        return $this->run(function () use ($trip, $role, $pollId, $v) {
            $r = $this->decide->close($trip, $this->uid(), $pollId, isset($v['optionId']) ? (int) $v['optionId'] : null);

            return $this->out($trip, $role, ['said' => 'Đã chốt “' . $r['label'] . '”. ' . $r['applied'], 'reload' => $r['applied'] !== '']);
        });
    }

    public function destroy(int $id, int $pollId): JsonResponse
    {
        [$trip, $role] = $this->access($id, true);

        return $this->run(function () use ($trip, $role, $pollId) {
            $this->decide->delete($trip, $pollId);

            return $this->out($trip, $role);
        });
    }

    public function rsvp(Request $request, int $id): JsonResponse
    {
        [$trip, $role] = $this->access($id);
        $v = $request->validate(['rsvp' => 'required|in:going,maybe,no']);

        return $this->run(function () use ($trip, $role, $v) {
            $this->decide->rsvp($trip, $this->uid(), $v['rsvp']);

            return $this->out($trip, $role);
        });
    }

    /** Đặt số người của chuyến = số người "đi" + khách không có tài khoản (chi phí, vé, phòng tính theo số này). */
    public function syncGroup(int $id): JsonResponse
    {
        [$trip, $role] = $this->access($id, true);
        $p = $this->decide->people($trip);
        $n = max(1, min(50, $p['going'] + $p['guests']));
        DB::table('tbl_trips')->where('tripId', $trip->tripId)->update(['groupSize' => $n, 'rev' => DB::raw('rev + 1'), 'lastEditorId' => $this->uid(), 'updatedAt' => now()]);

        return $this->out($trip, $role, ['said' => 'Số người của chuyến: ' . $n . '.', 'reload' => true]);
    }
}
