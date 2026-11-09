<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\ProfitabilityService as P;
use App\Services\SupplierService;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Chi phí và lợi nhuận từng đoàn khởi hành. */
class ProfitController extends Controller
{
    public function index(Request $request, P $svc)
    {
        $title = 'Lợi nhuận từng đoàn';
        $ready = P::ready();
        $range = in_array($request->query('range'), ['past', 'future', 'all'], true) ? $request->query('range') : 'past';
        $rows = $ready ? $svc->listing($range, $request->query('tourId') ? (int) $request->query('tourId') : null) : [];
        $tot = ['net' => 0, 'cost' => 0, 'profit' => 0, 'loss' => 0];
        foreach ($rows as $r) {
            $tot['net'] += $r->p['netRevenue'];
            $tot['cost'] += $r->p['costTotal'];
            $tot['profit'] += $r->p['profit'];
            $tot['loss'] += ($r->p['hasCosts'] && $r->p['loss']) ? 1 : 0;
        }

        return view('admin.profit.index', compact('title', 'ready', 'rows', 'range', 'tot'));
    }

    public function show(int $id, P $svc)
    {
        $dep = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')->where('a.availabilityId', $id)->first(['a.*', 't.title']) ?? abort(404);
        $costs = DB::table('tbl_departure_costs as c')->leftJoin('tbl_suppliers as s', 's.supplierId', '=', 'c.supplierId')->where('c.availabilityId', $id)->orderBy('c.costId')->get(['c.*', 's.name as supplierName']);
        $suppliers = SupplierService::ready() ? DB::table('tbl_suppliers')->where('active', true)->orderBy('name')->get(['supplierId', 'name', 'kind']) : collect();
        $rated = SupplierService::ready() ? DB::table('tbl_supplier_ratings')->where('availabilityId', $id)->pluck('rating', 'supplierId') : collect();
        $used = $costs->pluck('supplierId')->filter()->unique()->values();
        $title = 'Chi phí đoàn #' . $id;

        return view('admin.profit.show', ['title' => $title, 'dep' => $dep, 'costs' => $costs, 'p' => $svc->summary($id), 'suppliers' => $suppliers, 'rated' => $rated, 'used' => $used,
            'cats' => P::CATEGORIES, 'past' => $dep->departureDate <= today()->toDateString()]);
    }

    public function addCost(Request $request, int $id)
    {
        $d = $request->validate([
            'category' => 'required|in:' . implode(',', array_keys(P::CATEGORIES)), 'description' => 'required|string|min:3|max:200',
            'amount' => 'required|integer|min:1|max:100000000000', 'status' => 'required|in:estimated,actual', 'supplierId' => 'nullable|integer|exists:tbl_suppliers,supplierId',
        ]);
        abort_unless(DB::table('tbl_tour_availability')->where('availabilityId', $id)->exists(), 404);
        $cid = DB::table('tbl_departure_costs')->insertGetId(['availabilityId' => $id, 'supplierId' => $d['supplierId'] ?? null, 'category' => $d['category'], 'description' => $d['description'],
            'amount' => $d['amount'], 'status' => $d['status'], 'createdBy' => (int) session('adminId'), 'createdAt' => now(), 'updatedAt' => now()]);
        AuditLog::record('cost.create', 'departure', $id, 'Thêm chi phí ' . number_format($d['amount'], 0, ',', '.') . ' đ: ' . $d['description']);

        return back()->with('success', 'Đã thêm chi phí #' . $cid);
    }

    public function costStatus(int $id, int $costId)
    {
        $n = DB::table('tbl_departure_costs')->where('costId', $costId)->where('availabilityId', $id)->where('status', 'estimated')->update(['status' => 'actual', 'updatedAt' => now()]);
        if ($n) {
            AuditLog::record('cost.actual', 'departure', $id, 'Chốt chi phí #' . $costId . ' là thực tế');
        }

        return back()->with($n ? 'success' : 'error', $n ? 'Đã chốt là chi phí thực tế.' : 'Không đổi được.');
    }

    public function deleteCost(int $id, int $costId)
    {
        $n = DB::table('tbl_departure_costs')->where('costId', $costId)->where('availabilityId', $id)->delete();
        if ($n) {
            AuditLog::record('cost.delete', 'departure', $id, 'Xóa chi phí #' . $costId);
        }

        return back()->with($n ? 'success' : 'error', $n ? 'Đã xóa chi phí.' : 'Không xóa được.');
    }

    public function rate(Request $request, int $id, SupplierService $svc)
    {
        $d = $request->validate(['supplierId' => 'required|integer', 'rating' => 'required|integer|min:1|max:5', 'onTime' => 'nullable|in:0,1', 'comment' => 'nullable|string|max:500']);
        $r = $svc->rate((int) $d['supplierId'], $id, (int) $d['rating'], isset($d['onTime']) && $d['onTime'] !== '' ? (bool) $d['onTime'] : null, $d['comment'] ?? null, (int) session('adminId'));
        if ($r['ok']) {
            AuditLog::record('supplier.rate', 'supplier', (int) $d['supplierId'], 'Đánh giá ' . $d['rating'] . '/5 cho đoàn #' . $id);
        }

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }
}
