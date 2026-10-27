<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentIssues;
use App\Services\Content\CoverageHistory;
use App\Services\Content\CoverageScorer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Content Quality Dashboard (kế hoạch v6, mục 9 / demo 5): điểm Coverage từng điểm đến, cờ Planner-ready, danh sách lỗi, lịch sử. */
class ContentQualityController extends Controller
{
    private const SHOW = 100;

    public function index(Request $request, CoverageScorer $scorer, ContentIssues $issues, CoverageHistory $history)
    {
        $core = array_values(array_filter((array) config('core_destinations.slugs', [])));
        $scope = $request->query('scope') === 'all' || !$core ? 'all' : 'core';
        $scores = $scorer->scoreAll($scope === 'core' ? $core : null);

        $bySlug = collect($scores)->keyBy('slug');
        $slug = is_string($request->query('destination')) ? $request->query('destination') : '';
        $focus = $slug !== '' ? ($bySlug[$slug] ?? null) : null;
        $ids = $focus ? [$focus['destinationId']] : ($scope === 'core' ? array_column($scores, 'destinationId') : null);

        $all = $issues->all($ids);
        $code = is_string($request->query('code')) && isset(ContentIssues::CODES[$request->query('code')]) ? $request->query('code') : '';
        $shown = $code !== '' ? array_values(array_filter($all, fn($i) => $i['code'] === $code)) : $all;

        $names = collect($scores)->pluck('name', 'destinationId')->all();
        $pending = [];   // [destinationId][kind] => số mục chưa xuất bản
        foreach (['foods' => 'tbl_foods', 'experiences' => 'tbl_experiences', 'events' => 'tbl_events', 'places' => 'tbl_external_places'] as $kind => $table) {
            foreach (DB::table($table)->whereIn('destinationId', array_column($scores, 'destinationId'))->where('reviewStatus', '!=', 'published')
                ->selectRaw('destinationId d, COUNT(*) c')->groupBy('destinationId')->pluck('c', 'd') as $d => $c) {
                $pending[(int) $d][$kind] = (int) $c;
            }
        }

        return view('admin.content-quality', [
            'title' => 'Chất lượng nội dung',
            'scores' => $scores, 'scope' => $scope, 'hasCore' => (bool) $core, 'focus' => $focus, 'code' => $code,
            'codes' => ContentIssues::CODES, 'codeCounts' => ContentIssues::countByCode($all),
            'issues' => array_slice($shown, 0, self::SHOW), 'issueTotal' => count($shown), 'show' => self::SHOW,
            'names' => $names, 'pending' => $pending,
            'history' => $history->recent(array_column($scores, 'destinationId')),
            'avg' => $scores ? (int) round(array_sum(array_column($scores, 'percent')) / count($scores)) : 0,
            'ready' => count(array_filter($scores, fn($s) => $s['plannerReady'])),
        ]);
    }

    /** Lưu mốc hôm nay cho mọi điểm đến đang bật (ghi đè mốc cùng ngày). */
    public function snapshot(CoverageScorer $scorer, CoverageHistory $history)
    {
        $n = $history->snapshot($scorer->scoreAll());

        return back()->with('success', "Đã lưu mốc hôm nay cho {$n} điểm đến.");
    }
}
