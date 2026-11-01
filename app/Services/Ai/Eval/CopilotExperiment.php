<?php

namespace App\Services\Ai\Eval;

use App\Services\Ai\Copilot\CopilotService;
use App\Services\Planner\PlannerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Thí nghiệm B (tài liệu v2.2 mục 8.3): cùng yêu cầu chỉnh lịch, hai chế độ.
 *   verified  (B2) La bàn Copilot đầy đủ: compiler + TripHealth chặn vấn đề cứng mới + vòng sửa
 *   unchecked (B1) cùng LLM, cùng lời nhắc, nhưng đề xuất đầu tiên được nhận luôn (không chặn, không sửa)
 * Chuyến của mỗi mẫu do CHÍNH engine sinh (cùng yêu cầu thì cùng chuyến), có thể "làm hỏng" có chủ đích (dồn hết vào ngày 1...).
 * Câu yêu cầu dùng chỗ giữ {d1i2} = tên mục thứ 2 của ngày 1 trong chuyến vừa sinh, nên bộ mẫu chạy được trên dữ liệu của bạn.
 */
final class CopilotExperiment
{
    public const MODES = ['verified', 'unchecked'];

    public function __construct(private EvalKit $kit, private PlannerService $planner, private CopilotService $copilot) {}

    public function run(array $case, string $mode): array
    {
        $t = $case['trip'];
        $req = ['slug' => $t['slug'], 'days' => $t['days'], 'startDate' => $t['startDate'], 'month' => (int) date('n', strtotime($t['startDate'])),
            'groupSize' => $t['groupSize'] ?? 2, 'interests' => $t['interests'] ?? [], 'pace' => $t['pace'] ?? 'normal'];
        $row = ['id' => $case['id'], 'group' => $case['group'] ?? '', 'source' => $case['source'] ?? 'author', 'mode' => $mode];

        return $this->kit->sandbox(function (int $uid) use ($case, $req, $mode, $row, $t) {
            $res = $this->planner->generate($req, true);
            if (empty($res['ok'])) {
                return $row + ['error' => $res['message'] ?? 'engine lỗi'];
            }
            $tripId = $this->planner->save($uid, $res);
            $this->mutate($tripId, (string) ($t['mutate'] ?? ''));
            $names = $this->names($tripId);
            $text = preg_replace_callback('/\{d(\d+)i(\d+)\}/', fn($m) => $names[(int) $m[1]][(int) $m[2] - 1] ?? '???', $case['request']);
            if (str_contains($text, '???')) {
                return $row + ['error' => 'chuyến sinh ra thiếu mục cho chỗ giữ trong câu'];
            }
            $touch = isset($case['expect']['touch']) ? preg_replace_callback('/\{d(\d+)i(\d+)\}/', fn($m) => $names[(int) $m[1]][(int) $m[2] - 1] ?? '', $case['expect']['touch']) : null;

            $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
            Cache::forget('ai:cb:until');
            Cache::forget('ai:cb:fails');
            $t0 = microtime(true);
            $r = $this->copilot->chat($trip, null, $text, [], [], ['verify' => $mode === 'verified']);
            $ms = EvalKit::ms($t0);
            $run = DB::table('tbl_ai_runs')->where('tripId', $tripId)->orderByDesc('runId')->first();

            $exp = $case['expect'] ?? [];
            $kind = $r['kind'] ?? 'error';
            $ops = array_values(array_unique(array_column($r['changes'] ?? [], 'op')));
            $human = implode(' | ', array_column($r['steps_human'] ?? [], 'text'));
            $resolved = array_column($r['receipt']['resolved'] ?? [], 'code');
            $wantKind = $exp['kind'] ?? 'any';

            return $row + [
                'request' => $text,
                'kind' => $kind,
                'kindOk' => $wantKind === 'any' || $wantKind === $kind ? 1 : 0,
                'ops' => implode(',', $ops),
                'opsOk' => isset($exp['ops']) ? (array_intersect($exp['ops'], $ops) ? 1 : 0) : '',
                'newHard' => $kind === 'proposal' ? (int) ($r['receipt']['newHard'] ?? 0) : 0,
                'resolvesOk' => isset($exp['resolves']) ? (in_array($exp['resolves'], $resolved, true) ? 1 : 0) : '',
                'touchOk' => $touch ? (str_contains($human, $touch) ? 1 : 0) : '',
                'scoreBefore' => $r['receipt']['before']['score'] ?? '',
                'scoreAfter' => $r['receipt']['after']['score'] ?? '',
                'steps' => (int) ($r['steps'] ?? 0),
                'repairs' => $run ? (int) (json_decode((string) $run->guardFlags, true)['repairs'] ?? 0) : 0,
                'ms' => $ms,
                'in' => $run->inputTokens ?? 0, 'tokOut' => $run->outputTokens ?? 0,
                'outcome' => $run->outcome ?? '',
                'human' => $human,
            ];
        });
    }

    /** "Làm hỏng" chuyến có chủ đích để có vấn đề cần sửa. */
    private function mutate(int $tripId, string $how): void
    {
        if ($how === 'overload_day1') {
            $i = 0;
            foreach (DB::table('tbl_trip_items')->where('tripId', $tripId)->whereNotNull('dayNo')->orderBy('dayNo')->orderBy('orderNo')->get(['itemId']) as $r) {
                DB::table('tbl_trip_items')->where('itemId', $r->itemId)->update(['dayNo' => 1, 'orderNo' => $i++]);
            }
        } elseif ($how === 'fix_first_late') {
            // mục đầu ngày 1 bị đặt giờ cố định 21:00: tạo lỗi giờ / chồng giờ để La bàn sửa
            DB::table('tbl_trip_items')->where('tripId', $tripId)->where('dayNo', 1)->orderBy('orderNo')->limit(1)->update(['startMin' => 1260]);
        }
        DB::table('tbl_trips')->where('tripId', $tripId)->increment('rev');
    }

    /** @return array<int,list<string>> tên mục theo ngày, đúng thứ tự (như trên timeline) */
    private function names(int $tripId): array
    {
        $out = [];
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
        foreach (app(\App\Services\Planner\TripPlanner::class)->payload($trip)['days'] as $d) {
            $out[(int) $d['day']] = array_map(fn($x) => (string) $x['name'], array_values($d['items']));
        }

        return $out;
    }
}
