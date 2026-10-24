<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Nhật ký Hỏi đáp (tbl_chat_logs). Câu bị từ chối = danh sách chỗ nội dung còn thiếu;
 * số liệu tổng hợp dùng cho chương đánh giá luận văn (xuất CSV).
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
}
