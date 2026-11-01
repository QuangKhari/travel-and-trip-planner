<?php

namespace App\Console\Commands;

use App\Services\Ai\AiGateway;
use App\Services\Ai\Brief\BriefPrompt;
use App\Services\Ai\Brief\BriefService;
use App\Services\Ai\Brief\BriefValidator;
use App\Services\Ai\Eval\CopilotExperiment;
use App\Services\Ai\Eval\EvalKit;
use App\Services\Ai\Eval\ExplainExperiment;
use App\Services\Ai\Eval\PlanExperiment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Bộ đánh giá La bàn (tài liệu v2.2 mục 8). Mọi bộ chạy trong giao dịch rồi hoàn tác: không để lại chuyến, log hay cache.
 *   php artisan ai:eval --suite=brief --engine=rules            (mốc: SearchIntent, miễn phí)
 *   php artisan ai:eval --suite=brief --engine=llm --out=storage/app/ai-brief.csv [--file=database/ai/eval_brief_peer.json]
 *   php artisan ai:eval --suite=plan    --mode=engine|llm_data|llm   (thí nghiệm A: A2 / A1b / A1)
 *   php artisan ai:eval --suite=copilot --mode=verified|unchecked    (thí nghiệm B: B2 / B1)
 *   php artisan ai:eval --suite=explain                              (F2: tỷ lệ qua guard + lời kể để người chấm)
 * Chấm từng trường: đúng giá trị; trường "suy đoán" phải đúng giá trị VÀ được đánh dấu AI đoán;
 * trường thiếu phải để trống và được hỏi lại (không đoán bừa). Nhóm injection: không lộ chuỗi cấm.
 * Chỉ so sánh các lần chạy cùng promptVersion, cùng model, cùng bộ câu.
 */
class AiEval extends Command
{
    protected $signature = 'ai:eval {--suite=brief : brief | plan | copilot | explain} {--mode= : plan: engine|llm_data|llm · copilot: verified|unchecked} {--file= : bộ mẫu khác (mặc định database/ai/eval_<suite>.json)} {--engine=llm : llm | rules} {--limit=0} {--out=} {--today=2026-11-01} {--model= : đổi model cho lần chạy này, vd gemini-3.8-flash} {--sleep=4 : giây nghỉ giữa hai câu (tránh vượt hạn mức phút của gói miễn phí)} {--retry=2 : số lần thử lại khi lỗi} {--timeout= : giây chờ mỗi lệnh gọi (mặc định theo config ai.timeout.brief)} {--effort= : reasoning_effort low|medium|high}';
    protected $description = 'Chạy bộ đánh giá La bàn (F1 brief) và in độ chính xác từng trường';

    private const FIELDS = ['destination', 'month', 'days', 'adults', 'children', 'pace', 'interests', 'dislikes', 'budget'];

    public function handle(BriefService $brief, BriefValidator $validator, AiGateway $gateway): int
    {
        $suite = (string) $this->option('suite');
        if (in_array($suite, ['plan', 'copilot', 'explain'], true)) {
            return $this->suite($suite);
        }
        if ($suite !== 'brief') {
            $this->error('--suite phải là brief, plan, copilot hoặc explain.');

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($this->file('brief')), true);
        $cases = $data['cases'] ?? [];
        if ((int) $this->option('limit') > 0) {
            $cases = array_slice($cases, 0, (int) $this->option('limit'));
        }
        $engine = (string) $this->option('engine');
        if ($m = $this->option('model')) {
            // chạy thử nhiều model mà không phải sửa .env (PowerShell không có cú pháp VAR=x lệnh)
            config(['ai.models.' . config('ai.driver') . '.brief' => (string) $m]);
        }
        if ($t = (int) $this->option('timeout')) {
            config(['ai.timeout.brief' => $t]);
        }
        if ($e = $this->option('effort')) {
            config(['ai.reasoning_effort' => (string) $e]);
        }
        $model = $engine === 'llm' ? (string) config('ai.models.' . config('ai.driver') . '.brief') : '-';
        $today = (string) $this->option('today');
        $ctx = $brief->context($today);
        Cache::forget('ai:cb:until');   // ngắt mạch còn lại từ lần chạy trước không được chặn bộ đánh giá
        if ($engine === 'llm' && $gateway->blocked('brief', null)) {
            $this->error('Chưa gọi được LLM: ' . $gateway->blocked('brief', null) . ' (bật AI_PLANNER_ENABLED và cấu hình AI_DRIVER + khóa).');

            return self::FAILURE;
        }

        $rows = [];
        $failed = [];
        $score = [];
        $lat = [];
        $tokIn = $tokOut = 0;
        foreach ($cases as $c) {
            $text = BriefService::clean($c['q']);
            $t0 = microtime(true);
            $res = ['ok' => true, 'ms' => 0];   // chỉ dùng ở nhánh llm; khai báo trước cho rõ ràng
            if ($engine === 'rules') {
                $out = $validator->validate($brief->fromRules($text), $text, $ctx);
            } else {
                // Đánh giá đo CHẤT LƯỢNG model: tắt ngắt mạch, thử lại khi lỗi; câu vẫn lỗi thì loại khỏi phần chấm và báo riêng
                for ($try = 0; ; $try++) {
                    Cache::forget('ai:cb:until');
                    Cache::forget('ai:cb:fails');
                    $t0 = microtime(true);
                    $res = $gateway->json('brief', null, BriefPrompt::system(array_map(fn($d) => ['name' => $d['name']], $ctx['dests']), $today),
                        BriefPrompt::user($text), BriefPrompt::schema($ctx['maxDays']));
                    if ($res['ok'] || $try >= (int) $this->option('retry')) {
                        break;
                    }
                    $wait = 5 * ($try + 1) + (str_contains((string) ($res['error'] ?? ''), '429') ? 10 : 0);
                    $this->warn("  {$c['id']}: {$res['reason']} " . mb_substr((string) ($res['error'] ?? ''), 0, 90) . " -> thử lại sau {$wait}s");
                    sleep($wait);
                }
                $tokIn += (int) ($res['in'] ?? 0);
                $tokOut += (int) ($res['out'] ?? 0);
                if (!$res['ok']) {
                    $failed[] = $c['id'] . ': ' . $res['reason'] . ' ' . mb_substr((string) ($res['error'] ?? ''), 0, 120);
                    $rows[] = ['id' => $c['id'], 'group' => $c['group'], 'source' => $c['source'] ?? 'author', 'ms' => $res['ms'], 'error' => $res['reason']];
                    continue;
                }
                $out = BriefValidator::merge($validator->validate($res['data'], $text, $ctx), $validator->validate($brief->fromRules($text), $text, $ctx));
            }
            $lat[] = $engine === 'rules' ? (int) round((microtime(true) - $t0) * 1000) : (int) $res['ms'];
            $got = $this->flatten($out);
            $exp = $c['expect'] ?? [];
            $line = ['id' => $c['id'], 'group' => $c['group'], 'source' => $c['source'] ?? 'author', 'ms' => end($lat)];
            foreach (self::FIELDS as $f) {
                if (!array_key_exists($f, $exp)) {
                    $line[$f] = '';
                    continue;
                }
                $ok = $this->same($exp[$f], $got[$f] ?? null);
                if ($ok && in_array($f, (array) ($exp['inferred'] ?? []), true)) {
                    $ok = in_array($this->group($f), $got['guessed'], true);   // suy đoán: đúng giá trị VÀ đã khai báo
                }
                $line[$f] = $ok ? 1 : 0;
                $score[$f][] = $ok ? 1 : 0;
            }
            if (isset($exp['needs'])) {
                $ok = !array_diff($exp['needs'], $got['needs']);
                $line['needs'] = $ok ? 1 : 0;
                $score['needs'][] = $ok ? 1 : 0;
            }
            if (isset($exp['mustNotContain'])) {
                // chỉ xét phần ĐI VÀO FORM (prefill + giá trị các ô vé): spans / warnings / flags chép lại câu người dùng nên không tính là rò rỉ
                $blob = mb_strtolower(json_encode([$out['prefill'], array_column($out['fields'], 'value')], JSON_UNESCAPED_UNICODE));
                $ok = !array_filter($exp['mustNotContain'], fn($s) => str_contains($blob, mb_strtolower($s)));
                $line['injection'] = $ok ? 1 : 0;
                $score['injection'][] = $ok ? 1 : 0;
            }
            if (isset($exp['unsupported'])) {
                $ok = (bool) array_filter($out['warnings'], fn($w) => $w['type'] === 'unsupported') === (bool) $exp['unsupported'];
                $line['unsupported'] = $ok ? 1 : 0;
                $score['unsupported'][] = $ok ? 1 : 0;
            }
            $rows[] = $line;
            if ($engine === 'llm' && (int) $this->option('sleep') > 0) {
                sleep((int) $this->option('sleep'));
            }
        }

        $table = [];
        foreach ($score as $f => $v) {
            $table[] = [$f, count($v), array_sum($v), round(100 * array_sum($v) / max(1, count($v)), 1) . '%'];
        }
        $this->info('Bộ brief · engine=' . $engine . ' · model=' . $model . (config('ai.reasoning_effort') ? ' · effort=' . config('ai.reasoning_effort') : '') . ' · promptVersion=' . config('ai.prompt_version.brief') . ' · ' . count($cases) . ' câu');
        $this->table(['Trường', 'Số câu chấm', 'Đúng', 'Tỷ lệ'], $table);
        $miss = [];
        foreach ($rows as $r) {
            foreach ($r as $k => $v) {
                if ($v === 0) {
                    $miss[$k][] = $r['id'];
                }
            }
        }
        foreach ($miss as $k => $ids) {
            $this->line('  sai ' . $k . ': ' . implode(', ', $ids));
        }
        // tách theo nguồn câu (author / peer): báo cáo riêng để thấy thiên lệch "người viết đề hợp gu lời nhắc" (mục 8.1)
        $bySrc = [];
        foreach ($rows as $r) {
            foreach (self::FIELDS as $f) {
                if (($r[$f] ?? '') !== '') {
                    $bySrc[$r['source']][] = (int) $r[$f];
                }
            }
        }
        if (count($bySrc) > 1) {
            $this->table(['Nguồn câu', 'Số ô chấm', 'Đúng', 'Tỷ lệ'], array_map(fn($src, $v) => [$src, count($v), array_sum($v), round(100 * array_sum($v) / max(1, count($v)), 1) . '%'], array_keys($bySrc), $bySrc));
        }
        if ($failed) {
            $this->warn(count($failed) . ' câu gọi LLM lỗi, KHÔNG tính vào bảng trên (chạy lại hoặc tăng --sleep):');
            foreach ($failed as $f) {
                $this->line('  - ' . $f);
            }
        }
        sort($lat);
        $p = fn(float $q) => $lat ? $lat[min(count($lat) - 1, (int) floor($q * count($lat)))] : 0;
        $this->line('Độ trễ p50 ' . $p(0.5) . ' ms · p95 ' . $p(0.95) . ' ms' . ($engine === 'llm' ? ' · token vào ' . $tokIn . ' / ra ' . $tokOut : ''));
        if ($engine === 'llm' && config('ai.price_in') !== null) {
            $usd = $tokIn / 1e6 * (float) config('ai.price_in') + $tokOut / 1e6 * (float) config('ai.price_out');
            $this->line('Chi phí ước tính: $' . number_format($usd, 4) . ' (≈ $' . number_format($usd / max(1, count($cases)), 5) . '/câu)');
        }
        if ($out = $this->option('out')) {
            $fh = fopen(base_path($out), 'w');
            $cols = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
            fputcsv($fh, $cols);
            foreach ($rows as $r) {
                fputcsv($fh, array_map(fn($c) => $r[$c] ?? '', $cols));
            }
            fclose($fh);
            $this->info('Đã ghi ' . $out);
        }

        return self::SUCCESS;
    }

    private function flatten(array $o): array
    {
        $p = $o['prefill'];

        return [
            'destination' => $p['destinationSlug'] ?? null,
            'month' => $p['month'] ?? null,
            'days' => $p['days'] ?? null,
            'adults' => $p['adults'] ?? null,
            'children' => $p['children'] ?? null,
            'pace' => $p['pace'] ?? null,
            'interests' => $p['interests'] ?? [],
            'dislikes' => $p['dislikes'] ?? [],
            'budget' => $p['budget'] ?? null,
            'needs' => array_column($o['needs'], 'field'),
            'guessed' => array_keys(array_filter($o['fields'], fn($f) => $f['state'] === 'guess')),
        ];
    }

    private function group(string $f): string
    {
        return in_array($f, ['adults', 'children'], true) ? 'people' : $f;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            $a = (array) $a;
            $b = (array) $b;
            sort($a);
            sort($b);

            return $a === $b;
        }

        return $a == $b;
    }

    // ================================================================== plan / copilot / explain

    private function file(string $suite): string
    {
        $f = (string) $this->option('file');

        return $f !== '' ? base_path($f) : database_path('ai/eval_' . $suite . '.json');
    }

    private function suite(string $suite): int
    {
        // đánh giá đo CHẤT LƯỢNG: bật đúng tính năng đang đo, bỏ hạn mức (userId null) và ngắt mạch (EvalKit / từng mẫu)
        config(['ai.enabled' => true, 'ai.features.' . ($suite === 'plan' ? 'copilot' : $suite) => true]);
        if ($m = $this->option('model')) {
            config(['ai.models.' . config('ai.driver') . '.' . ($suite === 'explain' ? 'explain' : 'copilot') => (string) $m]);
        }
        if ($e = $this->option('effort')) {
            config(['ai.reasoning_effort' => (string) $e]);
        }
        $path = $this->file($suite);
        if (!is_file($path)) {
            $this->error('Không có bộ mẫu ' . $path);

            return self::FAILURE;
        }
        $cases = json_decode((string) file_get_contents($path), true)['cases'] ?? [];
        if ((int) $this->option('limit') > 0) {
            $cases = array_slice($cases, 0, (int) $this->option('limit'));
        }
        $modes = ['plan' => PlanExperiment::MODES, 'copilot' => CopilotExperiment::MODES, 'explain' => ['llm']][$suite];
        $mode = (string) ($this->option('mode') ?: $modes[0]);
        if (!in_array($mode, $modes, true)) {
            $this->error('--mode của ' . $suite . ' phải là ' . implode(' | ', $modes));

            return self::FAILURE;
        }
        $runner = app(['plan' => PlanExperiment::class, 'copilot' => CopilotExperiment::class, 'explain' => ExplainExperiment::class][$suite]);
        $usesLlm = !($suite === 'plan' && $mode === 'engine');
        $rows = [];
        foreach ($cases as $i => $c) {
            $r = $suite === 'explain' ? $runner->run($c) : $runner->run($c, $mode);
            $rows[] = $r;
            $this->line(sprintf('  %-6s %s', $r['id'], isset($r['error']) ? '<fg=red>lỗi: ' . $r['error'] . '</>' : $this->brief($suite, $r)));
            if ($usesLlm && $i < count($cases) - 1 && (int) $this->option('sleep') > 0 && !app()->runningUnitTests()) {
                sleep((int) $this->option('sleep'));
            }
        }
        $ok = array_values(array_filter($rows, fn($r) => !isset($r['error'])));
        $this->info(sprintf('Bộ %s · mode=%s · %s · %d mẫu (%d lỗi, không tính)', $suite, $mode,
            $usesLlm ? 'model=' . config('ai.models.' . config('ai.driver') . '.' . ($suite === 'explain' ? 'explain' : 'copilot')) : 'không dùng LLM',
            count($rows), count($rows) - count($ok)));
        $this->table(['Chỉ số', 'Giá trị'], $this->summary($suite, $ok));
        if ($out = $this->option('out')) {
            EvalKit::csv(base_path($out), $rows);
            $this->info('Đã ghi ' . $out);
        }

        return self::SUCCESS;
    }

    private function brief(string $suite, array $r): string
    {
        return match ($suite) {
            'plan' => sprintf('mục %d · có thật %.0f%% · lỗi cứng %d · cảnh báo %d · điểm %d', $r['items'], $r['exist'] * 100, $r['hard'], $r['warn'], $r['score']),
            'copilot' => sprintf('%s%s · lỗi cứng mới %d · %d bước · %s', $r['kind'], $r['ops'] ? ' (' . $r['ops'] . ')' : '', $r['newHard'], $r['steps'], $r['human'] ?: '-'),
            default => sprintf('%s · thay %d đoạn · %d ms', $r['engine'], $r['replaced'], $r['ms']),
        };
    }

    /** @return list<array{0:string,1:string}> */
    private function summary(string $suite, array $ok): array
    {
        $n = max(1, count($ok));
        $avg = fn(string $k) => round(array_sum(array_column($ok, $k)) / $n, 2);
        $rate = function (string $k) use ($ok) {
            $v = array_filter(array_column($ok, $k), fn($x) => $x !== '');

            return $v ? round(100 * array_sum($v) / count($v), 1) . '% (' . array_sum($v) . '/' . count($v) . ')' : '—';
        };
        $ms = array_values(array_filter(array_column($ok, 'ms')));
        $lat = ['Độ trễ p50 / p95 (ms)', EvalKit::pct($ms, 0.5) . ' / ' . EvalKit::pct($ms, 0.95)];
        $tok = ['Token vào / ra (tổng)', array_sum(array_column($ok, 'in')) . ' / ' . array_sum(array_column($ok, 'tokOut'))];

        return match ($suite) {
            'plan' => [
                ['Tỷ lệ mục có thật trong CSDL', round(100 * $avg('exist'), 1) . '%'],
                ['Mục lặp (tổng)', (string) array_sum(array_column($ok, 'dup'))],
                ['Lỗi cứng TripHealth: tổng / TB mỗi chuyến', array_sum(array_column($ok, 'hard')) . ' / ' . $avg('hard')],
                ['Chuyến không có lỗi cứng', round(100 * count(array_filter($ok, fn($r) => $r['hard'] === 0)) / $n, 1) . '%'],
                ['Cảnh báo TB mỗi chuyến', (string) $avg('warn')],
                ['Điểm sức khỏe TB', (string) $avg('score')],
                ['Độ dày ngày TB / ngày dày nhất TB (%)', $avg('avgLoad') . ' / ' . $avg('maxLoad')],
                ['Phủ sở thích TB', ($v = array_filter(array_column($ok, 'interestCover'), fn($x) => $x !== null)) ? round(100 * array_sum($v) / count($v), 1) . '%' : '—'],
                $lat, $tok,
            ],
            'copilot' => [
                ['Đúng loại phản hồi (đề xuất / trả lời)', $rate('kindOk')],
                ['Có đề xuất', round(100 * count(array_filter($ok, fn($r) => $r['kind'] === 'proposal')) / $n, 1) . '%'],
                ['LỖI CỨNG MỚI tới người dùng (tổng)', (string) array_sum(array_column($ok, 'newHard'))],
                ['Đề xuất có lỗi cứng mới', round(100 * count(array_filter($ok, fn($r) => $r['newHard'] > 0)) / $n, 1) . '%'],
                ['Giải quyết đúng vấn đề mong đợi', $rate('resolvesOk')],
                ['Đúng thao tác mong đợi', $rate('opsOk')],
                ['Đụng đúng mục được nêu', $rate('touchOk')],
                ['Số bước LLM TB / số lần sửa TB', $avg('steps') . ' / ' . $avg('repairs')],
                $lat, $tok,
            ],
            default => [
                ['Có gọi được AI', round(100 * count(array_filter($ok, fn($r) => $r['engine'] === 'llm')) / $n, 1) . '%'],
                ['Qua guard trọn vẹn (0 đoạn bị thay)', $rate('passed')],
                ['Số đoạn phải thay (tổng)', (string) array_sum(array_column($ok, 'replaced'))],
                ['Số bịa lọt tới người dùng', '0 (guard thay mọi đoạn có số / tên không có trong dữ kiện)'],
                $lat, $tok,
            ],
        };
    }
}
