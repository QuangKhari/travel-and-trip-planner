<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cảnh báo điểm đến (tbl_travel_alerts): sạt lở, đường cấm, điểm tạm đóng cửa, bão... do nhân viên nhập kèm nguồn.
 * La bàn (TripSuggest::alerts) đối chiếu với chuyến của người dùng theo điểm đến / địa điểm và khoảng ngày đi
 * để cảnh báo chủ động và gợi ý đổi lịch. Trang này cho thấy mỗi cảnh báo đang chạm tới bao nhiêu chuyến.
 */
class TravelAlertAdminController extends Controller
{
    public const KINDS = ['weather' => 'Thời tiết / thiên tai', 'closure' => 'Tạm đóng cửa', 'traffic' => 'Giao thông, cấm đường', 'crowd' => 'Đông đúc, sự kiện', 'other' => 'Khác'];
    public const LEVELS = ['info' => 'Lưu ý', 'warn' => 'Cảnh báo', 'bad' => 'Nghiêm trọng'];

    public function index(Request $request)
    {
        $ready = Schema::hasTable('tbl_travel_alerts');
        $status = in_array($request->query('status'), ['active', 'ended', 'off', 'all'], true) ? $request->query('status') : 'active';
        $rows = collect();
        $counts = ['active' => 0, 'ended' => 0, 'off' => 0, 'all' => 0];
        $today = now()->toDateString();
        if ($ready) {
            $base = DB::table('tbl_travel_alerts as a')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'a.destinationId')
                ->leftJoin('tbl_external_places as p', 'p.placeId', '=', 'a.placeId');
            $live = fn($q) => $q->where('a.isActive', 1)->where(fn($w) => $w->whereNull('a.endsOn')->orWhere('a.endsOn', '>=', $today));
            $counts = [
                'active' => $live(clone $base)->count(),
                'ended' => (clone $base)->where('a.isActive', 1)->whereNotNull('a.endsOn')->where('a.endsOn', '<', $today)->count(),
                'off' => (clone $base)->where('a.isActive', 0)->count(),
                'all' => (clone $base)->count(),
            ];
            $q = clone $base;
            match ($status) {
                'active' => $live($q),
                'ended' => $q->where('a.isActive', 1)->whereNotNull('a.endsOn')->where('a.endsOn', '<', $today),
                'off' => $q->where('a.isActive', 0),
                default => null,
            };
            $rows = $q->orderByDesc('a.isActive')->orderBy('a.startsOn')->limit(200)->get(['a.*', 'd.name as destName', 'p.name as placeName']);
            foreach ($rows as $r) {
                $r->trips = $this->affectedTrips($r);
            }
        }
        $edit = $ready && $request->query('edit') ? DB::table('tbl_travel_alerts')->where('alertId', (int) $request->query('edit'))->first() : null;

        return view('admin.travel-alerts.index', [
            'title' => 'Cảnh báo điểm đến', 'ready' => $ready, 'rows' => $rows, 'counts' => $counts, 'status' => $status, 'edit' => $edit,
            'kinds' => self::KINDS, 'levels' => self::LEVELS,
            'destinations' => DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['destinationId', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(Schema::hasTable('tbl_travel_alerts'), 404);
        $d = $this->validated($request);
        $id = DB::table('tbl_travel_alerts')->insertGetId($d + ['isActive' => 1, 'createdBy' => (int) session('adminId') ?: null, 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('travel_alert.create', 'travel_alert', $id, 'Thêm cảnh báo điểm đến: ' . $d['title']);

        return redirect()->route('admin.travel-alerts')->with('success', 'Đã thêm cảnh báo. La bàn sẽ nhắc những chuyến trùng ngày và điểm đến.');
    }

    public function update(Request $request, int $id)
    {
        abort_unless(DB::table('tbl_travel_alerts')->where('alertId', $id)->exists(), 404);
        $d = $this->validated($request);
        DB::table('tbl_travel_alerts')->where('alertId', $id)->update($d + ['updated_at' => now()]);
        AuditLog::record('travel_alert.update', 'travel_alert', $id, 'Sửa cảnh báo điểm đến: ' . $d['title']);

        return redirect()->route('admin.travel-alerts')->with('success', 'Đã cập nhật cảnh báo #' . $id . '.');
    }

    public function toggle(int $id)
    {
        $a = DB::table('tbl_travel_alerts')->where('alertId', $id)->first() ?? abort(404);
        DB::table('tbl_travel_alerts')->where('alertId', $id)->update(['isActive' => $a->isActive ? 0 : 1, 'updated_at' => now()]);
        AuditLog::record($a->isActive ? 'travel_alert.off' : 'travel_alert.on', 'travel_alert', $id, ($a->isActive ? 'Tắt' : 'Bật lại') . ' cảnh báo: ' . $a->title);

        return back()->with('success', $a->isActive ? 'Đã tắt cảnh báo (La bàn không nhắc nữa).' : 'Đã bật lại cảnh báo.');
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'title' => 'required|string|min:4|max:160',
            'kind' => 'required|in:' . implode(',', array_keys(self::KINDS)),
            'level' => 'required|in:' . implode(',', array_keys(self::LEVELS)),
            'destinationId' => 'nullable|integer|exists:tbl_destinations,destinationId',
            'placeId' => 'nullable|integer|exists:tbl_external_places,placeId',
            'startsOn' => 'required|date',
            'endsOn' => 'nullable|date|after_or_equal:startsOn',
            'detail' => 'nullable|string|max:600',
            'advice' => 'nullable|string|max:300',
            'sourceUrl' => 'nullable|url|max:255',
        ], ['endsOn.after_or_equal' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi.', 'sourceUrl.url' => 'Nguồn phải là đường dẫn đầy đủ (https://...).']);
        // ô để trống phải xóa giá trị cũ khi sửa (validate() bỏ khóa không gửi lên)
        $d += ['destinationId' => null, 'placeId' => null, 'endsOn' => null, 'detail' => null, 'advice' => null, 'sourceUrl' => null];
        if (empty($d['destinationId']) && empty($d['placeId'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['destinationId' => 'Chọn điểm đến hoặc nhập mã địa điểm bị ảnh hưởng.']);
        }
        if (!empty($d['placeId']) && empty($d['destinationId'])) {
            $d['destinationId'] = DB::table('tbl_external_places')->where('placeId', $d['placeId'])->value('destinationId');
        }

        return $d;
    }

    /** Số chuyến (đã có ngày đi) trùng khoảng ngày và cùng điểm đến / có địa điểm bị ảnh hưởng. */
    private function affectedTrips(object $a): int
    {
        if (!Schema::hasTable('tbl_trips')) {
            return 0;
        }
        $end = $a->endsOn ?: now()->addYear()->toDateString();
        $q = DB::table('tbl_trips as t')->whereNotNull('t.startDate')
            ->where('t.startDate', '<=', $end)->whereRaw('DATE_ADD(t.startDate, INTERVAL GREATEST(COALESCE(t.days, 1), 1) - 1 DAY) >= ?', [$a->startsOn]);
        if ($a->placeId && Schema::hasTable('tbl_trip_items')) {
            $q->whereExists(fn($w) => $w->from('tbl_trip_items as i')->whereColumn('i.tripId', 't.tripId')->where('i.refType', 'place')->where('i.refId', $a->placeId));
        } elseif ($a->destinationId) {
            $q->where('t.destinationId', $a->destinationId);
        }
        try {
            return $q->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
