<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\BookingAdjustmentService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Điều chỉnh số khách thay khách, và xử lý khoản thu thêm / hoàn phần dư phát sinh. */
class BookingAdjustmentAdminController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Điều chỉnh số khách';
        $ready = BookingAdjustmentService::ready();
        $status = in_array($request->query('status'), ['pending', 'done', 'all'], true) ? $request->query('status') : 'pending';
        $rows = collect();
        if ($ready) {
            $q = DB::table('tbl_booking_adjustments as j')->join('tbl_booking as b', 'b.bookingId', '=', 'j.bookingId')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
                ->select('j.*', 'b.bookingCode', 't.title', 'b.departureDate')->orderByDesc('j.adjustId');
            if ($status === 'pending') {
                $q->whereIn('j.moneyStatus', ['pending_collect', 'pending_refund']);
            } elseif ($status === 'done') {
                $q->whereIn('j.moneyStatus', ['none', 'collected', 'refunded']);
            }
            $rows = $q->paginate(30)->withQueryString();
        }

        return view('admin.adjustments.index', compact('title', 'ready', 'rows', 'status'));
    }

    /** Admin điều chỉnh số khách của đơn (từ chi tiết đơn). */
    public function adjust(Request $request, int $bookingId, BookingAdjustmentService $svc)
    {
        $d = $request->validate(['numAdults' => 'required|integer|min:1|max:50', 'numChildren' => 'required|integer|min:0|max:50', 'note' => 'nullable|string|max:255']);
        $res = $svc->adjust($bookingId, (int) $d['numAdults'], (int) $d['numChildren'], 'admin', (int) session('adminId'), null, $d['note'] ?? null);
        if ($res['ok']) {
            AuditLog::record('booking.adjust', 'booking', $bookingId, 'Điều chỉnh số khách: ' . $res['people']['old'][0] . '+' . $res['people']['old'][1] . ' → ' . $res['people']['new'][0] . '+' . $res['people']['new'][1] . ', tổng ' . $res['oldTotal'] . ' → ' . $res['newTotal']);
            AdminBadges::forget();
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Đã điều chỉnh số khách.' . ($res['moneyStatus'] === 'pending_collect' ? ' Cần thu thêm ' . number_format($res['settleAmount'], 0, ',', '.') . ' đ.' : ($res['moneyStatus'] === 'pending_refund' ? ' Cần hoàn ' . number_format($res['settleAmount'], 0, ',', '.') . ' đ.' : '')) : $res['message']);
    }

    public function settle(Request $request, int $id, BookingAdjustmentService $svc)
    {
        $d = $request->validate([
            'method' => 'required|in:Tiền mặt,Chuyển khoản,Ví điện tử',
            'reference' => 'required_if:method,Chuyển khoản,Ví điện tử|nullable|string|max:80',
        ], ['reference.required_if' => 'Chuyển khoản / ví điện tử phải nhập mã giao dịch để đối soát.']);
        $a = DB::table('tbl_booking_adjustments')->where('adjustId', $id)->first();
        abort_unless($a, 404);
        $res = $a->moneyStatus === 'pending_collect' ? $svc->markCollected($id, (int) session('adminId'), $d['method'], $d['reference'] ?? null) : $svc->markRefunded($id, (int) session('adminId'), $d['method'], $d['reference'] ?? null);
        if ($res['ok']) {
            AuditLog::record('booking.adjust.settle', 'booking', (int) $a->bookingId, $res['message'] . ' ' . number_format($res['amount'] ?? 0, 0, ',', '.') . ' đ (' . $d['method'] . ')');
            AdminBadges::forget();
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    /** Hoàn phần dư qua API cổng (VNPay / MoMo). */
    public function gateway(Request $request, int $id, \App\Services\Payments\GatewayRefundService $gw)
    {
        $res = $gw->processAdjustment($id, (int) session('adminId'), $request->ip());
        AuditLog::record('adjust.gateway.request', 'booking_adjustment', $id, 'Gửi lệnh hoàn phần dư qua cổng: ' . $res['status'] . ' — ' . $res['message']);
        AdminBadges::forget();

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function gatewaySync(Request $request, int $id, \App\Services\Payments\GatewayRefundService $gw)
    {
        $res = $gw->syncAdjustment($id, (int) session('adminId'), $request->ip());
        AdminBadges::forget();

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }
}
