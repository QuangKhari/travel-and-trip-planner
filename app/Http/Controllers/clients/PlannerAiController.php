<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Ai\Ask\QuestionRouter;
use App\Services\Ai\Ask\TripAskService;
use App\Services\Ai\Brief\BriefService;
use App\Services\Ai\Copilot\CopilotService;
use App\Services\Ai\Copilot\TripSuggest;
use App\Services\Ai\Explain\ExplainService;
use App\Services\Planner\PlannerGate;
use App\Services\Planner\PlannerService;
use App\Services\Planner\TripSharing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La bàn (trợ lý AI của Trip Planner). Mọi đầu ra AI đều qua kiểm tra ở máy chủ; không route nào ở đây ghi vào chuyến đi.
 *   POST /len-ke-hoach/hieu-yeu-cau        F1: câu mô tả -> vé hành trình (điền form), chưa tạo lịch
 *   POST /len-ke-hoach/{id}/tom-tat-ai     F2: tóm tắt + giải thích lịch hiện tại (bản đã lưu)
 *   POST /len-ke-hoach/{id}/la-ban         F3: sửa lịch bằng lời -> đề xuất đã kiểm chứng (trình duyệt áp dụng + lưu như thường)
 *                                          F5: câu hỏi ("ngày 2 có chỗ cho trẻ nhỏ không?") -> trả lời có nguồn (QuestionRouter chia luồng)
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

        // Gợi ý món ăn / địa điểm / lễ hội / cảnh báo: chạy bằng luật trên dữ liệu đã duyệt, trả THẺ để chọn (không cần LLM)
        if (config('ai.features.suggest', true) && ($intent = TripSuggest::intent($v['text'], $v['mentions'] ?? []))) {
            return response()->json(app(TripSuggest::class)->suggest($trip, $intent, TripSuggest::dayOf($v['text'])));
        }

        // F5: câu HỎI (không có động từ sửa lịch) -> trả lời có nguồn từ lịch + cẩm nang, không đổi lịch, không gọi Copilot
        if (config('ai.features.ask', true) && QuestionRouter::isQuestion($v['text'])) {
            return response()->json(app(TripAskService::class)->ask($trip, $this->uid() ?: null, $v['text']));
        }

        return response()->json($copilot->chat($trip, $this->uid() ?: null, $v['text'], $v['mentions'] ?? [], $v['history'] ?? []));
    }

    /** POST /len-ke-hoach/{id}/la-ban/goi-y {intent, day?}: thẻ gợi ý (nút nhanh, và kiểm tra cảnh báo chủ động khi mở trình soạn). */
    public function suggest(Request $request, int $id, TripSuggest $suggest): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        abort_unless(config('ai.features.suggest', true), 404);
        $v = $request->validate(['intent' => ['required', 'in:' . implode(',', TripSuggest::INTENTS)], 'day' => ['nullable', 'integer', 'min:1', 'max:60']]);

        $out = $suggest->suggest($trip, $v['intent'], $v['day'] ?? null);
        // Đợt D (L1): thẻ gợi ý đã hiện (nơi nào, thứ tự nào) để học cá nhân hóa
        $cards = (array) ($out['cards'] ?? []);
        $shown = [];
        foreach ($cards as $i => $c) {
            if (isset($c['apply']['refId'])) {
                $shown[] = ['type' => $c['apply']['type'] ?? null, 'refId' => $c['apply']['refId'], '_i' => $i];
            }
        }
        // Đợt E (L5): thẻ gợi ý xếp lại theo mô hình khi bật; thẻ không phải nơi (lễ hội, lời khuyên) giữ chỗ cũ ở cuối
        $rk = \App\Services\Planner\Reco\RecoRanker::rank($this->uid(), 'suggest', $shown);
        if ($rk['policy'] !== 'rules') {
            $used = [];
            $re = [];
            foreach ($rk['results'] as $r) {
                $used[$r['_i']] = true;
                $re[] = $cards[$r['_i']] + array_intersect_key($r, array_flip(['why', 'explore', 'score', 'team']));
            }
            foreach ($cards as $i => $c) {
                if (!isset($used[$i])) {
                    $re[] = $c;
                }
            }
            $out['cards'] = $re;
        }
        $out['listId'] = \App\Services\Planner\Reco\RecoLog::log($this->uid(), $trip, 'suggest', $rk['results'], ['day' => $v['day'] ?? null], $rk['policy']);

        return response()->json($out);
    }

    /** POST /len-ke-hoach/{id}/la-ban/goi-y/ap-dung {op, type?, refId?, toDay?, item?, startMin?, rev}: nút trên thẻ -> phiếu kiểm chứng. */
    /** Đợt E (AP2): lời giải thích ngắn cho một thẻ cảnh báo — LLM chỉ diễn đạt dữ kiện của thẻ, có chốt chặn và mẫu soạn sẵn. */
    public function alertExplain(Request $request, int $id, TripSuggest $suggest, \App\Services\Ai\Explain\AlertExplainer $ex): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate(['card' => ['required', 'string', 'max:200']]);
        $card = collect($suggest->suggest($trip, 'alerts')['cards'] ?? [])->firstWhere('id', $v['card']);
        if (!$card) {
            return response()->json(['ok' => false, 'message' => 'Cảnh báo này đã hết hoặc lịch vừa đổi. Mở lại thẻ Cảnh báo để xem bản mới.'], 404);
        }

        return response()->json(['ok' => true, 'card' => $card['id']] + $ex->explain($card, $this->uid() ?: null, (int) $trip->tripId));
    }

    public function suggestApply(Request $request, int $id, TripSuggest $suggest, CopilotService $copilot): JsonResponse
    {
        abort_unless(config('ai.features.suggest', true), 404);
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate([
            'op' => ['required', 'in:add,replace,move,time'],
            'type' => ['required_if:op,add,replace', 'nullable', 'in:place,food,event,experience'],
            'refId' => ['required_if:op,add,replace', 'nullable', 'integer', 'min:1'],
            'toDay' => ['nullable', 'integer', 'min:1', 'max:60'],
            'item' => ['required_if:op,replace,move,time', 'nullable', 'string', 'regex:/^i\d{1,4}$/'],
            'startMin' => ['required_if:op,time', 'nullable', 'integer', 'min:300', 'max:1380'],
            'rev' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($suggest->apply($trip, $this->uid() ?: null, $v, $copilot));
    }

    /**
     * POST /len-ke-hoach/{id}/dan-ghi-chu {text}: "Dán ghi chú của bạn, Travela biến thành lịch".
     * AI (hoặc luật khi AI tắt) chỉ TÁCH TÊN nơi có trong ghi chú; khớp với kho đã duyệt của điểm đến của chuyến bằng luật.
     * Trả về: matched (đưa vào "Đã lưu" khi người dùng bấm), unsure (vài lựa chọn để tự chọn), missing (không có trong dữ liệu Travela).
     * Không ghi gì: trình duyệt thêm vào "Đã lưu" và lưu như mọi thay đổi khác (có Hoàn tác).
     */
    public function notes(Request $request, int $id, \App\Services\Planner\NoteImport $notes): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate(['text' => ['required', 'string', 'min:3', 'max:' . \App\Services\Planner\NoteImport::MAX_CHARS]]);
        $dest = array_values(array_unique(array_filter(array_merge(
            [!empty($trip->destinationId) ? (int) $trip->destinationId : null],
            DB::table('tbl_trip_items as i')->join('tbl_external_places as p', fn($j) => $j->on('p.placeId', '=', 'i.refId')->where('i.refType', 'place'))
                ->where('i.tripId', $id)->distinct()->pluck('p.destinationId')->map(fn($x) => (int) $x)->all(),
        ))));
        $ex = $notes->extract($v['text'], $this->uid() ?: null);
        $m = $notes->match($ex['names'], $dest);
        $planner = app(\App\Services\Planner\TripPlanner::class);
        $refs = array_merge($m['matched'], ...array_map(fn($u) => $u['options'], $m['unsure']));
        $res = $refs ? $planner->resolve(array_map(fn($r) => ['type' => $r['type'], 'refId' => $r['refId']], $refs)) : [];
        $card = function (array $r) use ($res) {
            $x = $res[$r['type'] . ':' . $r['refId']] ?? null;
            if (!$x) {
                return null;
            }
            $group = $r['type'] === 'place' ? \App\Services\Planner\PlaceMedia::groupOfKind($x['kind'] ?? null) : null;

            return ['type' => $r['type'], 'refId' => (int) $r['refId'], 'name' => (string) $x['name'], 'kind' => (string) ($x['sub'] ?? ''), 'url' => $x['url'] ?? null,
                'lat' => $x['lat'] ?? null, 'lng' => $x['lng'] ?? null, 'image' => $x['image'] ?? null, 'durationMin' => null,
                'tile' => \App\Services\Planner\PlaceMedia::tile($r['type'], $group), 'said' => $r['said'] ?? null];
        };

        return response()->json([
            'ok' => true, 'engine' => $ex['engine'], 'found' => count($ex['names']),
            'matched' => array_values(array_filter(array_map($card, $m['matched']))),
            'unsure' => array_values(array_filter(array_map(fn($u) => ['text' => $u['text'], 'options' => array_values(array_filter(array_map($card, $u['options'])))], $m['unsure']), fn($u) => $u['options'])),
            'missing' => $m['missing'],
        ]);
    }

    public function recheck(Request $request, int $id, CopilotService $copilot): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate(['proposal' => ['required', 'string', 'alpha_num', 'size:24']]);

        return response()->json($copilot->recheck($trip, $v['proposal']));
    }

    /** GET /la-ban/tri-nho : trí nhớ của chuyến (ai xem được chuyến thì xem được). */
    public function memory(int $id): JsonResponse
    {
        (app(TripSharing::class)->access($this->uid(), $id) ?? abort(404));

        return response()->json(['ok' => true, 'items' => \App\Services\Ai\TripMemory::list($id)]);
    }

    /** POST /la-ban/tri-nho {kind: prefer|avoid|note, text} hoặc {reject: {type, refId, name}} (nút "Không hợp" trên thẻ gợi ý). */
    public function memoryAdd(Request $request, int $id): JsonResponse
    {
        (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404));
        $v = $request->validate([
            'kind' => ['nullable', 'in:prefer,avoid,note'], 'text' => ['nullable', 'string', 'max:160'],
            'reject.type' => ['nullable', 'in:place,food,experience,event'], 'reject.refId' => ['nullable', 'integer', 'min:1'], 'reject.name' => ['nullable', 'string', 'max:120'],
        ]);
        if (!empty($v['reject']['type']) && !empty($v['reject']['refId'])) {
            \App\Services\Ai\TripMemory::reject($id, $v['reject']['type'], (int) $v['reject']['refId'], strip_tags((string) ($v['reject']['name'] ?? 'nơi này')), 'suggest');
        } elseif (!empty($v['kind']) && \App\Services\Ai\TripMemory::add($id, $v['kind'], (string) ($v['text'] ?? '')) === null) {
            return response()->json(['ok' => false, 'message' => 'Nội dung trống hoặc đã có.'], 422);
        }

        return response()->json(['ok' => true, 'items' => \App\Services\Ai\TripMemory::list($id)]);
    }

    /** POST /la-ban/tri-nho/xoa {id?} : xóa một dòng, hoặc xóa hết khi không gửi id. */
    public function memoryForget(Request $request, int $id): JsonResponse
    {
        (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404));
        $mid = $request->integer('id') ?: null;
        \App\Services\Ai\TripMemory::forget($id, $mid);

        return response()->json(['ok' => true, 'items' => \App\Services\Ai\TripMemory::list($id)]);
    }

    public function feedback(Request $request, int $id, CopilotService $copilot): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate(['proposal' => ['required', 'string', 'alpha_num', 'size:24'], 'applied' => ['required', 'boolean']]);
        $copilot->feedback($trip, $v['proposal'], (bool) $v['applied']);

        return response()->json(['ok' => true]);
    }

    /**
     * A-25 POST /la-ban/danh-gia {feature, helpful, reason?, note?, tripId?} : chip "Kết quả có đúng ý bạn không?".
     * Gắn với lượt AI gần nhất (30 phút) của người dùng cho cùng tính năng / chuyến trong tbl_ai_runs để báo cáo được tỷ lệ "đúng ý" theo phiên bản lời nhắc.
     */
    public function rate(Request $request): JsonResponse
    {
        $v = $request->validate([
            'feature' => ['required', 'in:brief,explain,copilot,tour_match'],
            'helpful' => ['required', 'boolean'],
            'reason' => ['nullable', 'in:wrong_place,wrong_time,not_my_taste,too_generic,other'],
            'note' => ['nullable', 'string', 'max:200'],
            'tripId' => ['nullable', 'integer', 'min:1'],
        ]);
        $uid = $this->uid();
        $tripId = !empty($v['tripId']) && app(TripSharing::class)->access($uid, (int) $v['tripId']) ? (int) $v['tripId'] : null;
        if (!\Illuminate\Support\Facades\Schema::hasTable('tbl_ai_feedback')) {
            return response()->json(['ok' => false], 503);
        }
        $runId = DB::table('tbl_ai_runs')->where('userId', $uid)->where('feature', $v['feature'] === 'tour_match' ? 'brief' : $v['feature'])
            ->when($tripId, fn($q) => $q->where('tripId', $tripId))->where('createdAt', '>=', now()->subMinutes(30))->orderByDesc('runId')->value('runId');
        // một người, một lượt AI: chỉ giữ đánh giá mới nhất
        if ($runId) {
            DB::table('tbl_ai_feedback')->where(['userId' => $uid, 'runId' => $runId])->delete();
        }
        DB::table('tbl_ai_feedback')->insert(['userId' => $uid, 'tripId' => $tripId, 'runId' => $runId, 'feature' => $v['feature'], 'helpful' => (bool) $v['helpful'],
            'reason' => $v['helpful'] ? null : ($v['reason'] ?? null), 'note' => isset($v['note']) ? mb_substr(strip_tags((string) $v['note']), 0, 200) : null]);

        return response()->json(['ok' => true, 'runId' => $runId]);
    }

    // ======================= Trợ lý trò chuyện tạo chuyến (kiểu Layla) =======================
    // GET  /len-ke-hoach/tro-ly       trang chat 2 cột
    // POST /len-ke-hoach/tro-ly/doc   đọc các câu đã nói -> 5 ô checklist + câu hỏi kế tiếp (KHÔNG ghi gì)
    // POST /len-ke-hoach/tro-ly/tao   điền ô còn thiếu bằng mặc định, gọi PlannerService::generate + save, trả URL chuyến
    // Dùng lại BriefService (LLM đề xuất, validator kiểm chứng; AI tắt thì lùi về SearchIntent) và đúng đường tạo lịch của PlannerController::generate.

    private const SLOTS = ['to', 'from', 'who', 'when', 'like'];

    public function chatPage()
    {
        return view('clients.planner.ai', [
            'title' => 'Trò chuyện để lên chuyến đi',
            'metaDescription' => 'Kể cho Travela AI nghe chuyến đi bạn muốn, AI hỏi từng bước rồi xếp sẵn lịch trình.',
            'noindex' => true,
            'cfg' => ['read' => route('planner.ai.chat.read'), 'make' => route('planner.ai.chat.make'), 'edit' => route('planner.ai.chat.edit'), 'csrf' => csrf_token(),
                'aiOn' => app(\App\Services\Ai\AiGateway::class)->featureOn('brief')],
        ]);
    }

    public function chatRead(Request $request, BriefService $brief): JsonResponse
    {
        if ($request->boolean('suggest')) {   // "gợi ý giúp mình": một điểm đến đã đủ dữ liệu, ưu tiên nổi bật
            $ready = collect(app(PlannerGate::class)->coreStatus())->where('ready', true)->pluck('destinationId')->all();
            $pick = $ready ? DB::table('tbl_destinations')->where('isActive', 1)->whereIn('destinationId', $ready)->orderByDesc('isFeatured')->orderBy('name')->value('name') : null;

            return response()->json(['pick' => $pick]);
        }
        [$said, $from] = $this->chatInput($request);
        $r = $this->understandCached($brief, $said);
        $p = $r['prefill'] ?? [];
        $cover = null;
        if (!empty($p['destinationId'])) {
            $img = DB::table('tbl_destinations')->where('destinationId', (int) $p['destinationId'])->value('coverImage');
            $cover = $img ? \App\Support\DiscoverImage::url((string) $img) : null;
        }
        $extras = [];
        if (!empty($p['budget'])) {
            $extras[] = 'Ngân sách: ' . number_format((int) $p['budget'], 0, ',', '.') . 'đ';
        }
        foreach ((array) ($r['warnings'] ?? []) as $w) {   // điều Travela chưa lọc được: nói thẳng, không giả vờ đã hiểu
            if (($w['type'] ?? '') === 'unsupported' && !empty($w['text']) && count($extras) < 3) {
                $extras[] = 'Travela chưa lọc được: ' . $w['text'];
            }
        }

        return response()->json([
            'slots' => $this->slots($r, $from),
            'next' => $this->nextQuestion($r, $from),
            'extras' => $extras,
            'cover' => $cover,
            'geo' => $this->geo($p['destinationId'] ?? null, $from),
            'refused' => $r['refused'] ?? null,
            'engine' => $r['engine'] ?? null,
        ]);
    }

    public function chatCreate(Request $request, BriefService $brief, PlannerService $service): JsonResponse
    {
        [$said, $from] = $this->chatInput($request);
        $r = $this->understandCached($brief, $said);
        $p = $r['prefill'] ?? [];
        $filled = [];

        // ô còn thiếu -> mặc định hợp lý, và nói rõ cho người dùng biết là "AI chọn"
        if (empty($p['destinationId'])) {
            $core = collect(app(PlannerGate::class)->coreStatus())->firstWhere('ready', true);
            if (!$core) {
                return response()->json(['ok' => false, 'message' => 'Chưa có điểm đến nào đủ dữ liệu để xếp lịch. Bạn thử chọn cách tự tạo nhé.']);
            }
            $p['destinationId'] = (int) $core['destinationId'];
            $filled[] = 'to';
        }
        if (empty($p['startDate']) && empty($p['month'])) {
            $p['month'] = (int) now()->addMonth()->format('n');
            $filled[] = 'when';
        }
        if (empty($p['days'])) {
            $p['days'] = 3;
            $filled[] = 'when';
        }
        if (empty($p['adults'])) {
            $p['adults'] = 2;
            $p['children'] = 0;
            $filled[] = 'who';
        }
        // Điều chỉnh có chủ đích từ màn hình xác nhận. Cùng một payload được gửi
        // cả khi xem trước lẫn khi tạo, tránh kết quả hiển thị khác kết quả lưu.
        $adjustments = $request->validate([
            'adjustments' => ['sometimes', 'array:days,adults,children,startDate,budget,pace,interests'],
            'adjustments.pace' => ['sometimes', 'nullable', 'in:' . implode(',', array_keys((array) config('planner.pace')))],
            'adjustments.interests' => ['sometimes', 'array', 'max:6'],
            'adjustments.interests.*' => ['string', 'in:' . implode(',', array_keys((array) config('interests')))],
            'adjustments.days' => ['sometimes', 'integer', 'min:1', 'max:' . (int) config('planner.max_days', 7)],
            'adjustments.adults' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'adjustments.children' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'adjustments.startDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'adjustments.budget' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
        ])['adjustments'] ?? [];
        if ((int) ($adjustments['adults'] ?? $p['adults']) + (int) ($adjustments['children'] ?? ($p['children'] ?? 0)) > 50) {
            return response()->json(['ok' => false, 'message' => 'Tổng số người không được vượt quá 50.'], 422);
        }
        foreach (['days', 'adults', 'children', 'budget', 'startDate', 'pace', 'interests'] as $key) {
            if (array_key_exists($key, $adjustments)) {
                $p[$key] = $adjustments[$key];
            }
        }
        if (array_key_exists('startDate', $adjustments) && $adjustments['startDate']) {
            $p['month'] = (int) date('n', strtotime($adjustments['startDate']));
        }
        $notes = array_filter([$p['notes'] ?? null, $from ? 'Xuất phát từ: ' . $from : null]);
        $data = [
            'destinationId' => (int) $p['destinationId'], 'mode' => 'auto',
            'startDate' => $p['startDate'] ?? null, 'month' => (int) ($p['month'] ?? 0) ?: null,
            'days' => max(1, min((int) config('planner.max_days', 7), (int) $p['days'])),
            'adults' => (int) $p['adults'], 'children' => (int) ($p['children'] ?? 0),
            'budget' => $p['budget'] ?? null, 'pace' => $p['pace'] ?? null, 'maxDifficulty' => $p['maxDifficulty'] ?? null,
            'interests' => $p['interests'] ?? [], 'dislikes' => $p['dislikes'] ?? [],
        ];
        if ($data['startDate']) {
            $data['month'] = (int) date('n', strtotime($data['startDate']));
        }
        $data['groupSize'] = $data['adults'] + $data['children'];
        // "bay tới 14h, về chuyến 17h": ngày đầu / ngày cuối chỉ xếp trong giờ còn lại, vé ghi vào "Cần đặt" (luật, không cần AI)
        $tx = \App\Services\Planner\TransferParser::parse(implode('. ', $said));
        if (!empty($tx['arriveAt']) || !empty($tx['leaveAt'])) {
            $data = \App\Services\Planner\TransferParser::minutes($data + $tx + ['moveKind' => $tx['moveKind'] ?? 'flight']);
            $kindLabel = ['flight' => 'máy bay', 'train' => 'tàu', 'bus' => 'xe khách', 'self' => 'tự lái'][$data['moveKind']];
            if (!empty($tx['arriveAt'])) {
                $filled[] = 'Tới nơi ' . $tx['arriveAt'] . ' (' . $kindLabel . ')';
            }
            if (!empty($tx['leaveAt'])) {
                $filled[] = 'Về ' . $tx['leaveAt'] . ' ngày cuối (' . $kindLabel . ')';
            }
        }

        // Xem trước = BẢN NHÁP LỊCH THẬT, chưa lưu: AI chỉ hiểu yêu cầu; bộ xếp lịch (PlannerService, không LLM) tạo lịch;
        // người dùng xem từng ngày, giờ, đường đi rồi mới xác nhận. Bản nháp giữ 30 phút theo mã; xác nhận lưu ĐÚNG bản đã xem
        // (cùng yêu cầu thì không xếp lại, tránh "xem một đằng, lưu một nẻo"). Không ghi gì vào CSDL trước khi xác nhận.
        $hash = self::hashOf($data);
        if (!$request->boolean('confirmed')) {
            $destination = DB::table('tbl_destinations')->where('destinationId', $data['destinationId'])->value('name');
            $draft = null;
            $token = null;
            $gen = $service->generate($data + ['userId' => $this->uid()]);
            if (!empty($gen['ok']) && array_filter($gen['plan']['days'], fn($d) => !empty($d['items']))) {
                $token = Str::random(32);
                Cache::put(self::draftKey($this->uid(), $token), ['hash' => $hash, 'result' => $gen, 'edits' => 0], self::DRAFT_TTL);
                $draft = self::draftView($gen);
            }
            // So sánh nhịp ngay trong hội thoại: cùng yêu cầu, xếp thêm bản "Thong thả" và "Đi nhiều". Mỗi bản có mã nháp riêng,
            // chọn bản nào thì lưu đúng bản đó (mã băm tính trên yêu cầu đã đổi nhịp, giống hệt khi người dùng tự chọn nhịp).
            $compare = [];
            if ($draft && $request->boolean('compare')) {
                foreach (['relaxed' => 'Thong thả', 'packed' => 'Đi nhiều'] as $pace => $label) {
                    if (($data['pace'] ?? 'normal') === $pace) {
                        $compare[] = ['pace' => $pace, 'label' => $label, 'token' => $token, 'draft' => $draft, 'current' => true];
                        continue;
                    }
                    $alt = $data;
                    $alt['pace'] = $pace;   // giữ nguyên thứ tự khóa để mã băm khớp khi người dùng chọn bản này
                    $g2 = $service->generate($alt + ['userId' => $this->uid()]);
                    if (empty($g2['ok']) || !array_filter($g2['plan']['days'], fn($d) => !empty($d['items']))) {
                        continue;
                    }
                    $t2 = Str::random(32);
                    Cache::put(self::draftKey($this->uid(), $t2), ['hash' => self::hashOf($alt), 'result' => $g2, 'edits' => 0], self::DRAFT_TTL);
                    $compare[] = ['pace' => $pace, 'label' => $label, 'token' => $t2, 'draft' => self::draftView($g2), 'current' => false];
                }
            }
            return response()->json([
                'ok' => true, 'review' => true,
                'details' => [
                    'destination' => (string) ($destination ?: 'Điểm đến đã chọn'),
                    'days' => $data['days'],
                    'adults' => $data['adults'], 'children' => $data['children'],
                    'date' => $data['startDate'], 'month' => $data['month'],
                    'budget' => $data['budget'],
                    'arriveAt' => $data['arriveAt'] ?? null, 'leaveAt' => $data['leaveAt'] ?? null, 'moveKind' => $data['moveKind'] ?? null,
                    'pace' => $data['pace'] ?? null, 'interests' => array_values((array) ($data['interests'] ?? [])), 'dislikes' => array_values((array) ($data['dislikes'] ?? [])),
                ],
                'filled' => array_values(array_unique($filled)),
                'draft' => $draft, 'draftToken' => $token, 'compare' => $compare,
                'draftMessage' => $draft ? null : (!empty($gen['ok']) ? 'Chưa đủ nội dung phù hợp để xếp lịch với điều kiện này. Bạn thử đổi ngày hoặc sở thích nhé.' : ($gen['message'] ?? 'Chưa tạo được bản nháp.')),
            ]);
        }

        // Chỉ lưu ĐÚNG bản nháp người dùng vừa xem. Mã nháp hết hạn / đã dùng / yêu cầu đã đổi so với lúc xếp: KHÔNG tự xếp lịch mới rồi lưu
        // (trước đây máy chủ lặng lẽ xếp lại, chuyến được lưu có thể khác cái vừa xem). Trả 409 để trình duyệt xếp lại và cho xem trước.
        $token = self::cleanToken($request->input('draftToken'));
        // Xác nhận chỉ một lần cho mỗi bản nháp: khóa theo mã nháp (bấm Lưu hai lần / hai tab cùng gửi không tạo hai chuyến),
        // bản nháp chỉ bị "dùng" SAU khi lưu thành công (lưu lỗi thì người dùng vẫn bấm lại được đúng bản đã xem).
        $doneKey = $token ? self::draftDoneKey($this->uid(), $token) : null;
        $lock = $token ? Cache::lock('ai:draft:claim:' . $this->uid() . ':' . $token, 30) : null;
        if ($lock && !$lock->block(10)) {
            return response()->json(['ok' => false, 'message' => 'Bản nháp đang được lưu, bạn chờ giây lát rồi thử lại.'], 409);
        }
        try {
            if ($doneKey && ($doneId = Cache::get($doneKey)) && DB::table('tbl_trips')->where('tripId', (int) $doneId)->exists()) {
                return response()->json(['ok' => true, 'duplicate' => true, 'url' => route('planner.edit', ['id' => (int) $doneId])]);
            }
            $cached = $token ? Cache::get(self::draftKey($this->uid(), $token)) : null;
            if (!$cached || ($cached['hash'] ?? null) !== $hash) {
                return response()->json(['ok' => false, 'expired' => true, 'reason' => $cached ? 'changed' : 'expired',
                    'message' => $cached ? 'Thông tin chuyến đã đổi so với bản nháp. Travela xếp lại để bạn xem trước khi lưu.'
                        : 'Bản nháp đã hết hạn (giữ 30 phút). Travela xếp lại để bạn xem trước khi lưu; lịch mới có thể khác một chút.'], 409);
            }
            $result = $cached['result'];
            if (empty($result['ok']) || !array_filter($result['plan']['days'], fn($d) => !empty($d['items']))) {
                return response()->json(['ok' => false, 'message' => 'Bản nháp không còn mục nào trong lịch. Bạn thêm lại hoặc xếp lại bản nháp nhé.']);
            }
            try {
                $id = $service->save($this->uid(), $result);
            } catch (\DomainException $e) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()]);   // bản nháp còn nguyên để thử lại
            }
            Cache::put($doneKey, $id, self::DRAFT_TTL);
            Cache::forget(self::draftKey($this->uid(), $token));
        } finally {
            $lock?->release();
        }
        PlannerController::recordTransfers($id, $data);
        if ($notes && \Illuminate\Support\Facades\Schema::hasColumn('tbl_trips', 'notes')) {
            DB::table('tbl_trips')->where('tripId', $id)->update(['notes' => mb_substr(strip_tags(implode('; ', $notes)), 0, 500)]);
        }

        return response()->json(['ok' => true, 'url' => route('planner.edit', ['id' => $id]),
            'title' => ($result['destination']['name'] ?? 'Chuyến đi') . ' · ' . $data['days'] . ' ngày', 'filled' => array_values(array_unique($filled))]);
    }

    /** Bản nháp giữ 30 phút trong cache theo người dùng + mã; không ghi gì vào CSDL trước khi bấm Lưu. */
    public const DRAFT_TTL = 1800;

    /** Mã nháp đã được lưu thành chuyến nào (để lần bấm thứ hai trả về đúng chuyến đó). */
    public static function draftDoneKey(int $uid, string $token): string
    {
        return 'ai:draft:done:' . $uid . ':' . $token;
    }

    public static function draftKey(int $uid, string $token): string
    {
        return 'pai-draft:' . $uid . ':' . $token;
    }

    /**
     * Mã băm của yêu cầu xếp lịch, đã chuẩn hóa kiểu (3 và "3", null và "normal" là một) để "cùng yêu cầu" so được chắc chắn:
     * khác mã băm nghĩa là người dùng thật sự đổi điều gì đó, khi đó phải xếp lại và cho xem trước.
     */
    public static function hashOf(array $d): string
    {
        foreach (['destinationId', 'days', 'adults', 'children', 'groupSize', 'month', 'arriveMin', 'leaveMin'] as $k) {
            if (isset($d[$k]) && $d[$k] !== '') {
                $d[$k] = (int) $d[$k];
            }
        }
        $d['budget'] = isset($d['budget']) && $d['budget'] !== '' ? (int) $d['budget'] : null;
        $d['pace'] = !empty($d['pace']) ? (string) $d['pace'] : 'normal';
        $d['startDate'] = !empty($d['startDate']) ? (string) $d['startDate'] : null;
        foreach (['interests', 'dislikes'] as $k) {
            $a = array_values(array_unique(array_map('strval', (array) ($d[$k] ?? []))));
            sort($a);
            $d[$k] = $a;
        }
        ksort($d);

        return md5(json_encode($d));
    }

    private static function cleanToken($t): ?string
    {
        $t = preg_replace('/[^A-Za-z0-9]/', '', (string) $t);

        return strlen($t) === 32 ? $t : null;
    }

    /**
     * POST /len-ke-hoach/tro-ly/nhap/sua {draftToken, ops:[{op:remove|move, day, index, toDay?, toIndex?}]}
     * Sửa bản nháp TRƯỚC khi lưu: bỏ điểm (chuyển vào "Đã lưu"), đổi thứ tự, chuyển ngày. Máy chủ giữ bản nháp; sau mỗi thay đổi
     * xếp lại giờ của ngày bị đụng bằng đúng bộ xếp giờ của trang chuyến (DaySchedule), nên bản lưu = bản đang xem.
     * Không gọi LLM, không xếp lại cả chuyến. Mã hết hạn: 409 để trình duyệt xếp lại.
     */
    public function draftEdit(Request $request): JsonResponse
    {
        $v = $request->validate([
            'draftToken' => ['required', 'string', 'size:32', 'alpha_num'],
            'ops' => ['required', 'array', 'min:1', 'max:30'],
            'ops.*.op' => ['required', 'in:remove,move'],
            'ops.*.day' => ['required', 'integer', 'min:1', 'max:60'],
            'ops.*.index' => ['required', 'integer', 'min:0', 'max:60'],
            'ops.*.toDay' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ops.*.toIndex' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);
        $key = self::draftKey($this->uid(), $v['draftToken']);
        $cached = Cache::get($key);
        if (!$cached) {
            return response()->json(['ok' => false, 'expired' => true, 'message' => 'Bản nháp đã hết hạn (giữ 30 phút). Travela xếp lại để bạn xem trước khi lưu.'], 409);
        }
        $res = self::applyDraftOps($cached['result'], $v['ops']);
        if (!$res['ok']) {
            return response()->json(['ok' => false, 'message' => $res['message']], 422);
        }
        $cached['result'] = $res['result'];
        $cached['edits'] = (int) ($cached['edits'] ?? 0) + count($v['ops']);
        Cache::put($key, $cached, self::DRAFT_TTL);   // sửa thì gia hạn lại 30 phút

        return response()->json(['ok' => true, 'draft' => self::draftView($cached['result']), 'said' => $res['said'], 'edits' => $cached['edits']]);
    }

    /**
     * Áp dụng thao tác lên kết quả generate() đang giữ. Thuần dữ liệu + DaySchedule (dễ kiểm thử).
     * @return array{ok:bool,message?:string,result?:array,said?:list<string>}
     */
    public static function applyDraftOps(array $result, array $ops): array
    {
        $days = &$result['plan']['days'];
        $idx = [];
        foreach ($days as $i => $d) {
            $idx[(int) $d['day']] = $i;
        }
        $touched = [];
        $said = [];
        foreach ($ops as $op) {
            $from = $idx[(int) $op['day']] ?? null;
            if ($from === null || !isset($days[$from]['items'][(int) $op['index']])) {
                return ['ok' => false, 'message' => 'Mục này không còn trong bản nháp. Tải lại bản nháp nhé.'];
            }
            $it = $days[$from]['items'][(int) $op['index']];
            if ($op['op'] === 'remove') {
                array_splice($days[$from]['items'], (int) $op['index'], 1);
                $result['plan']['unplaced'][] = ['key' => $it['key'], 'name' => $it['name'], 'type' => $it['type'], 'refId' => (int) ($it['refId'] ?? 0), 'score' => $it['score'] ?? 0,
                    'reasons' => $it['reasons'] ?? [], 'durationMin' => $it['durationMin'] ?? null, 'priceMin' => $it['priceMin'] ?? null, 'why' => 'Bạn bỏ khỏi bản nháp',
                    'osm' => !empty($it['osm']), 'lat' => $it['lat'] ?? null, 'lng' => $it['lng'] ?? null];
                $touched[(int) $op['day']] = true;
                $said[] = 'Đã bỏ ' . $it['name'] . ' khỏi ngày ' . $op['day'] . ' (vẫn nằm trong “Đã lưu” sau khi lưu).';
                continue;
            }
            $toDay = (int) ($op['toDay'] ?? $op['day']);
            $to = $idx[$toDay] ?? null;
            if ($to === null) {
                return ['ok' => false, 'message' => 'Chuyến không có ngày ' . $toDay . '.'];
            }
            array_splice($days[$from]['items'], (int) $op['index'], 1);
            $pos = max(0, min(count($days[$to]['items']), (int) ($op['toIndex'] ?? count($days[$to]['items']))));
            array_splice($days[$to]['items'], $pos, 0, [$it]);
            $touched[(int) $op['day']] = $touched[$toDay] = true;
            $said[] = $toDay === (int) $op['day'] ? 'Đã đổi thứ tự ' . $it['name'] . ' trong ngày ' . $toDay . '.' : 'Đã chuyển ' . $it['name'] . ' sang ngày ' . $toDay . '.';
        }
        unset($days);
        $result = self::retime($result, array_keys($touched));
        foreach (array_keys($touched) as $dn) {
            foreach ($result['plan']['days'][$idx[$dn]]['items'] as $it) {
                if (!empty($it['warn'])) {
                    $said[] = 'Lưu ý ngày ' . $dn . ': ' . $it['name'] . ' ' . $it['warn'] . '.';
                }
            }
        }

        return ['ok' => true, 'result' => $result, 'said' => $said];
    }

    /**
     * Xếp lại giờ các ngày đã sửa bằng DaySchedule (cùng quy tắc trang chuyến): giờ bắt đầu, đi lại tới mục, tổng ngày,
     * cảnh báo giờ mở cửa / không kịp. Chi phí biết trước tính lại theo các mục còn trong lịch.
     */
    private static function retime(array $result, array $dayNos): array
    {
        $req = $result['request'] ?? [];
        $route = \App\Services\Planner\Route\RouteService::make();
        foreach ($result['plan']['days'] as &$day) {
            if (!in_array((int) $day['day'], $dayNos, true)) {
                continue;
            }
            $rows = array_map(fn($it) => ['type' => $it['type'], 'refId' => $it['refId'] ?? null, 'name' => $it['name'], 'kind' => $it['kind'] ?? null,
                'lat' => $it['lat'] ?? null, 'lng' => $it['lng'] ?? null, 'slot' => $it['slot'] ?? null, 'durationMin' => $it['durationMin'] ?? null, 'hours' => $it['hours'] ?? null], $day['items']);
            $ctx = ['arrive' => (int) $day['day'] === 1 ? ($req['arriveMin'] ?? null) : null, 'leave' => (int) $day['day'] === (int) ($req['days'] ?? 0) ? ($req['leaveMin'] ?? null) : null];
            $sch = new \App\Services\Planner\Health\DaySchedule(
                (int) $day['day'],
                $rows,
                $route,
                null,
                !empty($req['startDate']) ? \App\Services\Planner\OpeningHours::dowOf($req['startDate'], (int) $day['day']) : null,
                $ctx,
            );
            foreach ($day['items'] as $i => &$it) {
                $r = $sch->rows[$i] ?? null;
                if (!$r) {
                    continue;
                }
                $prev = $i > 0 ? ($sch->rows[$i - 1] ?? null) : null;
                $it['startMin'] = (int) $r['start'];
                $it['durationMin'] = (int) $r['dur'];
                $it['travelMin'] = (int) ($prev['travelMin'] ?? 0);
                $it['travelKm'] = round((float) ($prev['travelKm'] ?? 0), 1);
                $it['warn'] = match (true) {
                    $r['hours'] === 'closed' => 'đóng cửa ngày này',
                    $r['hours'] === 'late' => 'tới sau giờ đóng cửa',
                    $r['conflict'] === 'departure' => 'không kịp trước giờ phải rời đi',
                    in_array($r['conflict'], ['overlap', 'travel'], true) => 'không kịp đi tới',
                    default => null,
                };
            }
            unset($it);
            $day['activityMin'] = (int) $sch->activityMin;
            $day['travelMin'] = (int) $sch->travelMin;
        }
        unset($day);
        if (!empty($result['plan']['budget']) && isset($req['adults'])) {
            $engine = app(\App\Services\Planner\PlannerEngine::class);
            $known = 0.0;
            foreach ($result['plan']['days'] as $d) {
                foreach ($d['items'] as $it) {
                    $known += (float) ($engine->costOf($it, $req + ['children' => 0]) ?? 0);
                }
            }
            $result['plan']['budget']['knownMin'] = $known;
        }

        return $result;
    }

    /**
     * Bản nháp gọn cho khung chat: từng ngày, từng mục (giờ, đi lại tới mục, lý do chọn tối đa 3 ý, ảnh hoặc ô màu theo nhóm,
     * nhãn mức tin cậy), tổng đi lại, chi phí đã biết.
     * @return array<string,mixed>
     */
    public static function draftView(array $r): array
    {
        $media = \App\Services\Planner\PlaceMedia::forItems(array_merge(...array_map(fn($d) => $d['items'], $r['plan']['days'] ?: [['items' => []]])));
        $days = [];
        foreach ($r['plan']['days'] as $d) {
            $items = [];
            foreach ($d['items'] as $it) {
                $m = $media[$it['key'] ?? ''] ?? [];
                $price = isset($it['priceMin']) && $it['priceMin'] !== null ? (float) $it['priceMin'] : null;
                $items[] = ['key' => (string) ($it['key'] ?? ''), 'name' => (string) $it['name'], 'type' => (string) $it['type'], 'group' => $it['group'] ?? null, 'start' => (int) $it['startMin'], 'end' => (int) $it['startMin'] + (int) $it['durationMin'],
                    'travelMin' => (int) ($it['travelMin'] ?? 0), 'travelKm' => (float) ($it['travelKm'] ?? 0), 'osm' => !empty($it['osm']),
                    'price' => $price,
                    'reasons' => array_slice(array_values((array) ($it['reasons'] ?? [])), 0, 3),
                    'image' => $m['image'] ?? null, 'blurb' => $m['blurb'] ?? null, 'tile' => \App\Services\Planner\PlaceMedia::tile($it['type'], $it['group'] ?? null),
                    'trust' => \App\Services\Planner\PlaceMedia::trust($it, $m),
                    'warn' => $it['warn'] ?? null,
                    'lat' => isset($it['lat']) && empty($it['coordsApprox']) ? (float) $it['lat'] : null, 'lng' => isset($it['lng']) && empty($it['coordsApprox']) ? (float) $it['lng'] : null];
            }
            $days[] = ['day' => (int) $d['day'], 'activityMin' => (int) ($d['activityMin'] ?? 0), 'travelMin' => (int) ($d['travelMin'] ?? 0), 'items' => $items];
        }
        $b = $r['plan']['budget'] ?? [];
        $count = array_sum(array_map(fn($d) => count($d['items']), $days));

        return ['destination' => $r['destination']['name'] ?? null, 'startDate' => $r['request']['startDate'] ?? null, 'days' => $days,
            'knownCost' => (int) round($b['knownMin'] ?? 0), 'costNote' => $b['message'] ?? null, 'unplaced' => count($r['plan']['unplaced'] ?? []),
            'travelMin' => array_sum(array_column($days, 'travelMin')), 'count' => $count,
            // số đo để so sánh hai nhịp đặt cạnh nhau
            'activityMin' => array_sum(array_column($days, 'activityMin')),
            'perDay' => $days ? round($count / count($days), 1) : 0,
            'pace' => $r['request']['pace'] ?? null];
    }

    /** @return array{0:list<string>,1:?string} */
    private function chatInput(Request $request): array
    {
        $v = $request->validate(['said' => ['nullable', 'array', 'max:12'], 'said.*' => ['string', 'max:500'], 'from' => ['nullable', 'string', 'max:80']]);
        $said = array_values(array_filter(array_map(fn($s) => BriefService::clean((string) $s), $v['said'] ?? []), fn($s) => $s !== ''));
        $from = isset($v['from']) ? trim(strip_tags($v['from'])) : null;

        return [$said, $from !== '' ? $from : null];
    }

    /** Mỗi lượt gửi lại cả hội thoại; cache 10 phút theo nội dung để lượt trả lời "xuất phát từ" (không đổi gì) không tốn thêm lượt AI. */
    private function understandCached(BriefService $brief, array $said): ?array
    {
        if (!$said) {
            return null;
        }
        $text = implode('. ', $said);

        return Cache::remember('pai:' . $this->uid() . ':' . md5($text), 600, fn() => $brief->understand($text, $this->uid() ?: null));
    }

    /** Toạ độ [lat, lon] một số thành phố hay dùng làm nơi xuất phát (khớp không dấu, theo chuỗi con). */
    private const CITIES = [
        'ha noi' => [21.03, 105.85], 'hcm' => [10.78, 106.70], 'ho chi minh' => [10.78, 106.70], 'sai gon' => [10.78, 106.70],
        'da nang' => [16.05, 108.20], 'can tho' => [10.03, 105.78], 'hai phong' => [20.86, 106.68], 'hue' => [16.46, 107.59],
        'nha trang' => [12.24, 109.19], 'da lat' => [11.94, 108.44], 'vinh' => [18.68, 105.68], 'quy nhon' => [13.78, 109.22],
        'phu quoc' => [10.23, 103.96], 'buon ma thuot' => [12.67, 108.04], 'ha long' => [20.95, 107.08], 'quang ninh' => [21.01, 107.29],
        'sapa' => [22.34, 103.84], 'sa pa' => [22.34, 103.84], 'ha giang' => [22.82, 104.98], 'vung tau' => [10.35, 107.08], 'thanh hoa' => [19.81, 105.78],
    ];

    /** Điểm đến + nơi xuất phát -> toạ độ, khoảng cách đường chim bay và hướng la bàn (độ, 0 = Bắc). Thiếu dữ liệu thì để null, không đoán. */
    private function geo($destinationId, ?string $from): array
    {
        $to = null;
        if ($destinationId) {
            $d = DB::table('tbl_destinations')->where('destinationId', (int) $destinationId)->first(['latitude', 'longitude']);
            if ($d && $d->latitude !== null && $d->longitude !== null) {
                $to = [(float) $d->latitude, (float) $d->longitude];
            }
        }
        $fr = null;
        if ($from) {
            $n = str_replace('đ', 'd', mb_strtolower(\Illuminate\Support\Str::ascii($from)));
            foreach (self::CITIES as $name => $c) {
                if (str_contains($n, $name)) {
                    $fr = $c;
                    break;
                }
            }
            if (!$fr) {   // nơi xuất phát trùng tên một điểm đến của Travela
                $d = DB::table('tbl_destinations')->where('isActive', 1)->where('name', 'like', '%' . str_replace(['%', '_'], '', $from) . '%')->first(['latitude', 'longitude']);
                $fr = $d && $d->latitude !== null ? [(float) $d->latitude, (float) $d->longitude] : null;
            }
        }
        $km = $deg = null;
        if ($to && $fr) {
            [$a1, $o1, $a2, $o2] = [deg2rad($fr[0]), deg2rad($fr[1]), deg2rad($to[0]), deg2rad($to[1])];
            $h = sin(($a2 - $a1) / 2) ** 2 + cos($a1) * cos($a2) * sin(($o2 - $o1) / 2) ** 2;
            $km = (int) round(6371 * 2 * asin(min(1, sqrt($h))));
            $deg = (int) round(fmod(rad2deg(atan2(sin($o2 - $o1) * cos($a2), cos($a1) * sin($a2) - sin($a1) * cos($a2) * cos($o2 - $o1))) + 360, 360));
        }

        return ['to' => $to, 'from' => $fr, 'km' => $km, 'deg' => $deg];
    }

    private function slots(?array $r, ?string $from): array
    {
        $p = $r['prefill'] ?? [];
        $f = $r['fields'] ?? [];
        $when = null;
        if ((!empty($p['startDate']) || !empty($p['month'])) && !empty($p['days'])) {
            $when = (!empty($p['startDate']) ? date('d/m/Y', strtotime($p['startDate'])) : 'Tháng ' . $p['month']) . ', ' . $p['days'] . ' ngày';
        }
        $like = null;
        if (!empty($p['interests'])) {
            $like = implode(' · ', array_map(function ($k) {
                $c = config('interests.' . $k);

                return is_array($c) ? ($c['label'] ?? $c['name'] ?? $k) : (is_string($c) ? $c : $k);
            }, (array) $p['interests']));
        }

        return [
            'to' => !empty($p['destinationId']) ? ($f['destination']['value'] ?? null) : null,
            'from' => $from,
            'who' => isset($p['adults']) ? $p['adults'] . ' người lớn' . (!empty($p['children']) ? ', ' . $p['children'] . ' trẻ em' : '') : null,
            'when' => $when,
            'like' => $like,
        ];
    }

    /** Ô còn thiếu đầu tiên theo thứ tự checklist -> câu hỏi + gợi ý bấm nhanh (label hiển thị, text gửi đi). null = đã đủ. */
    private function nextQuestion(?array $r, ?string $from): ?array
    {
        $s = $this->slots($r, $from);
        $slot = collect(self::SLOTS)->first(fn($k) => empty($s[$k]));
        if ($slot === null) {
            return null;
        }
        $chip = fn(array $a) => array_map(fn($x) => ['label' => $x, 'text' => $x], $a);
        $m = (int) now()->addMonth()->format('n');

        return match ($slot) {
            'to' => ['slot' => 'to', 'q' => 'Bạn muốn đi đâu? Chưa có ý tưởng thì cứ chọn một gợi ý hoặc nói “gợi ý giúp mình”.',
                'chips' => array_merge($chip(DB::table('tbl_destinations')->where('isActive', 1)->orderByDesc('isFeatured')->orderByDesc('isHero')->orderBy('name')->limit(3)->pluck('name')->all()), [['label' => 'Gợi ý giúp mình', 'text' => '__suggest']])],
            'from' => ['slot' => 'from', 'q' => 'Bạn xuất phát từ đâu? Mình ghi lại để bạn tiện tính đường đi.', 'chips' => $chip(['Hà Nội', 'TP.HCM', 'Đà Nẵng', 'Cần Thơ'])],
            'who' => ['slot' => 'who', 'q' => 'Ai đi cùng bạn?', 'chips' => [
                ['label' => 'Đi một mình', 'text' => '1 người lớn'], ['label' => 'Cặp đôi', 'text' => '2 người lớn'],
                ['label' => 'Gia đình có trẻ nhỏ', 'text' => '2 người lớn 1 trẻ em'], ['label' => 'Nhóm bạn 4 người', 'text' => '4 người lớn']]],
            'when' => ['slot' => 'when', 'q' => 'Bạn đi khi nào và đi mấy ngày? Ví dụ: “tháng ' . $m . ', 3 ngày”.', 'chips' => $chip(['Tháng ' . $m . ', 3 ngày', 'Tháng ' . $m . ', 4 ngày', 'Tháng ' . $m . ', 5 ngày'])],
            default => ['slot' => 'like', 'q' => 'Cuối cùng, chuyến đi này nên có gì? Ẩm thực, biển, văn hóa, nghỉ ngơi…',
                'chips' => $chip(['ẩm thực địa phương', 'biển và nghỉ dưỡng', 'văn hóa, di sản', 'thiên nhiên, trekking'])],
        };
    }
}
