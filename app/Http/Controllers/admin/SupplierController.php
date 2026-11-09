<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\SupplierService as S;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Nhà cung cấp dịch vụ tour: danh mục và đánh giá chất lượng. */
class SupplierController extends Controller
{
    public function index(S $svc)
    {
        $title = 'Nhà cung cấp';
        $ready = S::ready();
        $stats = $ready ? $svc->stats() : [];
        // cần xem xét lên đầu
        uasort($stats, fn ($a, $b) => [$b['attention'], $b['trips']] <=> [$a['attention'], $a['trips']]);

        return view('admin.suppliers.index', ['title' => $title, 'ready' => $ready, 'stats' => $stats, 'kinds' => S::KINDS]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|min:2|max:150', 'kind' => 'required|in:' . implode(',', array_keys(S::KINDS)), 'phone' => 'nullable|string|max:20', 'email' => 'nullable|email|max:80', 'note' => 'nullable|string|max:255']);
        if (DB::table('tbl_suppliers')->whereRaw('LOWER(name) = ?', [mb_strtolower($d['name'])])->where('kind', $d['kind'])->exists()) {
            return back()->withInput()->with('error', 'Nhà cung cấp này đã có trong danh mục.');
        }
        $id = DB::table('tbl_suppliers')->insertGetId($d + ['active' => true, 'createdAt' => now()]);
        AuditLog::record('supplier.create', 'supplier', $id, 'Thêm nhà cung cấp ' . $d['name']);

        return back()->with('success', 'Đã thêm nhà cung cấp #' . $id);
    }

    public function show(int $id, S $svc)
    {
        $st = $svc->stats($id)[$id] ?? abort(404);
        $ratings = DB::table('tbl_supplier_ratings as r')->leftJoin('tbl_tour_availability as a', 'a.availabilityId', '=', 'r.availabilityId')->leftJoin('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->where('r.supplierId', $id)->orderByDesc('r.ratingId')->limit(50)->get(['r.*', 't.title', 'a.departureDate']);
        $title = $st['supplier']->name;

        return view('admin.suppliers.show', ['title' => $title, 'st' => $st, 'ratings' => $ratings, 'kinds' => S::KINDS]);
    }

    public function toggle(int $id)
    {
        $n = DB::table('tbl_suppliers')->where('supplierId', $id)->update(['active' => DB::raw('NOT active')]);
        if ($n) {
            AuditLog::record('supplier.toggle', 'supplier', $id, 'Bật / tắt nhà cung cấp');
        }

        return back()->with('success', 'Đã cập nhật.');
    }
}
