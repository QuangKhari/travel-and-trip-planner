<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký Hỏi đáp (tbl_chat_logs). Câu bị từ chối = danh sách chỗ nội dung còn thiếu;
 * số liệu tổng hợp dùng cho chương đánh giá luận văn (xuất CSV).
 * Tab "AI" (tbl_ai_runs): mỗi lượt La bàn một dòng (brief / explain / copilot): tỷ lệ phải lùi, tỷ lệ chấp nhận đề xuất,
 * độ trễ, token, chi phí ước tính (tài liệu v2.2 mục 6.3, 8.2). F5 "Hỏi về chuyến" ghi ở tab Hỏi đáp với mode = trip.
 */
class ChatLogController extends Controller
{
    private function filtered(Request $r)
    {
        $q = DB::table('tbl_chat_logs');
        match ((string) $r->query('f')) {
            'refused' => $q->where('refused', 1),
            'uncited' => $q->where('cited', 0)->where('refused', 0),
            'error' => $q->whereNotNull('error'),
            'cached' => $q->where('cached', 1),
            'trip' => $q->where('mode', 'trip'),
            default => null,
        };
        if ($s = trim((string) $r->query('q', ''))) {
            $q->where('question', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%');
        }
        if ($from = $r->query('from')) {
            $q->where('createdAt', '>=', $from);
        }
        if ($to = $r->query('to')) {
            $q->where('createdAt', '<', date('Y-m-d', strtotime($to . ' +1 day')));
        }

        return $q;
    }

    public function index(Request $request)
    {
        $s = DB::table('tbl_chat_logs')->selectRaw('COUNT(*) total, SUM(refused) refused, SUM(cited) cited, SUM(cached) cached, SUM(error IS NOT NULL) errors, AVG(latencyMs) avgMs')->first();
        $p95 = null;
        if ($s->total > 0) {
            $p95 = DB::table('tbl_chat_logs')->orderBy('latencyMs')->offset((int) floor($s->total * 0.95))->limit(1)->value('latencyMs');
        }
        $topRefused = DB::table('tbl_chat_logs')->where('refused', 1)
            ->selectRaw('question, COUNT(*) n, MAX(createdAt) last')->groupBy('question')->orderByDesc('n')->limit(20)->get();

        return view('admin.chat.index', [
            'title' => 'Nhật ký Hỏi đáp',
            'rows' => $this->filtered($request)->orderByDesc('logId')->paginate(30)->withQueryString(),
            's' => $s, 'p95' => $p95, 'topRefused' => $topRefused,
            'f' => (string) $request->query('f', ''), 'search' => (string) $request->query('q', ''),
            'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''),
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->filtered($request)->orderBy('logId');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM để Excel đọc đúng tiếng Việt
            fputcsv($out, ['logId', 'createdAt', 'mode', 'engine', 'question', 'cited', 'refused', 'cached', 'latencyMs', 'error']);
            foreach ($rows->cursor() as $r) {
                fputcsv($out, [$r->logId, $r->createdAt, $r->mode, $r->engine, $r->question, $r->cited, $r->refused, $r->cached, $r->latencyMs, $r->error]);
            }
            fclose($out);
        }, 'chat-logs-' . date('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ================================================================== tab AI (tbl_ai_runs)

    private const AI_FEATURES = ['brief' => 'F1 Vé hành trình', 'explain' => 'F2 Tóm tắt', 'copilot' => 'F3 Copilot', 'packing' => 'A-13 Đồ mang theo'];
    private const AI_OUTCOMES = ['ok', 'proposed', 'applied', 'skipped', 'reply', 'refused', 'guard_replaced', 'fallback', 'timeout', 'error', 'invalid'];

    private function aiFiltered(Request $r)
    {
        $q = DB::table('tbl_ai_runs');
        if (array_key_exists($f = (string) $r->query('feature'), self::AI_FEATURES)) {
            $q->where('feature', $f);
        }
        if (in_array($o = (string) $r->query('outcome'), self::AI_OUTCOMES, true)) {
            $q->where('outcome', $o);
        }
        if (in_array($e = (string) $r->query('engine'), ['llm', 'rules', 'template', 'cache'], true)) {
            $q->where('engine', $e);
        }
        if ($from = $r->query('from')) {
            $q->where('createdAt', '>=', $from);
        }
        if ($to = $r->query('to')) {
            $q->where('createdAt', '<', date('Y-m-d', strtotime($to . ' +1 day')));
        }

        return $q;
    }

    public function ai(Request $request)
    {
        $has = Schema::hasTable('tbl_ai_runs');
        $stats = [];
        $byOutcome = [];
        if ($has) {
            foreach (self::AI_FEATURES as $f => $label) {
                $q = $this->aiFiltered($request)->where('feature', $f);
                $row = (clone $q)->selectRaw("COUNT(*) n, SUM(engine = 'llm') llm, SUM(engine IN ('rules','template')) fallback,
                    SUM(outcome IN ('timeout','error','invalid')) failed, SUM(outcome = 'guard_replaced') guarded,
                    SUM(outcome = 'applied') applied, SUM(outcome = 'skipped') skipped, SUM(outcome = 'proposed') proposed, SUM(outcome = 'refused') refused,
                    AVG(CASE WHEN engine = 'llm' THEN latencyMs END) avgMs, SUM(inputTokens) tin, SUM(outputTokens) tout")->first();
                $llm = (int) $row->llm;
                $p95 = $llm ? (clone $q)->where('engine', 'llm')->orderBy('latencyMs')->offset((int) floor($llm * 0.95))->limit(1)->value('latencyMs') : null;
                $cost = config('ai.price_in') !== null && config('ai.price_out') !== null
                    ? (float) $row->tin / 1e6 * (float) config('ai.price_in') + (float) $row->tout / 1e6 * (float) config('ai.price_out') : null;
                $stats[$f] = ['label' => $label, 'row' => $row, 'p95' => $p95, 'cost' => $cost,
                    'accept' => ($row->applied + $row->skipped) > 0 ? round($row->applied * 100 / ($row->applied + $row->skipped), 1) : null];
            }
            $byOutcome = $this->aiFiltered($request)->selectRaw('feature, outcome, COUNT(*) n')->groupBy('feature', 'outcome')->orderBy('feature')->orderByDesc('n')->get();
        }

        // A-25: chip "Kết quả có đúng ý bạn không?" — tỷ lệ đúng ý, lý do chưa đúng, ghi chú gần đây
        $fb = ['has' => Schema::hasTable('tbl_ai_feedback'), 'rows' => [], 'reasons' => [], 'recent' => collect()];
        if ($fb['has']) {
            $fq = DB::table('tbl_ai_feedback')->when($request->query('from'), fn($q, $v) => $q->where('createdAt', '>=', $v))
                ->when($request->query('to'), fn($q, $v) => $q->where('createdAt', '<', \Carbon\Carbon::parse($v)->addDay()));
            $fb['rows'] = (clone $fq)->selectRaw('feature, COUNT(*) n, SUM(helpful = 1) yes')->groupBy('feature')->get()->keyBy('feature');
            $fb['reasons'] = (clone $fq)->where('helpful', 0)->whereNotNull('reason')->selectRaw('feature, reason, COUNT(*) n')->groupBy('feature', 'reason')->orderByDesc('n')->get()->groupBy('feature');
            $fb['recent'] = (clone $fq)->where('helpful', 0)->orderByDesc('feedbackId')->limit(10)->get();
        }

        return view('admin.chat.ai', [
            'fb' => $fb,
            'title' => 'Nhật ký AI (La bàn)',
            'has' => $has,
            'stats' => $stats,
            'byOutcome' => $byOutcome,
            'rows' => $has ? $this->aiFiltered($request)->orderByDesc('runId')->paginate(30)->withQueryString() : null,
            'features' => self::AI_FEATURES, 'outcomes' => self::AI_OUTCOMES,
            'q' => ['feature' => (string) $request->query('feature', ''), 'outcome' => (string) $request->query('outcome', ''), 'engine' => (string) $request->query('engine', ''),
                'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', '')],
        ]);
    }

    public function aiExport(Request $request)
    {
        abort_unless(Schema::hasTable('tbl_ai_runs'), 404);
        $rows = $this->aiFiltered($request)->orderBy('runId');
        $cols = ['runId', 'createdAt', 'feature', 'engine', 'outcome', 'model', 'promptVersion', 'userId', 'tripId', 'inputChars', 'inputTokens', 'outputTokens', 'latencyMs', 'guardFlags', 'error'];

        return response()->streamDownload(function () use ($rows, $cols) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $cols);
            foreach ($rows->cursor() as $r) {
                // chống CSV injection: ô bắt đầu bằng = + - @ bị thêm dấu nháy
                fputcsv($out, array_map(fn($c) => is_string($r->$c) && preg_match('/^[=+\-@]/', $r->$c) ? "'" . $r->$c : $r->$c, $cols));
            }
            fclose($out);
        }, 'ai-runs-' . date('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
