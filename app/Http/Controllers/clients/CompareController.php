<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\DepartureService;
use App\Support\ReviewScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** C-11: so sánh 2–3 tour cạnh nhau (giá, thời lượng, ngày khởi hành còn chỗ, đánh giá, bao gồm / không bao gồm, lộ trình). */
class CompareController extends Controller
{
    public function index(Request $request, DepartureService $deps)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))->map(fn($v) => (int) $v)->filter()->unique()->take(3)->values();
        $tours = DB::table('tbl_tours')->whereIn('tourId', $ids)->where('availability', 1)->get()->keyBy('tourId');
        $rows = $ids->map(fn($id) => $tours[$id] ?? null)->filter()->values()->map(function ($t) use ($deps) {
            $t->cover = DB::table('tbl_images')->where('tourId', $t->tourId)->orderByDesc('isCover')->orderBy('sortOrder')->value('imageURL');
            $q = DB::table('tbl_reviews')->where('tourId', $t->tourId);
            ReviewScope::visible($q);
            $r = $q->selectRaw('AVG(rating) a, COUNT(*) n')->first();
            $t->rating = $r->n ? round((float) $r->a, 1) : null;
            $t->ratingCount = (int) $r->n;
            $t->days = DepartureService::durationDays($t);
            $t->departures = array_slice(array_values(array_filter($deps->forTour((int) $t->tourId, true), fn($d) => $d->remaining > 0)), 0, 4);
            $t->timeline = DB::table('tbl_timeline')->where('tourId', $t->tourId)->orderBy('timeLineId')->pluck('title');
            $split = fn($s) => collect(preg_split('/\r?\n/', strip_tags((string) $s)))->map(fn($x) => trim($x, " \t-•"))->filter()->values();
            $t->inc = $split($t->includes ?? '');
            $t->exc = $split($t->excludes ?? '');
            $t->perDay = $t->days ? (int) round($t->priceAdult / $t->days) : null;

            return $t;
        });
        $title = 'So sánh tour';
        $cheapest = $rows->min('priceAdult');
        $best = $rows->max('rating');

        return view('clients.compare', compact('title', 'rows', 'cheapest', 'best'));
    }
}
