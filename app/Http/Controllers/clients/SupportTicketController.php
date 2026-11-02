<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\SupportTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Yêu cầu tư vấn của khách (T-39). Khách chọn chuyến đi (không bắt buộc), mô tả điều cần hỗ trợ;
 * nhân viên trả lời trong trang quản trị, khách nhận email và xem / trả lời tiếp ở đây.
 */
class SupportTicketController extends Controller
{
    private function trips(int $userId)
    {
        $member = DB::getSchemaBuilder()->hasTable('tbl_trip_members')
            ? DB::table('tbl_trip_members')->where('userId', $userId)->pluck('tripId')->all() : [];

        return DB::table('tbl_trips')->where('kind', 'trip')
            ->where(fn($q) => $q->where('userId', $userId)->orWhereIn('tripId', $member))
            ->orderByDesc('updatedAt')->orderByDesc('tripId')->limit(50)->get(['tripId', 'title', 'startDate', 'days']);
    }

    public function index(Request $request)
    {
        $title = 'Hỗ trợ tư vấn';
        $userId = (int) $this->getUserId();
        $tickets = DB::table('tbl_support_tickets as s')->leftJoin('tbl_trips as t', 't.tripId', '=', 's.tripId')
            ->where('s.userId', $userId)->orderByDesc('s.lastMessageAt')->select('s.*', 't.title as tripTitle')->get();
        $trips = $this->trips($userId);
        $preTrip = (int) $request->query('trip', 0);

        return view('clients.support.index', compact('title', 'tickets', 'trips', 'preTrip'));
    }

    public function store(Request $request, SupportTicketService $svc)
    {
        $userId = (int) $this->getUserId();
        $d = $request->validate([
            'tripId' => 'nullable|integer',
            'topic' => 'required|in:' . implode(',', array_keys(SupportTicketService::TOPICS)),
            'subject' => 'required|string|min:5|max:160',
            'body' => 'required|string|min:10|max:4000',
            'phone' => ['nullable', 'regex:/^[0-9]{10,11}$/'],
        ], [
            'subject.required' => 'Vui lòng nhập tiêu đề.', 'subject.min' => 'Tiêu đề quá ngắn.',
            'body.required' => 'Vui lòng mô tả điều bạn cần tư vấn.', 'body.min' => 'Nội dung cần ít nhất 10 ký tự.',
            'phone.regex' => 'Số điện thoại phải có 10–11 chữ số.',
        ]);
        $tripId = !empty($d['tripId']) && $this->trips($userId)->contains('tripId', (int) $d['tripId']) ? (int) $d['tripId'] : null;
        $open = DB::table('tbl_support_tickets')->where('userId', $userId)->whereIn('status', ['open', 'in_progress'])->count();
        if ($open >= 5) {
            return back()->withInput()->with('error', 'Bạn đang có 5 yêu cầu chưa xử lý xong. Vui lòng chờ nhân viên phản hồi.');
        }
        $t = $svc->create($userId, $tripId, $d['topic'], $d['subject'], $d['body'], $d['phone'] ?? null);
        toastr()->success('Đã gửi yêu cầu ' . $t->code . '. Nhân viên Travela sẽ phản hồi qua email và tại trang này.');

        return redirect()->route('support.show', ['code' => $t->code]);
    }

    public function show(string $code)
    {
        $t = $this->own($code);
        $title = 'Yêu cầu ' . $t->code;
        DB::table('tbl_support_tickets')->where('ticketId', $t->ticketId)->update(['customerUnread' => false]);
        $messages = DB::table('tbl_support_ticket_messages')->where('ticketId', $t->ticketId)->orderBy('messageId')->get();
        $trip = $t->tripId ? DB::table('tbl_trips')->where('tripId', $t->tripId)->first(['tripId', 'title']) : null;

        return view('clients.support.show', compact('title', 't', 'messages', 'trip'));
    }

    public function reply(Request $request, string $code, SupportTicketService $svc)
    {
        $t = $this->own($code);
        $d = $request->validate(['body' => 'required|string|min:2|max:4000'], ['body.required' => 'Vui lòng nhập nội dung.']);
        if ($t->status === 'closed') {
            return back()->with('error', 'Yêu cầu đã đóng. Hãy tạo yêu cầu mới nếu bạn cần hỗ trợ thêm.');
        }
        $svc->customerReply($t, (int) $this->getUserId(), $d['body']);
        toastr()->success('Đã gửi tin nhắn.');

        return back();
    }

    private function own(string $code): object
    {
        $t = DB::table('tbl_support_tickets')->where('code', $code)->where('userId', (int) $this->getUserId())->first();
        abort_if(!$t, 404);

        return $t;
    }
}
