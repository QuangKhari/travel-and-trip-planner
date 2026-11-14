<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Planner\PlaceReports;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dữ liệu địa điểm cho Trip Planner: người dùng báo sai (duyệt / từ chối) và giờ đặc biệt (đóng cửa tạm thời, giờ theo mùa).
 */
class PlaceDataAdminController extends Controller
{
    public function index(Request $request)
    {
        $ready = PlaceReports::ready() && Schema::hasTable('tbl_hours_rules');
        $tab = in_array($request->query('tab'), ['reports', 'rules', 'verify'], true) ? $request->query('tab') : 'reports';
        $status = in_array($request->query('status'), ['open', 'accepted', 'rejected'], true) ? $request->query('status') : 'open';
        $reports = collect();
        $rules = collect();
        $counts = ['open' => 0];
        if ($ready) {
            $counts['open'] = DB::table('tbl_place_reports')->where('status', 'open')->count();
            $reports = DB::table('tbl_place_reports as r')->leftJoin('tbl_users as u', 'u.userId', '=', 'r.userId')
                ->where('r.status', $status)->orderByDesc('r.reportId')->limit(200)->get(['r.*', 'u.username']);
            foreach ($reports as $r) {
                $r->name = PlaceReports::name($r->refType, (int) $r->refId) ?? '(đã xóa)';
                $r->same = DB::table('tbl_place_reports')->where(['refType' => $r->refType, 'refId' => $r->refId, 'kind' => $r->kind, 'status' => 'open'])->count();
                $r->hours = DB::table('tbl_opening_hours')->where('refType', $r->refType)->where('refId', $r->refId)->exists();
            }
            $rules = DB::table('tbl_hours_rules as h')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'h.destinationId')
                ->where(fn($q) => $q->where('h.yearly', 1)->orWhere('h.dateTo', '>=', now()->subDays(30)->toDateString()))
                ->orderByDesc('h.ruleId')->limit(200)->get(['h.*', 'd.name as destName']);
            foreach ($rules as $h) {
                $h->name = $h->refType ? (PlaceReports::name($h->refType, (int) $h->refId) ?? '(đã xóa)') : 'Cả điểm đến ' . ($h->destName ?? '');
            }
        }

        $facts = app(\App\Services\Planner\PlaceFacts::class);
        $dest = (int) $request->query('dest') ?: null;
        $queue = $ready && $tab === 'verify' ? $facts->queue($dest) : [];
        $counts['verify'] = $ready ? $facts->count() : 0;

        return view('admin.place-data.index', ['title' => 'Dữ liệu địa điểm', 'ready' => $ready, 'tab' => $tab, 'status' => $status, 'queue' => $queue, 'dest' => $dest,
            'reports' => $reports, 'rules' => $rules, 'counts' => $counts, 'kinds' => PlaceReports::KINDS,
            'destinations' => DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['destinationId', 'name'])]);
    }

    public function decide(Request $request, int $id, PlaceReports $svc)
    {
        $v = $request->validate(['decision' => 'required|in:accept,reject', 'suggested' => 'nullable|string|max:120', 'note' => 'nullable|string|max:255']);
        $r = DB::table('tbl_place_reports')->where('reportId', $id)->where('status', 'open')->first() ?? abort(404);
        if (array_key_exists('suggested', $v) && $v['suggested'] !== null) {
            DB::table('tbl_place_reports')->where('reportId', $id)->update(['suggested' => $v['suggested']]);
            $r->suggested = $v['suggested'];
        }
        $admin = (int) session('adminId');
        if ($v['decision'] === 'reject') {
            DB::table('tbl_place_reports')->where('reportId', $id)->update(['status' => 'rejected', 'reviewedBy' => $admin, 'reviewedAt' => now(), 'reviewNote' => $v['note'] ?? null]);
            AuditLog::record('place_report.reject', 'place_report', $id, 'Từ chối báo sai #' . $id);

            return back()->with('success', 'Đã từ chối báo cáo #' . $id . '.');
        }
        $res = $svc->accept($r, $admin, $v['note'] ?? null);
        if (!$res['ok']) {
            return back()->withErrors(['report' => $res['message']]);
        }
        AuditLog::record('place_report.accept', 'place_report', $id, 'Duyệt báo sai #' . $id . ': ' . $res['message']);

        return back()->with('success', $res['message']);
    }

    public function storeRule(Request $request)
    {
        $v = $request->validate([
            'target' => 'required|in:place,experience,destination',
            'refId' => 'required_unless:target,destination|nullable|integer|min:1',
            'destinationId' => 'required_if:target,destination|nullable|integer|exists:tbl_destinations,destinationId',
            'kind' => 'required|in:closed,hours',
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date|after_or_equal:dateFrom',
            'yearly' => 'nullable|boolean',
            'open' => 'required_if:kind,hours|nullable|date_format:H:i',
            'close' => 'required_if:kind,hours|nullable|date_format:H:i|after:open',
            'note' => 'nullable|string|max:200',
        ], ['dateTo.after_or_equal' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi.', 'close.after' => 'Giờ đóng phải sau giờ mở.']);
        if ($v['target'] !== 'destination' && PlaceReports::name($v['target'], (int) $v['refId']) === null) {
            return back()->withInput()->withErrors(['refId' => 'Không có ' . ($v['target'] === 'place' ? 'địa điểm' : 'trải nghiệm') . ' mã ' . $v['refId'] . '.']);
        }
        $m = fn(?string $t) => $t ? (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2) : null;
        $id = DB::table('tbl_hours_rules')->insertGetId([
            'refType' => $v['target'] === 'destination' ? null : $v['target'], 'refId' => $v['target'] === 'destination' ? null : (int) $v['refId'],
            'destinationId' => $v['target'] === 'destination' ? (int) $v['destinationId'] : null, 'kind' => $v['kind'],
            'dateFrom' => $v['dateFrom'], 'dateTo' => $v['dateTo'], 'yearly' => !empty($v['yearly']),
            'openMin' => $v['kind'] === 'hours' ? $m($v['open']) : null, 'closeMin' => $v['kind'] === 'hours' ? $m($v['close']) : null,
            'note' => $v['note'] ?? null, 'source' => 'manual', 'createdBy' => (int) session('adminId') ?: null,
        ], 'ruleId');
        AuditLog::record('hours_rule.create', 'hours_rule', $id, 'Thêm giờ đặc biệt #' . $id);

        return redirect()->route('admin.place-data', ['tab' => 'rules'])->with('success', 'Đã thêm. Bộ xếp lịch và chuông theo dõi chuyến dùng quy tắc này ngay.');
    }

    /** "Cần kiểm tra": xác nhận dữ kiện vẫn đúng hoặc sửa giờ / giá, ghi nguồn + ngày kiểm tra */
    public function verify(Request $request, int $id)
    {
        $v = $request->validate([
            'hours' => 'nullable|string|max:255', 'priceMin' => 'nullable|integer|min:0|max:100000000', 'priceMax' => 'nullable|integer|min:0|max:100000000',
            'source' => 'nullable|string|max:120', 'url' => 'nullable|url|max:255',
        ], ['url.url' => 'Đường dẫn nguồn phải đầy đủ (https://...).']);
        abort_unless(DB::table('tbl_external_places')->where('placeId', $id)->exists(), 404);
        if (!empty($v['hours']) && !\App\Services\Planner\OpeningHours::parseOsm($v['hours'])) {
            return back()->withErrors(['hours' => 'Giờ chưa đúng dạng, ví dụ "Mo-Su 08:00-17:00; Mo off; PH off".']);
        }
        app(\App\Services\Planner\PlaceFacts::class)->verify($id, $v['hours'] ?? null, isset($v['priceMin']) ? (int) $v['priceMin'] : null,
            isset($v['priceMax']) ? (int) $v['priceMax'] : null, $v['source'] ?? null, $v['url'] ?? null, (int) session('adminId'));
        AuditLog::record('place_facts.verify', 'place', $id, 'Kiểm tra dữ kiện địa điểm #' . $id);

        return back()->with('success', 'Đã ghi ngày kiểm tra cho #' . $id . '. Nơi này rời hàng chờ trong ' . \App\Services\Planner\PlaceFacts::STALE_DAYS . ' ngày.');
    }

    public function deleteRule(int $id)
    {
        DB::table('tbl_hours_rules')->where('ruleId', $id)->delete();
        AuditLog::record('hours_rule.delete', 'hours_rule', $id, 'Xóa giờ đặc biệt #' . $id);

        return back()->with('success', 'Đã xóa quy tắc #' . $id . '.');
    }
}
