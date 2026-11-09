<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentReconciliationService;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Đối soát booking – thanh toán – hoàn tiền: xem các điểm không khớp, quét lại, bỏ qua có ghi chú. */
class ReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Đối soát thanh toán';
        $ready = PaymentReconciliationService::ready();
        $status = in_array($request->query('status'), ['open', 'ignored', 'resolved', 'all'], true) ? $request->query('status') : 'open';
        $severity = in_array($request->query('severity'), ['high', 'medium', 'low'], true) ? $request->query('severity') : null;
        $rows = collect();
        $counts = [];
        if ($ready) {
            $q = DB::table('tbl_reconciliation_findings as f')->leftJoin('tbl_booking as b', 'b.bookingId', '=', 'f.bookingId')
                ->select('f.*', 'b.bookingCode')
                ->orderByRaw("FIELD(f.severity, 'high', 'medium', 'low')")->orderByDesc('f.lastSeenAt');
            if ($status !== 'all') {
                $q->where('f.status', $status);
            }
            if ($severity) {
                $q->where('f.severity', $severity);
            }
            $rows = $q->paginate(30)->withQueryString();
            $counts = DB::table('tbl_reconciliation_findings')->where('status', 'open')->selectRaw('severity, COUNT(*) n')->groupBy('severity')->pluck('n', 'severity')->all();
        }
        $types = PaymentReconciliationService::TYPES;

        return view('admin.reconciliation.index', compact('title', 'ready', 'rows', 'counts', 'status', 'severity', 'types'));
    }

    public function run(PaymentReconciliationService $svc)
    {
        $r = $svc->sync();
        AuditLog::record('reconcile.run', 'reconciliation', 0, "Quét đối soát: {$r['found']} điểm không khớp, {$r['new']} mới, {$r['resolved']} tự đóng");

        return back()->with('success', "Đã quét: {$r['found']} điểm không khớp ({$r['new']} mới, {$r['resolved']} tự đóng, {$r['open']} đang mở).");
    }

    public function ignore(Request $request, int $id, PaymentReconciliationService $svc)
    {
        $d = $request->validate(['note' => 'required|string|min:5|max:255'], ['note.*' => 'Phải ghi lý do bỏ qua (tối thiểu 5 ký tự).']);
        $ok = $svc->ignore($id, (int) session('adminId'), $d['note']);
        if ($ok) {
            AuditLog::record('reconcile.ignore', 'reconciliation', $id, 'Bỏ qua điểm không khớp: ' . $d['note']);
        }

        return back()->with($ok ? 'success' : 'error', $ok ? 'Đã bỏ qua, lý do được lưu.' : 'Không tìm thấy mục cần bỏ qua.');
    }

    public function reopen(int $id, PaymentReconciliationService $svc)
    {
        $ok = $svc->reopen($id);
        if ($ok) {
            AuditLog::record('reconcile.reopen', 'reconciliation', $id, 'Mở lại điểm không khớp');
        }

        return back()->with($ok ? 'success' : 'error', $ok ? 'Đã mở lại.' : 'Không mở lại được.');
    }
}
