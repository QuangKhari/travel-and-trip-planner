<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\ComplaintService as S;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Khiếu nại sau tour: xử lý, phản hồi khách, bồi hoàn và quy lỗi nhà cung cấp. */
class ComplaintAdminController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Khiếu nại';
        $ready = S::ready();
        $status = in_array($request->query('status'), ['active', 'resolved', 'rejected', 'closed', 'all'], true) ? $request->query('status') : 'active';
        $rows = collect();
        if ($ready) {
            $q = DB::table('tbl_complaints as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
                ->select('c.*', 'b.bookingCode', 't.title')->orderByDesc('c.complaintId');
            if ($status === 'active') {
                $q->whereIn('c.status', ['open', 'investigating']);
            } elseif ($status !== 'all') {
                $q->where('c.status', $status);
            }
            $rows = $q->paginate(30)->withQueryString();
        }

        return view('admin.complaints.index', ['title' => $title, 'ready' => $ready, 'rows' => $rows, 'status' => $status, 'cats' => S::CATEGORIES, 'statuses' => S::STATUSES]);
    }

    public function show(int $id)
    {
        $c = DB::table('tbl_complaints as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')->where('c.complaintId', $id)
            ->first(['c.*', 'b.bookingCode', 'b.availabilityId', 't.title']) ?? abort(404);
        $msgs = DB::table('tbl_complaint_messages')->where('complaintId', $id)->orderBy('messageId')->get();
        $suppliers = Schema::hasTable('tbl_suppliers') ? DB::table('tbl_suppliers')->where('active', true)->orderBy('name')->get(['supplierId', 'name', 'kind']) : collect();
        $paid = (int) round((float) DB::table('tbl_checkout')->where('bookingId', $c->bookingId)->value('amount'));
        $title = 'Khiếu nại ' . $c->code;

        return view('admin.complaints.show', ['title' => $title, 'c' => $c, 'msgs' => $msgs, 'suppliers' => $suppliers, 'paid' => $paid, 'cats' => S::CATEGORIES, 'statuses' => S::STATUSES, 'flow' => S::FLOW]);
    }

    public function reply(Request $request, int $id, S $svc)
    {
        $d = $request->validate(['body' => 'required|string|min:3|max:4000']);
        abort_unless(DB::table('tbl_complaints')->where('complaintId', $id)->exists(), 404);
        $svc->addMessage($id, 'staff', (int) session('adminId'), $d['body']);
        DB::table('tbl_complaints')->where('complaintId', $id)->where('status', 'open')->update(['status' => 'investigating', 'assignedTo' => (int) session('adminId')]);
        AdminBadges::forget();

        return back()->with('success', 'Đã gửi phản hồi cho khách.');
    }

    public function status(Request $request, int $id, S $svc)
    {
        $d = $request->validate(['status' => 'required|in:investigating,resolved,rejected,closed', 'resolution' => 'nullable|string|max:3000']);
        $r = $svc->setStatus($id, $d['status'], (int) session('adminId'), $d['resolution'] ?? null);
        if ($r['ok']) {
            AuditLog::record('complaint.status', 'complaint', $id, 'Khiếu nại → ' . S::STATUSES[$d['status']]);
            AdminBadges::forget();
        }

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }

    public function compensation(Request $request, int $id, S $svc)
    {
        $d = $request->validate(['amount' => 'required|integer|min:0|max:1000000000', 'supplierId' => 'nullable|integer|exists:tbl_suppliers,supplierId']);
        $r = $svc->setCompensation($id, (int) $d['amount'], $d['supplierId'] ?? null);
        if ($r['ok']) {
            AuditLog::record('complaint.compensation', 'complaint', $id, 'Đặt bồi hoàn ' . number_format($d['amount'], 0, ',', '.') . ' đ');
        }

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }

    public function pay(Request $request, int $id, S $svc)
    {
        $d = $request->validate(['method' => 'required|in:Tiền mặt,Chuyển khoản,Ví điện tử', 'reference' => 'required_if:method,Chuyển khoản,Ví điện tử|nullable|string|max:80'],
            ['reference.required_if' => 'Chuyển khoản / ví điện tử phải nhập mã giao dịch để đối soát.']);
        $r = $svc->markCompensationPaid($id, (int) session('adminId'), $d['method'], $d['reference'] ?? null);
        if ($r['ok']) {
            AuditLog::record('complaint.paid', 'complaint', $id, 'Chi trả bồi hoàn ' . number_format($r['amount'], 0, ',', '.') . ' đ (' . $d['method'] . ')');
            AdminBadges::forget();
        }

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }
}
