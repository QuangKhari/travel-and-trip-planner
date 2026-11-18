<?php

namespace App\Console\Commands;

use App\Services\Ai\AiCost;
use App\Services\Ai\Eval\EvalKit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Thí nghiệm so sánh model và mốc không dùng AI (mục B): cùng bộ câu, mỗi hồ sơ chạy N lần, báo độ chính xác (TB ± độ lệch chuẩn giữa
 * các lần), P95 độ trễ, chi phí mỗi câu theo bảng giá model (AiCost). Trả lời câu hỏi "AI có hơn cách làm không dùng AI không, và tốn bao nhiêu?".
 *
 *   php artisan ai:compare --suite=brief --profiles=rules,gemini-flash-lite,haiku,sonnet,gpt-4o-mini --runs=3
 *   php artisan ai:compare --suite=brief --file=database/ai/eval_brief_peer.json --profiles=rules,haiku
 *   php artisan ai:compare --suite=plan  --profiles=rules,haiku              (rules = engine xếp lịch, không LLM)
 *   php artisan ai:compare --suite=copilot --profiles=haiku,sonnet --runs=3  (không có mốc luật: mốc là người tự sửa, xem buổi thử người dùng)
 *
 * "rules" là mốc: brief = SearchIntent (luật), plan = engine xếp lịch. Mốc tất định nên chỉ chạy 1 lần.
 * Mỗi lần chạy ghi CSV riêng trong --out; bảng tổng ghi vào {out}/compare-{suite}.csv.
 */
class AiCompare extends Command
{
    protected $signature = 'ai:compare {--suite=brief : brief | plan | copilot | explain} {--profiles=rules,gemini-flash-lite : danh sách hồ sơ, cách nhau dấu phẩy}
        {--runs=3} {--file= : bộ câu khác} {--limit=0} {--sleep=4} {--out=storage/app/compare}';
    protected $description = 'So sánh nhiều model (và mốc luật) trên cùng bộ đánh giá: độ chính xác, P95, chi phí mỗi câu';

    public function handle(): int
    {
        $suite = (string) $this->option('suite');
        if (!in_array($suite, ['brief', 'plan', 'copilot', 'explain'], true)) {
            $this->error('--suite phải là brief, plan, copilot hoặc explain.');

            return self::FAILURE;
        }
        $dir = trim((string) $this->option('out'), '/');
        @mkdir(base_path($dir), 0775, true);
        $runs = max(1, min(10, (int) $this->option('runs')));
        $summary = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('profiles')))) as $prof) {
            $isRules = $prof === 'rules';
            if ($isRules && !in_array($suite, ['brief', 'plan'], true)) {
                $this->warn('Bộ ' . $suite . ' không có mốc luật: bỏ "rules".');
                continue;
            }
            $model = $isRules ? 'không dùng LLM' : (string) config('ai.profiles.' . $prof . '.model', '?');
            $per = [];
            for ($r = 1; $r <= ($isRules ? 1 : $runs); $r++) {
                $csv = $dir . '/' . $suite . '-' . $prof . '-r' . $r . '.csv';
                @unlink(base_path($csv));
                $args = ['--suite' => $suite, '--out' => $csv, '--sleep' => (int) $this->option('sleep')];
                if ($this->option('file')) {
                    $args['--file'] = (string) $this->option('file');
                }
                if ((int) $this->option('limit') > 0) {
                    $args['--limit'] = (int) $this->option('limit');
                }
                if ($isRules) {
                    $suite === 'brief' ? $args['--engine'] = 'rules' : $args['--mode'] = 'engine';
                } else {
                    $args['--profile'] = $prof;
                    if ($suite === 'plan') {
                        $args['--mode'] = 'llm_data';
                    }
                }
                $this->line("<fg=cyan>{$prof}</> lần {$r}…");
                $code = Artisan::call('ai:eval', $args);
                if ($code !== 0 || !is_file(base_path($csv))) {
                    $this->warn("  {$prof} lần {$r}: ai:eval không chạy được (" . trim(mb_substr(Artisan::output(), -300)) . ')');
                    continue;
                }
                $per[] = self::score($suite, self::read(base_path($csv)), $isRules ? null : $model);
            }
            if (!$per) {
                continue;
            }
            $acc = array_column($per, 'acc');
            $summary[] = [
                'profile' => $prof, 'model' => $model, 'runs' => count($per),
                'cases' => (int) round(array_sum(array_column($per, 'n')) / count($per)),
                'errors' => array_sum(array_column($per, 'errors')),
                'metric' => $per[0]['metric'],
                'acc_mean' => round(array_sum($acc) / count($acc), 2),
                'acc_sd' => round(self::sd($acc), 2),
                'p95_ms' => (int) round(array_sum(array_column($per, 'p95')) / count($per)),
                'usd_per_case' => ($c = array_filter(array_column($per, 'usdPerCase'), fn($v) => $v !== null)) ? round(array_sum($c) / count($c), 6) : ($isRules ? 0 : null),
                'extra' => $per[0]['extra'],
            ];
        }
        if (!$summary) {
            $this->error('Không hồ sơ nào chạy được.');

            return self::FAILURE;
        }
        $this->info('Bộ ' . $suite . ($this->option('file') ? ' · ' . $this->option('file') : '') . ' · chỉ số chính: ' . $summary[0]['metric'] . ' · giá kiểm ngày ' . config('ai.pricing_checked'));
        $this->table(['Hồ sơ', 'Model', 'Lần', 'Câu', 'Lỗi gọi', 'Chính xác TB ± SD (%)', 'P95 (ms)', 'USD / câu', 'Khác'], array_map(fn($s) => [
            $s['profile'], $s['model'], $s['runs'], $s['cases'], $s['errors'], $s['acc_mean'] . ' ± ' . $s['acc_sd'], $s['p95_ms'],
            $s['usd_per_case'] === null ? 'chưa có giá' : number_format((float) $s['usd_per_case'], 5), $s['extra'],
        ], $summary));
        $out = $dir . '/compare-' . $suite . '.csv';
        EvalKit::csv(base_path($out), $summary);
        $this->info('Đã ghi ' . $out);
        $this->line('Đọc kết quả: chênh lệch nhỏ hơn độ lệch chuẩn giữa các lần chạy thì KHÔNG kết luận model nào hơn. Bộ câu nhỏ: nói rõ trong quyển.');

        return self::SUCCESS;
    }

    /** @return list<array<string,string>> */
    public static function read(string $path): array
    {
        $fh = fopen($path, 'r');
        $head = fgetcsv($fh, escape: '\\');
        if (!$head) {
            return [];
        }
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
        $rows = [];
        while (($r = fgetcsv($fh, escape: '\\')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
        }
        fclose($fh);

        return $rows;
    }

    /**
     * Điểm của một lần chạy.
     * @param list<array<string,string>> $rows
     * @return array{n:int,errors:int,acc:float,metric:string,p95:int,usdPerCase:?float,extra:string}
     */
    public static function score(string $suite, array $rows, ?string $model): array
    {
        $ok = array_values(array_filter($rows, fn($r) => ($r['error'] ?? '') === ''));
        $errors = count($rows) - count($ok);
        $ms = array_map('intval', array_filter(array_column($ok, 'ms'), fn($v) => $v !== ''));
        $p95 = $ms ? EvalKit::pct($ms, 0.95) : 0;
        $tin = array_sum(array_map('intval', array_column($ok, 'in')));
        $tout = array_sum(array_map('intval', array_column($ok, 'tokOut')));
        $usd = $model !== null && $ok ? AiCost::usd($model, $tin, $tout, intdiv($tin, max(1, count($ok)))) : null;
        $rate = function (string $col) use ($ok): ?float {
            $v = array_values(array_filter(array_column($ok, $col), fn($x) => $x !== ''));

            return $v ? 100 * array_sum(array_map('intval', $v)) / count($v) : null;
        };
        [$metric, $acc, $extra] = match ($suite) {
            'brief' => (function () use ($ok) {
                $cells = [];
                foreach ($ok as $r) {
                    foreach (['destination', 'month', 'days', 'adults', 'children', 'pace', 'interests', 'dislikes', 'budget', 'needs', 'injection', 'unsupported'] as $f) {
                        if (($r[$f] ?? '') !== '') {
                            $cells[] = (int) $r[$f];
                        }
                    }
                }
                $inj = array_values(array_filter(array_column($ok, 'injection'), fn($x) => $x !== ''));

                return ['ô đúng / ô chấm', $cells ? 100 * array_sum($cells) / count($cells) : 0.0,
                    $inj ? 'chống chèn lệnh ' . array_sum(array_map('intval', $inj)) . '/' . count($inj) : ''];
            })(),
            'plan' => ['chuyến không lỗi cứng', $ok ? 100 * count(array_filter($ok, fn($r) => (int) ($r['hard'] ?? 1) === 0)) / count($ok) : 0.0,
                'mục có thật ' . ($ok ? round(100 * array_sum(array_map('floatval', array_column($ok, 'exist'))) / count($ok), 1) : 0) . '%'],
            'copilot' => ['đúng loại phản hồi', $rate('kindOk') ?? 0.0,
                'giải đúng vấn đề ' . (($x = $rate('resolvesOk')) === null ? '—' : round($x, 1) . '%') . ' · lỗi cứng mới ' . array_sum(array_map('intval', array_column($ok, 'newHard')))],
            default => ['qua guard trọn vẹn', $rate('passed') ?? 0.0, 'đoạn bị chặn ' . array_sum(array_map('intval', array_column($ok, 'replaced')))],
        };

        return ['n' => count($ok), 'errors' => $errors, 'acc' => round($acc, 2), 'metric' => $metric, 'p95' => $p95,
            'usdPerCase' => $usd === null ? null : $usd / max(1, count($ok)), 'extra' => $extra];
    }

    private static function sd(array $v): float
    {
        $n = count($v);
        if ($n < 2) {
            return 0.0;
        }
        $m = array_sum($v) / $n;

        return sqrt(array_sum(array_map(fn($x) => ($x - $m) ** 2, $v)) / ($n - 1));
    }
}
