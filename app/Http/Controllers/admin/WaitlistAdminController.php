<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\WaitlistService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Quản lý danh sách chờ: xem theo lịch khởi hành, mời thủ công, gỡ người chờ. */
class WaitlistAdminController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Danh sách chờ';
        $ready = WaitlistService::ready();
        $status = in_array($request->query('status'), ['active', 'converted', 'expired', 'cancelled', 'all'], true) ? $request->query('status') : 'active';
        $rows = collect();
        $deps = collect();
        if ($ready) {
            $q = DB::table('tbl_waitlist as w')->join('tbl_tour_availability as a', 'a.availabilityId', '=', 'w.availabilityId')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
                ->join('tbl_users as u', 'u.userId', '=', 'w.userId')->orderBy('a.departureDate')->orderBy('w.createdAt')
                ->select('w.*', 'a.departureDate', 'a.totalSlots', 'a.bookedSlots', 'a.heldSlots', 't.title', 'u.fullName', 'u.email', 'u.phoneNumber');
            if ($status === 'active') {
                $q->whereIn('w.status', ['waiting', 'offered']);
            } elseif ($status !== 'all') {
                $q->where('w.status', $status);
            }
            $rows = $q->paginate(40)->withQueryString();
            $deps = DB::table('tbl_waitlist as w')->join('tbl_tour_availability as a', 'a.availabilityId', '=', 'w.availabilityId')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
                ->whereIn('w.status', ['waiting', 'offered'])->groupBy('a.availabilityId', 'a.departureDate', 'a.totalSlots', 'a.bookedSlots', 'a.heldSlots', 't.title')
                ->orderBy('a.departureDate')->get(['a.availabilityId', 'a.departureDate', 'a.totalSlots', 'a.bookedSlots', 'a.heldSlots', 't.title', DB::raw("SUM(w.status = 'waiting') as waiting"), DB::raw("SUM(w.status = 'offered') as offered")]);
        }

        return view('admin.waitlist.index', compact('title', 'ready', 'rows', 'deps', 'status'));
    }

    public function offer(int $availabilityId, WaitlistService $svc)
    {
        $n = $svc->offerNext($availabilityId);
        AuditLog::record('waitlist.offer', 'departure', $availabilityId, 'Mời từ danh sách chờ: ' . $n . ' lời mời');
        AdminBadges::forget();

        return back()->with($n ? 'success' : 'error', $n ? 'Đã gửi ' . $n . ' lời mời.' : 'Không có người chờ vừa với số chỗ trống hiện tại.');
    }

    public function cancel(int $id, WaitlistService $svc)
    {
        $ok = $svc->cancel($id, 0, true);
        if ($ok) {
            AuditLog::record('waitlist.cancel', 'waitlist', $id, 'Gỡ người chờ khỏi danh sách');
            AdminBadges::forget();
        }

        return back()->with($ok ? 'success' : 'error', $ok ? 'Đã gỡ khỏi danh sách chờ.' : 'Không gỡ được.');
    }

    public function process(WaitlistService $svc)
    {
        $r = $svc->processAll();
        AdminBadges::forget();

        return back()->with('success', "Đã xử lý: {$r['expired']} lời mời hết hạn, {$r['offered']} lời mời mới.");
    }
}
