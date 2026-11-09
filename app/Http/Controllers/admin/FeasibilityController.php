<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\FeasibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Kiểm tra tính khả thi của lịch trình tour. */
class FeasibilityController extends Controller
{
    public function index(Request $request, FeasibilityService $svc)
    {
        $title = 'Kiểm tra khả thi tour';
        $q = DB::table('tbl_tours')->orderByDesc('tourId');
        if ($request->query('q')) {
            $q->where('title', 'like', '%' . $request->query('q') . '%');
        }
        $tours = $q->select('tourId', 'title', 'destination')->paginate(20)->withQueryString();
        foreach ($tours->items() as $t) {
            try {
                $t->fz = $svc->check((int) $t->tourId);
            } catch (\Throwable $e) {
                report($e);
                $t->fz = null;
            }
        }

        return view('admin.feasibility.index', compact('title', 'tours'));
    }

    public function show(Request $request, int $id, FeasibilityService $svc)
    {
        $tour = DB::table('tbl_tours')->where('tourId', $id)->first() ?? abort(404);
        $date = $request->query('date');
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        $fz = $svc->check($id, $date);
        $deps = DB::table('tbl_tour_availability')->where('tourId', $id)->where('departureDate', '>', today()->toDateString())->orderBy('departureDate')->pluck('departureDate');
        $title = 'Khả thi: ' . $tour->title;

        return view('admin.feasibility.show', compact('title', 'tour', 'fz', 'deps'));
    }
}
