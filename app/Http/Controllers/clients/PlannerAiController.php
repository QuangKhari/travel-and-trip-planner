<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Ai\Brief\BriefService;
use App\Services\Ai\Copilot\CopilotService;
use App\Services\Ai\Explain\ExplainService;
use App\Services\Planner\TripSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La bàn (trợ lý AI của Trip Planner). Mọi đầu ra AI đều qua kiểm tra ở máy chủ; không route nào ở đây ghi vào chuyến đi.
 *   POST /len-ke-hoach/hieu-yeu-cau        F1: câu mô tả -> vé hành trình (điền form), chưa tạo lịch
 *   POST /len-ke-hoach/{id}/tom-tat-ai     F2: tóm tắt + giải thích lịch hiện tại (bản đã lưu)
 *   POST /len-ke-hoach/{id}/la-ban         F3: sửa lịch bằng lời -> đề xuất đã kiểm chứng (trình duyệt áp dụng + lưu như thường)
 *   POST /len-ke-hoach/{id}/la-ban/kiem-tra-lai   F3: lịch vừa đổi -> ánh xạ + mô phỏng lại đề xuất cũ, không gọi LLM
 *   POST /len-ke-hoach/{id}/la-ban/ket-qua        F3: ghi người dùng Áp dụng / Bỏ qua (đo tỷ lệ chấp nhận)
 * Copilot chỉ cho người SỬA được chuyến (chủ, người được mời sửa); người chỉ xem nhận 404.
 */
class PlannerAiController extends Controller
{
    private function uid(): int
    {
        return (int) session()->get('userId');
    }

    public function brief(Request $request, BriefService $brief): JsonResponse
    {
        $v = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        return response()->json($brief->understand($v['text'], $this->uid() ?: null));
    }

    public function explain(Request $request, int $id, ExplainService $explain): JsonResponse
    {
        // người được mời "chỉ xem" cũng đọc được tóm tắt; người lạ nhận 404 như mọi API của Planner
        $trip = (app(TripSharing::class)->access($this->uid(), $id) ?? abort(404))['trip'];

        return response()->json($explain->forTrip($trip, $this->uid() ?: null, $request->boolean('fresh')));
    }

    public function copilot(Request $request, int $id, CopilotService $copilot): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate([
            'text' => ['required', 'string', 'max:' . (int) config('ai.limits.max_input_chars', 500)],
            'mentions' => ['nullable', 'array', 'max:6'],
            'mentions.*.name' => ['required', 'string', 'max:120'],
            'mentions.*.day' => ['nullable', 'integer', 'min:1', 'max:60'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.role' => ['required', 'string', 'in:user,ai'],
            'history.*.text' => ['required', 'string', 'max:600'],
        ]);

        return response()->json($copilot->chat($trip, $this->uid() ?: null, $v['text'], $v['mentions'] ?? [], $v['history'] ?? []));
    }

    public function recheck(Request $request, int $id, CopilotService $copilot): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate(['proposal' => ['required', 'string', 'alpha_num', 'size:24']]);

        return response()->json($copilot->recheck($trip, $v['proposal']));
    }

    public function feedback(Request $request, int $id, CopilotService $copilot): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate(['proposal' => ['required', 'string', 'alpha_num', 'size:24'], 'applied' => ['required', 'boolean']]);
        $copilot->feedback($trip, $v['proposal'], (bool) $v['applied']);

        return response()->json(['ok' => true]);
    }
}
