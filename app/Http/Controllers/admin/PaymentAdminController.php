<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\OnlinePaymentService;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thanh toán trực tuyến (VNPay, MoMo, QR chuyển khoản): nhật ký tạo giao dịch / khách quay về / thông báo của cổng / đối soát,
 * nút hỏi lại cổng cho từng đơn, và nút xác nhận đã nhận tiền cho đơn chuyển khoản QR.
 */
class PaymentAdminController extends Controller
{
    public const KINDS = ['create', 'return', 'notify', 'query', 'manual'];

    public function index(Request $request, OnlinePaymentService $payments)
    {
        $title = 'Thanh toán trực tuyến';
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : null;
        $provider = in_array($request->query('provider'), ['vnpay', 'momo', 'qr', 'zalopay'], true) ? $request->query('provider') : null;
        $q = DB::table('tbl_payment_logs as l')->leftJoin('tbl_booking as b', 'b.bookingId', '=', 'l.bookingId')
            ->select('l.*', 'b.bookingCode', 'b.bookingStatus')->orderByDesc('l.logId');
        if ($kind) {
            $q->where('l.kind', $kind);
        }
        if ($provider) {
            $q->where('l.provider', $provider);
        }
        if ($request->query('problem')) {
            $q->where(fn($w) => $w->where('l.validSignature', 0)->orWhereIn('l.result', ['bad_signature', 'bad_amount', 'not_found', 'unmatched', 'error', 'http_error', 'rejected']));
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn($w) => $w->where('l.txnRef', 'like', '%' . addcslashes($s, '%_\\') . '%')->orWhere('b.bookingCode', $s));
        }
        $rows = $q->paginate(30)->withQueryString();

        $online = ['vnpay', 'momo', 'qr'];
        $today = now()->startOfDay();
        $stats = [
            'paidToday' => DB::table('tbl_checkout')->whereIn('paymentMethod', $online)->where('paidAt', '>=', $today)->count(),
            'amountToday' => (int) DB::table('tbl_checkout')->whereIn('paymentMethod', $online)->where('paidAt', '>=', $today)->sum('amount'),
            'pending' => DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')
                ->whereIn('c.paymentMethod', $online)->where('c.paymentStatus', 'n')->where('b.bookingStatus', 'n')->count(),
            'bad' => DB::table('tbl_payment_logs')->where('createdAt', '>=', now()->subDays(7))
                ->where(fn($w) => $w->where('validSignature', 0)->orWhereIn('result', ['bad_signature', 'bad_amount']))->count(),
        ];
        // Đơn QR đang chờ: admin đối chiếu sao kê rồi bấm "Đã nhận tiền" (khi chưa nối SePay hoặc webhook không khớp)
        $qrPending = DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')
            ->where('c.paymentMethod', 'qr')->where('c.paymentStatus', 'n')->whereIn('b.bookingStatus', ['n', 'y'])
            ->orderBy('b.holdExpiresAt')->limit(20)->get(['b.bookingId', 'b.bookingCode', 'b.holdExpiresAt', 'b.bookingStatus', 'c.amount', 'c.providerTxnRef']);
        $gateways = [];
        foreach ($online as $code) {
            $g = $payments->gateway($code);
            $gateways[$code] = ['label' => $g->label(), 'on' => $g->enabled(), 'notify' => OnlinePaymentService::publicRoute('payment.notify', ['method' => $code])];
        }
        $gateways['qr']['auto'] = $payments->gateway('qr')->autoConfirm();

        return view('admin.payments.index', compact('title', 'rows', 'kind', 'provider', 'stats', 'gateways', 'qrPending'));
    }

    public function reconcile(Request $request, int $bookingId, OnlinePaymentService $payments)
    {
        $r = $payments->queryAndSettle($bookingId, $request->ip());
        AuditLog::record('payment.reconcile', 'booking', $bookingId, 'Đối soát thanh toán: ' . $r['message']);

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }

    /** Xác nhận đã nhận tiền chuyển khoản (QR): admin ghi mã tham chiếu trên sao kê. */
    public function confirm(Request $request, int $bookingId, OnlinePaymentService $payments)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:40']], ['reference.required' => 'Nhập mã giao dịch trên sao kê ngân hàng để đối chiếu sau này.']);
        $r = $payments->confirmManual($bookingId, trim($data['reference']), $request->ip());

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }
}
