<?php

namespace App\Http\Controllers\admin;

use App\Support\AuditLog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chuyến đi do khách lập bằng Trip Planner (tbl_trips + tbl_trip_items).
 * Admin chỉ XEM và xóa chuyến sai phạm; không sửa nội dung chuyến của khách.
 * Khay chuyến đi (kind = 'tray') là dữ liệu tạm nên không liệt kê.
 */
class TripAdminController extends Controller
{
    private const STATUSES = ['draft' => 'Nháp', 'planned' => 'Đã lên kế hoạch', 'done' => 'Đã đi'];

    public function index(Request $request)
    {
        $hasKind = Schema::hasColumn('tbl_trips', 'kind');
        $hasDest = Schema::hasColumn('tbl_trips', 'destinationId');
        $hasShare = Schema::hasColumn('tbl_trips', 'shareToken');

        $base = DB::table('tbl_trips as t');
        if ($hasKind) {
            $base->where('t.kind', 'trip');
        }

        $status = array_key_exists((string) $request->query('status'), self::STATUSES) ? (string) $request->query('status') : null;
        $counts = (clone $base)->selectRaw('t.status, COUNT(*) c')->groupBy('t.status')->pluck('c', 'status');

        $q = (clone $base)->join('tbl_users as u', 'u.userId', '=', 't.userId')
            ->selectRaw('t.*, u.fullName, u.email')
            ->selectRaw('(SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId) AS itemCount');
        if ($hasDest) {
            $q->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')->addSelect('d.name as destName');
        }
        if ($status) {
            $q->where('t.status', $status);
        }
        if ($s = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
            $q->where(fn($w) => $w->where('t.title', 'like', $like)->orWhere('u.fullName', 'like', $like)->orWhere('u.email', 'like', $like));
        }

        return view('admin.trips.index', [
            'title' => 'Chuyến đi (Planner)',
            'rows' => $q->orderByDesc('t.tripId')->paginate(15)->withQueryString(),
            'counts' => $counts, 'statuses' => self::STATUSES, 'status' => $status,
            'search' => (string) $request->query('q', ''), 'hasShare' => $hasShare, 'hasDest' => $hasDest,
            'itemTotal' => (int) DB::table('tbl_trip_items')->count(),
        ]);
    }

    /** Xem chuyến đi của khách (chỉ đọc): từng ngày, từng mục, thành viên, yêu cầu tư vấn liên quan. */
    public function show(int $id)
    {
        $t = DB::table('tbl_trips as t')->join('tbl_users as u', 'u.userId', '=', 't.userId')
            ->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('t.tripId', $id)->select('t.*', 'u.fullName', 'u.email', 'u.avatar', 'd.name as destName')->first();
        abort_if(!$t, 404);
        $title = 'Chuyến đi: ' . $t->title;
        $items = DB::table('tbl_trip_items')->where('tripId', $id)->orderBy('dayNo')->orderBy('orderNo')->get();
        // Tên hiển thị của mục: dùng đúng hàm giải tham chiếu của Trip Planner (nội dung đã tắt / xóa thì không có tên)
        $refs = $items->filter(fn($r) => $r->refType && $r->refId && in_array($r->refType, \App\Services\Planner\TripPlanner::TYPES, true))
            ->map(fn($r) => ['type' => $r->refType, 'refId' => (int) $r->refId])->values()->all();
        $names = $refs ? app(\App\Services\Planner\TripPlanner::class)->resolve($refs) : [];
        $days = $items->groupBy('dayNo');
        $members = Schema::hasTable('tbl_trip_members') ? DB::table('tbl_trip_members as m')->join('tbl_users as u', 'u.userId', '=', 'm.userId')
            ->where('m.tripId', $id)->get(['m.role', 'm.createdAt', 'u.userId', 'u.fullName', 'u.email']) : collect();
        $tickets = Schema::hasTable('tbl_support_tickets') ? DB::table('tbl_support_tickets')->where('tripId', $id)->orderByDesc('ticketId')->get() : collect();
        $cost = (float) $items->sum('cost');

        return view('admin.trips.show', compact('title', 't', 'items', 'names', 'days', 'members', 'tickets', 'cost'));
    }

    public function destroy(int $id)
    {
        // tbl_trip_items có khóa ngoại ON DELETE CASCADE nên xóa chuyến là xóa luôn các mục
        $title = DB::table('tbl_trips')->where('tripId', $id)->value('title');
        $n = DB::table('tbl_trips')->where('tripId', $id)->delete();
        if ($n) {
            AuditLog::record('trip.delete', 'trip', $id, 'Xóa chuyến đi "' . $title . '"');
        }

        return back()->with($n ? 'success' : 'error', $n ? 'Đã xóa chuyến đi.' : 'Không tìm thấy chuyến đi.');
    }
}
