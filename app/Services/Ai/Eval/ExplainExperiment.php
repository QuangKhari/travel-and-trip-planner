<?php

namespace App\Services\Ai\Eval;

use App\Services\Ai\Explain\ExplainService;
use App\Services\Planner\PlannerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bộ đánh giá F2 (tài liệu v2.2 mục 8.1, 8.2): chuyến do engine sinh ở nhiều điểm đến / độ dài, đo
 * tỷ lệ qua guard (không đoạn nào phải thay), số đoạn phải thay bằng câu mẫu, loại cờ guard, độ trễ, token.
 * Chất lượng lời văn và độ trung thực chấm bằng người: lệnh ghi lời kể ra CSV để chấm 1–5 (cột judge* để trống).
 */
final class ExplainExperiment
{
    public function __construct(private EvalKit $kit, private PlannerService $planner, private ExplainService $explain) {}

    public function run(array $case): array
    {
        $req = $case + ['month' => (int) date('n', strtotime($case['startDate'])), 'groupSize' => $case['groupSize'] ?? 2];
        $row = ['id' => $case['id'], 'slug' => $case['slug'], 'days' => $case['days']];

        return $this->kit->sandbox(function (int $uid) use ($req, $row) {
            $res = $this->planner->generate($req, true);
            if (empty($res['ok'])) {
                return $row + ['error' => $res['message'] ?? 'engine lỗi'];
            }
            $tripId = $this->planner->save($uid, $res);
            $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
            Cache::forget('ai:cb:until');
            Cache::forget('ai:cb:fails');
            $r = $this->explain->forTrip($trip, null, true);
            $run = DB::table('tbl_ai_runs')->where('tripId', $tripId)->where('feature', 'explain')->orderByDesc('runId')->first();
            $flat = fn($segs) => implode('', array_map(fn($s) => $s['t'] ?? ($s['name'] ?? ''), (array) $segs));
            $story = $flat($r['headline'] ?? []) . "\n" . implode("\n", array_map(fn($d) => $d['label'] . ': ' . $flat($d['summary']), $r['days'] ?? []))
                . "\n" . implode("\n", array_map(fn($t) => '- ' . $flat($t['text']), $r['tips'] ?? []));

            return $row + [
                'items' => array_sum(array_map(fn($d) => count($d['items'] ?? []), $r['days'] ?? [])),
                'engine' => $r['engine'] ?? 'none',
                'outcome' => $run->outcome ?? '',
                'replaced' => (int) ($r['guards']['replaced'] ?? 0),
                'passed' => ($r['engine'] ?? '') === 'llm' && (int) ($r['guards']['replaced'] ?? 0) === 0 ? 1 : 0,
                'flags' => $r['guards']['flags'] ?? [],
                'ms' => $run->latencyMs ?? 0, 'in' => $run->inputTokens ?? 0, 'tokOut' => $run->outputTokens ?? 0,
                'story' => $story,
                'judgeFaithful' => '', 'judgeUseful' => '',
            ];
        });
    }
}
