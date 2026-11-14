<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripSharing;
use App\Services\TourQuotes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TourQuoteController extends Controller
{
    /** POST /len-ke-hoach/{id}/bao-gia-tour */
    public function store(Request $request, int $id, TourQuotes $svc)
    {
        abort_unless(TourQuotes::ready(), 404);
        $acc = app(TripSharing::class)->editable((int) session('userId'), $id) ?? abort(404);
        $v = $request->validate(['note' => 'nullable|string|max:600']);
        try {
            $qid = $svc->request($acc['trip'], (int) session('userId'), isset($v['note']) ? strip_tags($v['note']) : null);
        } catch (\DomainException $e) {
            return back()->with('planner_notice', $e->getMessage());
        }

        return redirect()->route('quotes.show', ['id' => $qid])->with('success', 'Đã gửi yêu cầu. Travela thường báo giá trong 1 ngày làm việc; bạn sẽ nhận email và thông báo ở chuông của chuyến.');
    }

    /** GET /bao-gia-tour : các yêu cầu của tôi */
    public function index()
    {
        $rows = TourQuotes::ready() ? DB::table('tbl_tour_quotes as q')->leftJoin('tbl_trips as t', 't.tripId', '=', 'q.tripId')->where('q.userId', (int) session('userId'))
            ->orderByDesc('q.quoteId')->get(['q.*', 't.title']) : collect();

        return view('clients.quotes.index', ['title' => 'Báo giá tour riêng', 'rows' => $rows]);
    }

    public function show(int $id)
    {
        $q = DB::table('tbl_tour_quotes')->where('quoteId', $id)->where('userId', (int) session('userId'))->first() ?? abort(404);

        return view('clients.quotes.show', ['title' => 'Báo giá tour riêng #' . $id, 'q' => $q, 'snap' => json_decode((string) $q->snapshot, true)]);
    }

    public function decide(Request $request, int $id, TourQuotes $svc)
    {
        $v = $request->validate(['decision' => 'required|in:accept,decline']);
        try {
            $svc->decide($id, (int) session('userId'), $v['decision'] === 'accept');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $v['decision'] === 'accept' ? 'Đã nhận báo giá. Nhân viên Travela sẽ liên hệ để chốt dịch vụ.' : 'Đã từ chối báo giá.');
    }
}
