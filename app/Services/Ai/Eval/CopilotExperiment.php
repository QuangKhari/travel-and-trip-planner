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
            [$names, $withHours] = $this->names($tripId);
            $fill = fn(string $s, string $miss) => preg_replace_callback('/\{h(\d+)\}/', fn($m) => $withHours[(int) $m[1] - 1] ?? $miss,
                preg_replace_callback('/\{d(\d+)i(\d+)\}/', fn($m) => $names[(int) $m[1]][(int) $m[2] - 1] ?? $miss, $s));
            $text = $fill($case['request'], '???');
            if (str_contains($text, '???')) {
                return $row + ['error' => preg_match('/\{h\d+\}/', $case['request'])
                    ? 'chuyến sinh ra không đủ mục có dữ liệu giờ mở cửa cho chỗ giữ {hN}'
                    : 'chuyến sinh ra thiếu mục cho chỗ giữ trong câu'];
            }
            $touch = isset($case['expect']['touch']) ? $fill($case['expect']['touch'], '') : null;

            $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
            Cache::forget('ai:cb:until');
            Cache::forget('ai:cb:fails');
            $t0 = microtime(true);
            $r = $this->copilot->chat($trip, null, $text, [], [], ['verify' => $mode === 'verified']);
            $ms = EvalKit::ms($t0);
            $run = DB::table('tbl_ai_runs')->where('tripId', $tripId)->orderByDesc('runId')->first();

            if (($run->outcome ?? '') === 'error') {
                // lỗi phía nhà cung cấp (hết hạn mức 429, 400, 503...): không phải hành vi của La bàn -> không tính vào bảng
                return $row + ['error' => 'LLM: ' . ($run->error ?: 'lỗi gọi API')];
            }
            $flags = $run ? (array) json_decode((string) $run->guardFlags, true) : [];
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
                'newWarn' => $kind === 'proposal' ? (int) ($r['receipt']['newWarn'] ?? 0) : 0,
                'trap' => !empty($case['trap']) || preg_match('/\{h\d+\}/', $case['request']) ? 1 : 0,
                'resolvesOk' => isset($exp['resolves']) ? (in_array($exp['resolves'], $resolved, true) ? 1 : 0) : '',
                'touchOk' => $touch ? (str_contains($human, $touch) ? 1 : 0) : '',
                'scoreBefore' => $r['receipt']['before']['score'] ?? '',
                'scoreAfter' => $r['receipt']['after']['score'] ?? '',
                'steps' => (int) ($r['steps'] ?? 0),
                'repairs' => (int) ($flags['repairs'] ?? 0),
                'searches' => (int) ($flags['searches'] ?? 0),
                'hits' => (int) ($flags['hits'] ?? 0),
                'ms' => $ms,
                'in' => $run->inputTokens ?? 0, 'tokOut' => $run->outputTokens ?? 0,
                'outcome' => $run->outcome ?? '',
                'model' => (string) ($run->model ?? ''),
                'human' => $human,
                // lời La bàn (khi trả lời thay vì đề xuất): để biết vì sao không đề xuất (tìm không ra, từ chối, hỏi lại...)
                'say' => trim(implode('', array_map(fn($x) => $x['t'] ?? ($x['name'] ?? ''), (array) ($r['say'] ?? [])))),
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
            // một mục ngày 1 bị đặt giờ cố định 21:00 để có lỗi giờ thật cho La bàn sửa. Ưu tiên mục CÓ giờ mở cửa hôm đó và đóng cửa sớm nhất
            // (21:00 chắc chắn quá giờ -> hours.late, lỗi cứng); không có thì lấy mục đầu ngày 1 như trước (chỉ tạo được chồng giờ / muộn).
            $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
            $day1 = collect(app(\App\Services\Planner\TripPlanner::class)->payload($trip)['days'])->firstWhere('day', 1);
            $pick = null;
            $close = PHP_INT_MAX;
            foreach ($day1['items'] ?? [] as $x) {
                $r = ($day1['dow'] ?? null) !== null ? ($x['hours'][(string) $day1['dow']] ?? []) : [];
                $c = $r ? max(array_column($r, 1)) : null;
                if ($c !== null && $c < $close) {
                    [$pick, $close] = [$x, $c];
                }
            }
            $row = DB::table('tbl_trip_items')->where('tripId', $tripId)->where('dayNo', 1)
                ->when($pick, fn($q) => $q->where('refType', $pick['type'])->where('refId', (int) $pick['refId']))
                ->orderBy('orderNo')->value('itemId');
            if ($row) {
                DB::table('tbl_trip_items')->where('itemId', $row)->update(['startMin' => 1260]);
            }
        }
        DB::table('tbl_trips')->where('tripId', $tripId)->increment('rev');
    }

    /**
     * @return array{0:array<int,list<string>>,1:list<string>} tên mục theo ngày (đúng thứ tự timeline)
     *         và tên các mục CÓ giờ mở cửa cho đúng thứ của ngày đó (chỗ giữ {h1}, {h2}: bẫy dời ra ngoài giờ chắc chắn bị TripHealth bắt)
     */
    private function names(int $tripId): array
    {
        $out = [];
        $withHours = [];
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->first();
        foreach (app(\App\Services\Planner\TripPlanner::class)->payload($trip)['days'] as $d) {
            $out[(int) $d['day']] = array_map(fn($x) => (string) $x['name'], array_values($d['items']));
            $dow = $d['dow'] ?? null;
            foreach ($d['items'] as $x) {
                if ($dow !== null && !empty($x['hours'][(string) $dow])) {
                    $withHours[] = (string) $x['name'];
                }
            }
        }

        return [$out, array_values(array_unique($withHours))];
    }
}
