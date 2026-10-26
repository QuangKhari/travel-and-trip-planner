<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** Tổng quan nội dung: số bài theo trạng thái, độ phủ theo điểm đến, bài cẩm nang quá hạn cập nhật. */
class ContentOverviewController extends Controller
{
    private const STALE_DAYS = 365;

    public function index()
    {
        $tables = ['foods' => ['Món ăn', 'tbl_foods'], 'experiences' => ['Trải nghiệm', 'tbl_experiences'], 'events' => ['Lễ hội', 'tbl_events'], 'guides' => ['Cẩm nang', 'tbl_guides'], 'itineraries' => ['Lịch trình', 'tbl_itineraries'], 'places' => ['Địa điểm OSM', 'tbl_external_places']];
        $byStatus = [];
        foreach ($tables as $k => [$label, $t]) {
            $byStatus[$k] = ['label' => $label] + DB::table($t)->selectRaw('reviewStatus s, COUNT(*) c')->groupBy('reviewStatus')->pluck('c', 's')->all();
        }

        $coverage = DB::table('tbl_destinations as d')
            ->leftJoin(DB::raw("(SELECT destinationId, COUNT(*) c FROM tbl_foods WHERE reviewStatus='published' GROUP BY destinationId) f"), 'f.destinationId', '=', 'd.destinationId')
            ->leftJoin(DB::raw("(SELECT destinationId, COUNT(*) c FROM tbl_experiences WHERE reviewStatus='published' GROUP BY destinationId) x"), 'x.destinationId', '=', 'd.destinationId')
            ->leftJoin(DB::raw("(SELECT destinationId, COUNT(*) c FROM tbl_events WHERE reviewStatus='published' GROUP BY destinationId) e"), 'e.destinationId', '=', 'd.destinationId')
            ->leftJoin(DB::raw("(SELECT destinationId, COUNT(*) c FROM tbl_external_places WHERE reviewStatus='published' GROUP BY destinationId) p"), 'p.destinationId', '=', 'd.destinationId')
            ->leftJoin(DB::raw('(SELECT destinationId, COUNT(*) c FROM tbl_destination_months GROUP BY destinationId) m'), 'm.destinationId', '=', 'd.destinationId')
            ->leftJoin(DB::raw('(SELECT destinationId, COUNT(*) c FROM tbl_tours GROUP BY destinationId) t'), 't.destinationId', '=', 'd.destinationId')
            ->where('d.isActive', 1)
            ->select('d.destinationId', 'd.name', DB::raw('COALESCE(f.c,0) foods'), DB::raw('COALESCE(x.c,0) experiences'), DB::raw('COALESCE(e.c,0) events'), DB::raw('COALESCE(p.c,0) places'), DB::raw('COALESCE(m.c,0) months'), DB::raw('COALESCE(t.c,0) tours'))
            ->orderBy('d.name')->get();

        $stale = DB::table('tbl_guides')->where('reviewStatus', 'published')
            ->where('updatedOn', '<', now()->subDays(self::STALE_DAYS)->toDateString())->orderBy('updatedOn')->limit(50)->get(['guideId', 'title', 'updatedOn']);

        return view('admin.content-overview', [
            'title' => 'Tổng quan nội dung', 'byStatus' => $byStatus, 'coverage' => $coverage, 'stale' => $stale, 'staleDays' => self::STALE_DAYS,
            'noDestTours' => DB::table('tbl_tours')->whereNull('destinationId')->count(),
            'rejected' => DB::table('tbl_foods')->where('reviewStatus', 'draft')->whereNotNull('reviewNote')->count(),
        ]);
    }
}
