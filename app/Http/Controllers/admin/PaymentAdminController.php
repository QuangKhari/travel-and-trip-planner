<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\VnpayService;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Giao dịch VNPay: nhật ký create / return / IPN / querydr để đối soát, và nút hỏi lại VNPay cho từng đơn. */
class PaymentAdminController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Giao dịch VNPay';
        $kind = in_array($request->query('kind'), ['create', 'return', 'ipn', 'querydr'], true) ? $request->query('kind') : null;
        $q = DB::table('tbl_payment_logs as l')->leftJoin('tbl_booking as b', 'b.bookingId', '=', 'l.bookingId')
            ->select('l.*', 'b.bookingCode', 'b.bookingStatus')->orderByDesc('l.logId');
        if ($kind) {
            $q->where('l.kind', $kind);
        }
        if ($request->query('problem')) {
            $q->where(fn($w) => $w->where('l.validSignature', 0)->orWhereIn('l.result', ['01', '04', '97', '99', 'http_error']));
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn($w) => $w->where('l.txnRef', 'like', '%' . addcslashes($s, '%_\\') . '%')->orWhere('b.bookingCode', $s));
        }
        $rows = $q->paginate(30)->withQueryString();

        $today = now()->startOfDay();
        $stats = [
            'paidToday' => DB::table('tbl_checkout')->where('paymentMethod', 'vnpay')->where('paidAt', '>=', $today)->count(),
            'amountToday' => (int) DB::table('tbl_checkout')->where('paymentMethod', 'vnpay')->where('paidAt', '>=', $today)->sum('amount'),
            'pending' => DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')
                ->where('c.paymentMethod', 'vnpay')->where('c.paymentStatus', 'n')->where('b.bookingStatus', 'n')->count(),
            'bad' => DB::table('tbl_payment_logs')->where('createdAt', '>=', now()->subDays(7))
                ->where(fn($w) => $w->where('validSignature', 0)->orWhereIn('result', ['04', '97']))->count(),
        ];
        $enabled = VnpayService::enabled();

        return view('admin.payments.index', compact('title', 'rows', 'kind', 'stats', 'enabled'));
    }

    public function reconcile(Request $request, int $bookingId, VnpayService $vnpay)
    {
        $r = $vnpay->queryAndSettle($bookingId, $request->ip());
        AuditLog::record('payment.reconcile', 'booking', $bookingId, 'Đối soát VNPay: ' . $r['message']);

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }
}
