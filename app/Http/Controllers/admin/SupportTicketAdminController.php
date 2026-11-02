<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\SupportTicketService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Yêu cầu tư vấn (T-39): nhân viên xem, nhận xử lý, trả lời (email khách qua hàng đợi), đóng. */
class SupportTicketAdminController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Yêu cầu tư vấn';
        $status = $request->query('status', 'active');
        $q = DB::table('tbl_support_tickets as s')
            ->join('tbl_users as u', 'u.userId', '=', 's.userId')
            ->leftJoin('tbl_trips as t', 't.tripId', '=', 's.tripId')
            ->leftJoin('tbl_admin as a', 'a.adminId', '=', 's.assignedTo')
            ->select('s.*', 'u.fullName', 'u.email', 'u.avatar', 't.title as tripTitle', 'a.userName as assignee')
            ->orderByRaw("FIELD(s.status, 'open', 'in_progress', 'answered', 'closed')")->orderByDesc('s.lastMessageAt');
        if ($status === 'active') {
            $q->whereIn('s.status', ['open', 'in_progress']);
        } elseif (array_key_exists($status, SupportTicketService::STATUS)) {
            $q->where('s.status', $status);
        }
        if ($request->query('mine')) {
            $q->where('s.assignedTo', (int) session('adminId'));
        }
        if ($kw = trim((string) $request->query('q'))) {
            $like = '%' . addcslashes($kw, '%_\\') . '%';
            $q->where(fn($w) => $w->where('s.code', 'like', $like)->orWhere('s.subject', 'like', $like)->orWhere('u.fullName', 'like', $like));
        }
        $rows = $q->paginate(20)->withQueryString();
        $counts = DB::table('tbl_support_tickets')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('admin.support.index', compact('title', 'rows', 'status', 'counts'));
    }

    public function show(int $id)
    {
        $t = DB::table('tbl_support_tickets as s')
            ->join('tbl_users as u', 'u.userId', '=', 's.userId')
            ->leftJoin('tbl_trips as tr', 'tr.tripId', '=', 's.tripId')
            ->leftJoin('tbl_admin as a', 'a.adminId', '=', 's.assignedTo')
            ->where('s.ticketId', $id)
            ->select('s.*', 'u.fullName', 'u.email', 'u.phoneNumber', 'u.avatar', 'tr.title as tripTitle', 'tr.startDate as tripStart',
                'tr.days as tripDays', 'tr.groupSize', 'tr.budget', 'tr.destinationId', 'a.userName as assignee')
            ->first();
        abort_if(!$t, 404);
        $title = 'Yêu cầu ' . $t->code;
        $messages = DB::table('tbl_support_ticket_messages as m')
            ->leftJoin('tbl_admin as a', fn($j) => $j->on('a.adminId', '=', 'm.authorId')->where('m.authorType', 'staff'))
            ->where('m.ticketId', $id)->orderBy('m.messageId')
            ->select('m.*', 'a.userName as staffName')->get();
        $dest = $t->destinationId ? DB::table('tbl_destinations')->where('destinationId', $t->destinationId)->value('name') : null;
        $items = $t->tripId ? DB::table('tbl_trip_items')->where('tripId', $t->tripId)->count() : 0;
        $bookings = DB::table('tbl_booking')->where('userId', $t->userId)->where('bookingStatus', '!=', 'c')->count();

        return view('admin.support.show', compact('title', 't', 'messages', 'dest', 'items', 'bookings'));
    }

    public function reply(Request $request, int $id, SupportTicketService $svc)
    {
        $d = $request->validate(['body' => 'required|string|min:2|max:4000', 'close' => 'nullable|boolean'],
            ['body.required' => 'Vui lòng nhập nội dung trả lời.']);
        $t = DB::table('tbl_support_tickets')->where('ticketId', $id)->first();
        abort_if(!$t, 404);
        $svc->staffReply($t, (int) session('adminId'), $d['body'], (bool) ($d['close'] ?? false));
        AuditLog::record('ticket.reply', 'ticket', $id, 'Trả lời yêu cầu ' . $t->code . (!empty($d['close']) ? ' và đóng' : ''), ['status' => $t->status], ['status' => !empty($d['close']) ? 'closed' : 'answered']);
        AdminBadges::forget();

        return back()->with('success', 'Đã gửi câu trả lời, khách sẽ nhận email thông báo.');
    }

    public function status(Request $request, int $id)
    {
        $d = $request->validate(['action' => 'required|in:take,close,reopen']);
        $t = DB::table('tbl_support_tickets')->where('ticketId', $id)->first();
        abort_if(!$t, 404);
        $adminId = (int) session('adminId');
        $upd = match ($d['action']) {
            'take' => ['assignedTo' => $adminId, 'status' => $t->status === 'open' ? 'in_progress' : $t->status],
            'close' => ['status' => 'closed', 'closedAt' => now()],
            'reopen' => ['status' => 'open', 'closedAt' => null],
        };
        DB::table('tbl_support_tickets')->where('ticketId', $id)->update($upd);
        AuditLog::record($d['action'] === 'take' ? 'ticket.assign' : 'ticket.status', 'ticket', $id,
            ['take' => 'Nhận xử lý', 'close' => 'Đóng', 'reopen' => 'Mở lại'][$d['action']] . ' yêu cầu ' . $t->code,
            ['status' => $t->status, 'assignedTo' => $t->assignedTo], $upd);
        AdminBadges::forget();

        return back()->with('success', ['take' => 'Bạn đã nhận xử lý yêu cầu này.', 'close' => 'Đã đóng yêu cầu.', 'reopen' => 'Đã mở lại yêu cầu.'][$d['action']]);
    }
}
