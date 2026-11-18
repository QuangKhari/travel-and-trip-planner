<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\TourStops;
use App\Services\TourVersionService;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TourStopsAdminController extends Controller
{
    public function edit(int $id, TourStops $svc)
    {
        $tour = DB::table('tbl_tours')->where('tourId', $id)->first() ?? abort(404);

        $rd = $svc->readiness($id);

        return view('admin.tour-stops', ['title' => 'Lịch trình theo giờ', 'tour' => $tour, 'stops' => $svc->forTour($id), 'issues' => $svc->check($tour), 'readiness' => $rd,
            'days' => TourStops::tourDays($tour) ?? 1, 'kinds' => TourStops::KINDS, 'ready' => TourStops::ready(),
            'addons' => \Illuminate\Support\Facades\Schema::hasTable('tbl_tour_addons') ? DB::table('tbl_tour_addons')->where('tourId', $id)->orderBy('addonId')->get() : collect()]);
    }

    public function save(Request $request, int $id, TourStops $svc)
    {
        $tour = DB::table('tbl_tours')->where('tourId', $id)->first() ?? abort(404);
        $max = max(1, TourStops::tourDays($tour) ?? 14);
        $v = $request->validate([
            'stops' => 'nullable|array|max:120',
            'stops.*.dayNo' => 'required|integer|between:1,' . $max,
            'stops.*.start' => 'nullable|date_format:H:i',
            'stops.*.end' => 'nullable|date_format:H:i',
            'stops.*.kind' => 'required|in:' . implode(',', array_keys(TourStops::KINDS)),
            'stops.*.title' => 'required|string|max:160',
            'stops.*.note' => 'nullable|string|max:400',
            'stops.*.placeId' => 'nullable|integer|exists:tbl_external_places,placeId',
        ], ['stops.*.dayNo.between' => 'Ngày phải từ 1 đến ' . $max . ' (số ngày của tour).', 'stops.*.title.required' => 'Mỗi điểm dừng cần tên.']);
        foreach ($v['stops'] ?? [] as $i => $r) {
            if (!empty($r['start']) && !empty($r['end']) && $r['end'] <= $r['start']) {
                return back()->withInput()->withErrors(["stops.$i.end" => 'Dòng ' . ($i + 1) . ': giờ kết thúc phải sau giờ bắt đầu.']);
            }
        }
        $rows = array_map(fn($r) => $r + ['refType' => !empty($r['placeId']) ? 'place' : null, 'refId' => !empty($r['placeId']) ? (int) $r['placeId'] : null], array_values($v['stops'] ?? []));
        // Phiên bản nội dung: đơn đã đặt giữ lịch trình theo giờ cũ, đơn mới gắn bản mới
        $versions = app(TourVersionService::class);
        $before = $versions->beforeEdit($id, (int) session('adminId') ?: null);
        $n = $svc->save($id, $rows);
        $ver = $versions->afterEdit($id, $before, (int) session('adminId') ?: null);
        AuditLog::record('tour.stops', 'tour', $id, 'Cập nhật lịch trình theo giờ: ' . $n . ' điểm dừng' . ($ver['changed'] ? ' (phiên bản ' . $ver['version'] . ', ' . $ver['affected'] . ' đơn giữ bản cũ)' : ''));
        $rd = $svc->readiness($id);
        $msg = 'Đã lưu ' . $n . ' điểm dừng.';
        if ($rd['blockers']) {
            $msg .= ' Còn ' . count($rd['blockers']) . ' lỗi chặn: ' . ($rd['status'] === 'published' ? 'tour ĐANG BÁN, hãy sửa ngay.' : 'tour chưa đăng / hiện lại được cho tới khi sửa.');
        } elseif ($rd['warnings']) {
            $msg .= ' Còn ' . count($rd['warnings']) . ' điều nên kiểm tra.';
        }

        return redirect()->route('admin.tour-stops', $id)->with($rd['blockers'] && $rd['status'] === 'published' ? 'error' : 'success', $msg);
    }

    public function storeAddon(Request $request, int $id)
    {
        abort_unless(DB::table('tbl_tours')->where('tourId', $id)->exists(), 404);
        $v = $request->validate(['name' => 'required|string|max:160', 'description' => 'nullable|string|max:400', 'price' => 'required|integer|min:0|max:1000000000', 'unit' => 'required|in:person,group']);
        $versions = app(TourVersionService::class);
        $before = $versions->beforeEdit($id, (int) session('adminId') ?: null);
        $aid = DB::table('tbl_tour_addons')->insertGetId($v + ['tourId' => $id, 'isActive' => 1, 'createdAt' => now()], 'addonId');
        $versions->afterEdit($id, $before, (int) session('adminId') ?: null);
        AuditLog::record('tour.addon', 'tour', $id, 'Thêm hoạt động thêm #' . $aid . ': ' . $v['name']);

        return back()->with('success', 'Đã thêm hoạt động "' . $v['name'] . '". Khách thấy ở bước đặt tour.');
    }

    /** Không xóa (đơn cũ còn tham chiếu và giữ giá lúc đặt ở tbl_booking_addons): chỉ bật / tắt. */
    public function toggleAddon(int $id, int $addonId)
    {
        $a = DB::table('tbl_tour_addons')->where('tourId', $id)->where('addonId', $addonId)->first() ?? abort(404);
        $versions = app(TourVersionService::class);
        $before = $versions->beforeEdit($id, (int) session('adminId') ?: null);
        DB::table('tbl_tour_addons')->where('addonId', $addonId)->update(['isActive' => $a->isActive ? 0 : 1]);
        $versions->afterEdit($id, $before, (int) session('adminId') ?: null);

        return back()->with('success', $a->isActive ? 'Đã tắt.' : 'Đã bật.');
    }
}
