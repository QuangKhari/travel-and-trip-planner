<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\PriceRules;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Quy tắc giá theo mùa (tăng / giảm % theo khoảng ngày khởi hành, cho một tour hoặc mọi tour). */
class PriceRuleController extends Controller
{
    public function index()
    {
        abort_unless(PriceRules::ready(), 503, 'Chạy php artisan migrate.');

        return view('admin.price-rules', [
            'title' => 'Giá theo mùa',
            'rules' => DB::table('tbl_price_rules as r')->leftJoin('tbl_tours as t', 't.tourId', '=', 'r.tourId')->orderByDesc('r.isActive')->orderBy('r.dateFrom')
                ->get(['r.*', 't.title']),
            'tours' => DB::table('tbl_tours')->orderBy('title')->get(['tourId', 'title']),
        ]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'tourId' => ['nullable', 'integer', 'exists:tbl_tours,tourId'],
            'dateFrom' => ['required', 'date'],
            'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'],
            'pct' => ['required', 'integer', 'min:' . PriceRules::MIN, 'max:' . PriceRules::MAX, 'not_in:0'],
            'label' => ['required', 'string', 'max:80'],
        ]);
        $id = DB::table('tbl_price_rules')->insertGetId(['tourId' => $v['tourId'] ?? null, 'dateFrom' => $v['dateFrom'], 'dateTo' => $v['dateTo'], 'pct' => (int) $v['pct'],
            'label' => strip_tags($v['label']), 'isActive' => 1, 'createdBy' => (int) session('adminId') ?: null, 'createdAt' => now()], 'ruleId');
        AuditLog::record('price_rule.create', 'price_rule', $id, 'Giá theo mùa: ' . $v['label'] . ' ' . ($v['pct'] > 0 ? '+' : '') . $v['pct'] . '%', [], $v);

        return back()->with('success', 'Đã thêm quy tắc. Chỉ áp cho đơn đặt từ bây giờ; đơn đã đặt giữ mức đã chốt.');
    }

    public function toggle(int $id)
    {
        $r = DB::table('tbl_price_rules')->where('ruleId', $id)->first() ?? abort(404);
        DB::table('tbl_price_rules')->where('ruleId', $id)->update(['isActive' => $r->isActive ? 0 : 1]);
        AuditLog::record('price_rule.toggle', 'price_rule', $id, ($r->isActive ? 'Tắt' : 'Bật') . ' giá theo mùa: ' . $r->label, ['isActive' => (bool) $r->isActive], ['isActive' => !$r->isActive]);

        return back()->with('success', $r->isActive ? 'Đã tắt quy tắc.' : 'Đã bật quy tắc.');
    }
}
