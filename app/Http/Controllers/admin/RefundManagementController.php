<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplateMail;
use App\Services\RefundService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use App\Support\BookingPii;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Hoàn tiền (A6): danh sách yêu cầu hoàn tiền của đơn đã thanh toán bị hủy, admin chuyển tiền rồi đánh dấu "Đã hoàn".
 */
class RefundManagementController extends Controller
{
    public function index(Request $request)
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

        $sum = DB::table('tbl_refunds')->selectRaw("status, COUNT(*) n, COALESCE(SUM(amount),0) total")->groupBy('status')->get()->keyBy('status');

        return view('admin.refunds.index', compact('title', 'rows', 'status', 'sum'));
    }

    public function complete(Request $request, int $id, RefundService $refunds)
    {
        $data = $request->validate([
            'method' => 'required|string|in:Chuyển khoản,Tiền mặt,Ví điện tử,Khác',
            'reference' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:255',
        ], ['method.*' => 'Vui lòng chọn cách hoàn tiền.']);

        $res = $refunds->markRefunded($id, (int) session('adminId'), $data['method'], $data['reference'] ?? null, $data['note'] ?? null);
        if (!$res['ok']) {
            return back()->with('error', $res['message']);
        }
        if (($res['before']->status ?? null) === 'pending') {
            $code = DB::table('tbl_booking')->where('bookingId', $res['after']->bookingId)->value('bookingCode');
            AuditLog::record('refund.done', 'refund', $id, 'Đã hoàn ' . number_format((float) $res['after']->amount, 0, ',', '.') . ' đ cho đơn ' . $code . ' (' . $data['method'] . ')',
                ['status' => 'pending'], ['status' => 'refunded', 'method' => $data['method'], 'reference' => $data['reference'] ?? null]);
            $this->mailCustomer((int) $res['after']->bookingId, $res['after']);
            AdminBadges::forget();
        }

        return back()->with('success', $res['message']);
    }

    private function mailCustomer(int $bookingId, object $refund): void
    {
        try {
            $b = BookingPii::decrypt(DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
                ->where('b.bookingId', $bookingId)->select('b.*', 't.title')->first());
            if ($b && filter_var($b->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($b->email)->queue(new TemplateMail('admin.emails.booking-status', ['b' => $b, 'event' => 'refunded', 'refund' => $refund], 'Travela đã hoàn tiền cho đơn ' . $b->bookingCode));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
