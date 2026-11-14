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

        return response()->json($suggest->suggest($trip, $v['intent'], $v['day'] ?? null));
    }

    /** POST /len-ke-hoach/{id}/la-ban/goi-y/ap-dung {op, type?, refId?, toDay?, item?, startMin?, rev}: nút trên thẻ -> phiếu kiểm chứng. */
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
            'cfg' => ['read' => route('planner.ai.chat.read'), 'make' => route('planner.ai.chat.make'), 'csrf' => csrf_token(),
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
            'adjustments' => ['sometimes', 'array:days,adults,children,startDate,budget'],
            'adjustments.days' => ['sometimes', 'integer', 'min:1', 'max:' . (int) config('planner.max_days', 7)],
            'adjustments.adults' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'adjustments.children' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'adjustments.startDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'adjustments.budget' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
        ])['adjustments'] ?? [];
        if ((int) ($adjustments['adults'] ?? $p['adults']) + (int) ($adjustments['children'] ?? ($p['children'] ?? 0)) > 50) {
            return response()->json(['ok' => false, 'message' => 'Tổng số người không được vượt quá 50.'], 422);
        }
        foreach (['days', 'adults', 'children', 'budget', 'startDate'] as $key) {
            if (array_key_exists($key, $adjustments)) $p[$key] = $adjustments[$key];
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

        // Lần đầu chỉ xem trước. Chỉ lần xác nhận thứ hai mới chạy engine và ghi DB.
        // Frontend gửi lại toàn bộ câu người dùng; cache BriefService giữ kết quả AI ổn định trong 10 phút.
        if (!$request->boolean('confirmed')) {
            $destination = DB::table('tbl_destinations')->where('destinationId', $data['destinationId'])->value('name');
            return response()->json([
                'ok' => true, 'review' => true,
                'details' => [
                    'destination' => (string) ($destination ?: 'Điểm đến đã chọn'),
                    'days' => $data['days'],
                    'adults' => $data['adults'], 'children' => $data['children'],
                    'date' => $data['startDate'], 'month' => $data['month'],
                    'budget' => $data['budget'],
                    'arriveAt' => $data['arriveAt'] ?? null, 'leaveAt' => $data['leaveAt'] ?? null, 'moveKind' => $data['moveKind'] ?? null,
                ],
                'filled' => array_values(array_unique($filled)),
            ]);
        }

        $result = $service->generate($data);
        if (empty($result['ok'])) {
            return response()->json(['ok' => false, 'message' => $result['message'] ?? 'Không thể tạo lịch trình.']);
        }
        if (!array_filter($result['plan']['days'], fn($d) => !empty($d['items']))) {
            return response()->json(['ok' => false, 'message' => 'Chưa đủ nội dung phù hợp để xếp lịch với điều kiện này. Bạn thử đổi ngày hoặc sở thích nhé.']);
        }
        try {
            $id = $service->save($this->uid(), $result);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
        PlannerController::recordTransfers($id, $data);
        if ($notes && \Illuminate\Support\Facades\Schema::hasColumn('tbl_trips', 'notes')) {
            DB::table('tbl_trips')->where('tripId', $id)->update(['notes' => mb_substr(strip_tags(implode('; ', $notes)), 0, 500)]);
        }

        return response()->json(['ok' => true, 'url' => route('planner.edit', ['id' => $id]),
            'title' => ($result['destination']['name'] ?? 'Chuyến đi') . ' · ' . $data['days'] . ' ngày', 'filled' => array_values(array_unique($filled))]);
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
