<?php

namespace App\Http\Controllers\admin;

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

    public function destroy(int $id)
    {
        // tbl_trip_items có khóa ngoại ON DELETE CASCADE nên xóa chuyến là xóa luôn các mục
        $n = DB::table('tbl_trips')->where('tripId', $id)->delete();

        return back()->with($n ? 'success' : 'error', $n ? 'Đã xóa chuyến đi.' : 'Không tìm thấy chuyến đi.');
    }
}
