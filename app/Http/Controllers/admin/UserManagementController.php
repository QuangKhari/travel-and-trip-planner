<?php

namespace App\Http\Controllers\admin;

use App\Support\AuditLog;

use App\Http\Controllers\Controller;
use App\Models\admin\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserManagementController extends Controller
{
    private UserModel $users;

    /** Bộ lọc trạng thái: khóa => nhãn */
    private const FILTERS = [
        'all' => 'Tất cả',
        'active' => 'Đang hoạt động',
        'pending' => 'Chưa kích hoạt',
        'blocked' => 'Bị chặn',
        'deleted' => 'Đã xóa',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->users = new UserModel();
    }

    public function index(Request $request)
    {
        $title = 'Người dùng';
        $q = trim((string) $request->query('q', ''));
        $f = array_key_exists((string) $request->query('f'), self::FILTERS) ? (string) $request->query('f') : 'all';

        // Đếm theo từng bộ lọc bằng một truy vấn
        $c = DB::table('tbl_users')->selectRaw(
            "COUNT(*) AS total,
             SUM(status IS NULL AND isActive = 'y') AS active,
             SUM(status IS NULL AND isActive = 'n') AS pending,
             SUM(status = 'b') AS blocked,
             SUM(status = 'd') AS deleted"
        )->first();
        $counts = [
            'all' => (int) $c->total, 'active' => (int) $c->active, 'pending' => (int) $c->pending,
            'blocked' => (int) $c->blocked, 'deleted' => (int) $c->deleted,
        ];

        $query = DB::table('tbl_users');
        match ($f) {
            'active' => $query->whereNull('status')->where('isActive', 'y'),
            'pending' => $query->whereNull('status')->where('isActive', 'n'),
            'blocked' => $query->where('status', 'b'),
            'deleted' => $query->where('status', 'd'),
            default => null,
        };
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(fn($w) => $w->where('fullName', 'like', $like)->orWhere('username', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('phoneNumber', 'like', $like));
        }

        $users = $query->orderByDesc('userId')->paginate(15)->withQueryString();

        $bookingCount = $users->count()
            ? DB::table('tbl_booking')->whereIn('userId', $users->pluck('userId'))
                ->selectRaw('userId, COUNT(*) AS c')->groupBy('userId')->pluck('c', 'userId')
            : collect();

        return view('admin.users', [
            'title' => $title, 'users' => $users, 'q' => $q, 'f' => $f,
            'filters' => self::FILTERS, 'counts' => $counts, 'bookingCount' => $bookingCount,
        ]);
    }

    /** Hồ sơ đầy đủ một khách: mọi thứ khách làm được ở phía người dùng đều có chỗ xem ở đây. */
    public function show(int $id)
    {
        $u = DB::table('tbl_users')->where('userId', $id)->first();
        abort_if(!$u, 404);
        $title = 'Khách: ' . $u->fullName;
        $has = fn($t) => \Illuminate\Support\Facades\Schema::hasTable($t);

        $bookings = \App\Support\BookingPii::decryptAll(DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->join('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')->where('b.userId', $id)->orderByDesc('b.bookingId')
            ->get(['b.bookingId', 'b.bookingCode', 'b.bookingStatus', 'b.cancelReason', 'b.bookingDate', 'b.departureDate', 'b.numAdults', 'b.numChildren', 'b.totalPrice',
                't.title', 't.startDate', 'c.paymentStatus', 'c.paymentMethod']));
        $memberOf = $has('tbl_trip_members') ? DB::table('tbl_trip_members')->where('userId', $id)->pluck('role', 'tripId') : collect();
        $trips = DB::table('tbl_trips as t')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where(fn($w) => $w->where('t.userId', $id)->orWhereIn('t.tripId', $memberOf->keys()))->where('t.kind', 'trip')
            ->orderByDesc('t.tripId')->get(['t.tripId', 't.title', 't.status', 't.startDate', 't.days', 't.userId', 't.shareToken', 'd.name as destName']);
        $reviews = DB::table('tbl_reviews as r')->join('tbl_tours as t', 't.tourId', '=', 'r.tourId')->where('r.userId', $id)->orderByDesc('r.reviewId')
            ->get(['r.reviewId', 'r.rating', 'r.comment', 'r.timestamp', 'r.isHidden', 't.title']);
        $tickets = $has('tbl_support_tickets') ? DB::table('tbl_support_tickets')->where('userId', $id)->orderByDesc('ticketId')->get() : collect();
        $contacts = $has('tbl_contact_messages') ? DB::table('tbl_contact_messages')->where(fn($w) => $w->where('userId', $id)->orWhere('email', $u->email))->orderByDesc('messageId')->limit(10)->get() : collect();
        $marks = $has('tbl_user_marks') ? DB::table('tbl_user_marks')->where('userId', $id)->selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type') : collect();
        $asks = $has('tbl_chat_logs') ? DB::table('tbl_chat_logs')->where('userId', $id)->count() : 0;
        $aiRuns = $has('tbl_ai_runs') ? DB::table('tbl_ai_runs')->where('userId', $id)->count() : 0;
        $coupons = $has('tbl_promotion_usage') ? DB::table('tbl_promotion_usage as u')->join('tbl_promotion as p', 'p.promotionId', '=', 'u.promotionId')
            ->where('u.userId', $id)->get(['p.code', 'u.discountAmount', 'u.bookingId']) : collect();
        $audit = DB::table('tbl_audit_log')->where('entity', 'user')->where('entityId', (string) $id)->orderByDesc('auditId')->limit(10)->get();
        $spent = $bookings->filter(fn($b) => in_array($b->paymentStatus, ['y', 'refund_pending', 'refunded'], true))->sum('totalPrice')
            - (float) ($has('tbl_refunds') ? DB::table('tbl_refunds')->whereIn('bookingId', $bookings->pluck('bookingId'))->where('status', 'refunded')->sum('amount') : 0);

        return view('admin.users.show', compact('title', 'u', 'bookings', 'trips', 'memberOf', 'reviews', 'tickets', 'contacts', 'marks', 'asks', 'aiRuns', 'coupons', 'audit', 'spent'));
    }

    public function activeUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'required|integer|min:1|exists:tbl_users,userId',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        // Không dựa vào số dòng bị đổi: kích hoạt lần hai (0 dòng đổi) không phải là lỗi
        $this->users->updateActive((int) $request->userId);
        AuditLog::record('user.activate', 'user', (int) $request->userId, 'Kích hoạt tài khoản ' . \Illuminate\Support\Facades\DB::table('tbl_users')->where('userId', (int) $request->userId)->value('username'));

        return response()->json(['success' => true, 'message' => 'Người dùng đã được kích hoạt thành công!']);
    }

    public function changeStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'required|integer|min:1|exists:tbl_users,userId',
            // 'active' = bỏ chặn / khôi phục (ghi NULL vào cột status)
            'status' => 'required|in:b,d,active',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $status = $request->status === 'active' ? null : $request->status;
        $u = \Illuminate\Support\Facades\DB::table('tbl_users')->where('userId', (int) $request->userId)->first(['username', 'status']);
        $this->users->changeStatus((int) $request->userId, ['status' => $status]);
        if (($u->status ?? null) !== $status) {
            $label = ['b' => 'Khóa', 'd' => 'Xóa mềm', null => 'Mở khóa / khôi phục'][$status];
            AuditLog::record($status === null ? 'user.unblock' : 'user.block', 'user', (int) $request->userId, $label . ' tài khoản ' . ($u->username ?? ''), ['status' => $u->status ?? null], ['status' => $status]);
        }

        return response()->json([
            'success' => true,
            'status' => $status,   // null | 'b' | 'd' để giao diện tự vẽ lại nhãn
            'message' => 'Trạng thái người dùng đã được cập nhật thành công!',
        ]);
    }
}
