<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\TourQuotes;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TourQuoteAdminController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['open', 'quoted', 'accepted', 'declined', 'all'], true) ? $request->query('status') : 'open';
        $rows = TourQuotes::ready() ? DB::table('tbl_tour_quotes as q')->leftJoin('tbl_users as u', 'u.userId', '=', 'q.userId')->leftJoin('tbl_trips as t', 't.tripId', '=', 'q.tripId')
            ->when($status !== 'all', fn($x) => $x->where('q.status', $status))->orderByDesc('q.quoteId')->limit(200)->get(['q.*', 'u.fullName', 'u.username', 't.title']) : collect();
        $counts = TourQuotes::ready() ? DB::table('tbl_tour_quotes')->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status')->all() : [];

        return view('admin.tour-quotes.index', ['title' => 'Báo giá tour riêng', 'rows' => $rows, 'status' => $status, 'counts' => $counts, 'ready' => TourQuotes::ready()]);
    }

    public function show(int $id)
    {
        $q = DB::table('tbl_tour_quotes as q')->leftJoin('tbl_users as u', 'u.userId', '=', 'q.userId')->where('q.quoteId', $id)
            ->first(['q.*', 'u.fullName', 'u.username', 'u.email', 'u.phoneNumber']) ?? abort(404);

        return view('admin.tour-quotes.show', ['title' => 'Báo giá #' . $id, 'q' => $q, 'snap' => json_decode((string) $q->snapshot, true)]);
    }

    public function quote(Request $request, int $id, TourQuotes $svc)
    {
        $v = $request->validate(['pricePerPerson' => 'required|integer|min:1000|max:1000000000', 'includes' => 'nullable|string|max:600', 'excludes' => 'nullable|string|max:600',
            'message' => 'nullable|string|max:1000', 'validUntil' => 'required|date|after_or_equal:today']);
        try {
            $svc->quote($id, $v, (int) session('adminId'));
        } catch (\DomainException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }
        AuditLog::record('tour_quote.quote', 'tour_quote', $id, 'Báo giá tour riêng #' . $id . ': ' . $v['pricePerPerson'] . 'đ/người');

        return back()->with('success', 'Đã gửi báo giá cho khách (email + chuông của chuyến).');
    }
}
