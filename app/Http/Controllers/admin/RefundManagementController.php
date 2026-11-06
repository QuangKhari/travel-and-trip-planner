<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayRefundService;
use App\Services\RefundService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use App\Support\BookingPii;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hoàn tiền (A6): danh sách yêu cầu hoàn tiền của đơn đã thanh toán bị hủy, admin chuyển tiền rồi đánh dấu "Đã hoàn".
 */
class RefundManagementController extends Controller
{
    public function index(Request $request, GatewayRefundService $gateway)
    {
        $title = 'Hoàn tiền';
        $status = in_array($request->query('status'), ['pending', 'refunded', 'all'], true) ? $request->query('status') : 'pending';

        $q = DB::table('tbl_refunds as r')
            ->join('tbl_booking as b', 'b.bookingId', '=', 'r.bookingId')
            ->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->leftJoin('tbl_admin as a', 'a.adminId', '=', 'r.processedBy')
            ->select('r.*', 'b.bookingCode', 'b.fullName', 'b.email', 'b.phoneNumber', 'b.departureDate', 'b.cancelReason', 't.title', 't.startDate', 'a.userName as processedName')
            ->orderByRaw("r.status = 'pending' desc")->orderByDesc('r.createdAt');
        if ($status !== 'all') {
            $q->where('r.status', $status);
        }
        $rows = $q->paginate(20)->withQueryString();
        BookingPii::decryptAll($rows->items());
        foreach ($rows->items() as $r) {
            // Nút "Hoàn qua cổng" chỉ hiện khi hồ sơ đủ điều kiện (cổng có API hoàn, có mã giao dịch gốc, tính năng đã bật)
            $chk = $r->status === 'pending' && empty($r->gatewayStatus) ? $gateway->check($r) : ['ok' => false, 'message' => '', 'gateway' => null];
            $r->gw = ['can' => $chk['ok'], 'label' => $chk['gateway']?->label(), 'why' => $chk['message']];
        }

        $sum = DB::table('tbl_refunds')->selectRaw("status, COUNT(*) n, COALESCE(SUM(amount),0) total")->groupBy('status')->get()->keyBy('status');

        $failed = (int) DB::table('tbl_refunds')->where('status', 'pending')->where('gatewayStatus', 'failed')->count();
        $processing = (int) DB::table('tbl_refunds')->where('status', 'pending')->where('gatewayStatus', 'processing')->count();

        return view('admin.refunds.index', compact('title', 'rows', 'status', 'sum', 'failed', 'processing'));
    }

    public function complete(Request $request, int $id, RefundService $refunds)
    {
        $data = $request->validate([
            'method' => 'required|string|in:Chuyển khoản,Tiền mặt,Ví điện tử,Khác',
            'reference' => 'required_if:method,Chuyển khoản,Ví điện tử|nullable|string|max:80',
            'note' => 'nullable|string|max:255',
        ], ['method.*' => 'Vui lòng chọn cách hoàn tiền.', 'reference.required_if' => 'Chuyển khoản / ví điện tử phải nhập mã giao dịch hoàn để đối soát.']);

        $res = $refunds->markRefunded($id, (int) session('adminId'), $data['method'], $data['reference'] ?? null, $data['note'] ?? null);
        if (!$res['ok']) {
            return back()->with('error', $res['message']);
        }
        if (($res['before']->status ?? null) === 'pending') {
            $code = DB::table('tbl_booking')->where('bookingId', $res['after']->bookingId)->value('bookingCode');
            AuditLog::record('refund.done', 'refund', $id, 'Đã hoàn ' . number_format((float) $res['after']->amount, 0, ',', '.') . ' đ cho đơn ' . $code . ' (' . $data['method'] . ')',
                ['status' => 'pending'], ['status' => 'refunded', 'method' => $data['method'], 'reference' => $data['reference'] ?? null]);
            $refunds->mailRefunded((int) $res['after']->bookingId, $res['after']);
            AdminBadges::forget();
        }

        return back()->with('success', $res['message']);
    }

    /** Gửi lệnh hoàn tiền qua API MoMo / ZaloPay cho hồ sơ $id (đã kiểm tra điều kiện trong GatewayRefundService). */
    public function gateway(Request $request, int $id, GatewayRefundService $gateway)
    {
        $res = $gateway->process($id, (int) session('adminId'), $request->ip());
        AuditLog::record('refund.gateway.request', 'refund', $id, 'Gửi lệnh hoàn qua cổng: ' . $res['status'] . ' — ' . $res['message']);
        AdminBadges::forget();

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    /** Hỏi cổng trạng thái lệnh hoàn đang xử lý. */
    public function sync(Request $request, int $id, GatewayRefundService $gateway)
    {
        $res = $gateway->sync($id, (int) session('adminId'), $request->ip());
        AdminBadges::forget();

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }
}
