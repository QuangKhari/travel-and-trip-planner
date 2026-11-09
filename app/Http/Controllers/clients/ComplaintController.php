<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\ComplaintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Khiếu nại sau tour phía khách. */
class ComplaintController extends Controller
{
    public function index(Request $request, ComplaintService $svc)
    {
        $title = 'Khiếu nại';
        $userId = (int) $this->getUserId();
        $ready = ComplaintService::ready();
        $rows = collect();
        $booking = null;
        $el = ['can' => false, 'why' => ''];
        if ($ready) {
            $rows = DB::table('tbl_complaints as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->where('c.userId', $userId)->orderByDesc('c.complaintId')->limit(30)->get(['c.*', 'b.bookingCode']);
            if ($request->query('bookingId')) {
                $booking = DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')->where('b.bookingId', (int) $request->query('bookingId'))->where('b.userId', $userId)->first(['b.*', 't.title']);
                $el = $booking ? $svc->eligibility($booking) : ['can' => false, 'why' => 'Không tìm thấy đơn.'];
            }
        }

        return view('clients.complaints.index', ['title' => $title, 'ready' => $ready, 'rows' => $rows, 'booking' => $booking, 'el' => $el,
            'cats' => ComplaintService::CATEGORIES, 'statuses' => ComplaintService::STATUSES]);
    }

    public function store(Request $request, ComplaintService $svc)
    {
        $userId = (int) $this->getUserId();
        $d = $request->validate([
            'bookingId' => 'required|integer', 'category' => 'required|in:' . implode(',', array_keys(ComplaintService::CATEGORIES)),
            'subject' => 'required|string|min:5|max:160', 'body' => 'required|string|min:20|max:4000', 'claimedAmount' => 'nullable|integer|min:0|max:1000000000',
        ], ['subject.min' => 'Tiêu đề quá ngắn.', 'body.min' => 'Hãy mô tả cụ thể (ít nhất 20 ký tự) để Travela xử lý nhanh hơn.']);
        $booking = DB::table('tbl_booking')->where('bookingId', (int) $d['bookingId'])->where('userId', $userId)->first();
        $el = $booking ? $svc->eligibility($booking) : ['can' => false, 'why' => 'Không tìm thấy đơn.'];
        if (!$el['can']) {
            return redirect()->route('complaint.index')->with('error', $el['why']);
        }
        $c = $svc->create($booking, $userId, $d['category'], $d['subject'], $d['body'], isset($d['claimedAmount']) ? (int) $d['claimedAmount'] : null);

        return redirect()->route('complaint.show', ['code' => $c->code])->with('success', 'Đã gửi khiếu nại ' . $c->code . '. Travela sẽ phản hồi qua email và tại trang này.');
    }

    public function show(string $code)
    {
        $userId = (int) $this->getUserId();
        $c = DB::table('tbl_complaints as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->where('c.code', $code)->where('c.userId', $userId)->first(['c.*', 'b.bookingCode']) ?? abort(404);
        $msgs = DB::table('tbl_complaint_messages')->where('complaintId', $c->complaintId)->orderBy('messageId')->get();
        DB::table('tbl_complaints')->where('complaintId', $c->complaintId)->update(['customerUnread' => false]);
        $title = 'Khiếu nại ' . $c->code;

        return view('clients.complaints.show', ['title' => $title, 'c' => $c, 'msgs' => $msgs, 'cats' => ComplaintService::CATEGORIES, 'statuses' => ComplaintService::STATUSES]);
    }

    public function reply(Request $request, string $code, ComplaintService $svc)
    {
        $userId = (int) $this->getUserId();
        $c = DB::table('tbl_complaints')->where('code', $code)->where('userId', $userId)->first() ?? abort(404);
        $d = $request->validate(['body' => 'required|string|min:3|max:4000']);
        if (in_array($c->status, ['resolved', 'closed'], true)) {
            return back()->with('error', 'Khiếu nại đã được giải quyết / đóng. Nếu cần, hãy gửi khiếu nại mới (nếu còn trong thời hạn).');
        }
        $svc->addMessage((int) $c->complaintId, 'customer', $userId, $d['body']);
        if ($c->status === 'rejected') {   // khách phản hồi lại quyết định từ chối: đưa về hàng đợi xem xét
            DB::table('tbl_complaints')->where('complaintId', $c->complaintId)->update(['status' => 'open']);
        }

        return back()->with('success', 'Đã gửi phản hồi.');
    }
}
