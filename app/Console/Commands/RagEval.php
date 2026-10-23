<?php

namespace App\Console\Commands;

use App\Services\EventDateService;
use App\Services\Content\SearchCatalog;
use App\Services\Rag\AskService;
use App\Services\Rag\Embedding\EmbedderFactory;
use App\Services\Rag\Llm\LlmFactory;
use App\Services\Rag\RagUnavailableException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * G7: chạy bộ 30 câu hỏi qua ba cấu hình (llm | rag | rag_facts), xuất bảng so sánh Markdown + CSV để rà soát tay.
 * Câu hỏi nằm ở database/rag/eval_questions.json, dùng {dest} {dest2} {food} {event} để điền bằng dữ liệu demo của bạn.
 */
class RagEval extends Command
{
    protected $signature = 'rag:eval
        {--modes=llm,rag,rag_facts : các cấu hình cần chạy}
        {--file=database/rag/eval_questions.json}
        {--dest= : slug điểm đến thay cho {dest}} {--dest2= : slug thay cho {dest2}}
        {--food= : slug món thay cho {food}} {--event= : slug lễ hội thay cho {event}}
        {--limit=0 : chỉ chạy N câu đầu (0 = tất cả)}
        {--embedder=} {--llm= : none | anthropic | openai}';

    protected $description = 'G7: đánh giá hỏi đáp trên bộ câu hỏi, so sánh LLM / RAG / RAG + dữ kiện.';

    public function handle(EmbedderFactory $ef, LlmFactory $lf, EventDateService $dates): int
    {
        $path = base_path($this->option('file'));
        if (!is_file($path)) {
            $this->error("Không thấy $path");

            return self::FAILURE;
        }
        $subs = $this->substitutions();
        if (!$subs) {
            return self::FAILURE;
        }
        $questions = json_decode(File::get($path), true)['questions'] ?? [];
        if ((int) $this->option('limit') > 0) {
            $questions = array_slice($questions, 0, (int) $this->option('limit'));
        }

        $svc = app(AskService::class)->withEmbedder($ef->make($this->option('embedder')))->withLlm($lf->make($this->option('llm')));
        $modes = array_values(array_intersect(AskService::MODES, explode(',', (string) $this->option('modes'))));
        $this->info('Bộ nhúng: ' . $svc->embedder()->name() . ' | LLM: ' . $svc->llm()->name());

        $rows = [];
        $sum = [];
        foreach ($modes as $mode) {
            if ($mode === 'llm' && !$svc->llm()->available()) {
                $this->warn('Bỏ qua chế độ llm: chưa cấu hình LLM.');
                continue;
            }
            $this->line("== $mode");
            foreach ($questions as $i => $q) {
                $text = strtr($q['q'], $subs);
                $t0 = hrtime(true);
                try {
                    $r = $svc->compute($text, $mode);
                } catch (RagUnavailableException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
                $r['ms'] = (int) ((hrtime(true) - $t0) / 1e6);
                $verdict = $this->judge($q, $r, $subs, $dates);
                $rows[] = [$mode, $q['group'], $text, $r, $verdict];
                $this->line(sprintf('  %2d %-9s %s', $i + 1, $verdict['label'], mb_substr($text, 0, 70)));
            }
        }

        $md = $this->report($rows, $svc);
        $dir = storage_path('app/rag');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd-His');
        File::put("$dir/eval-$stamp.md", $md);
        $this->writeCsv("$dir/eval-$stamp.csv", $rows);
        $this->newLine();
        $this->line($md);
        $this->info("Đã lưu: storage/app/rag/eval-$stamp.md và .csv (cột reviewer_ok để bạn rà soát tay).");

        return self::SUCCESS;
    }

    /** @return array<string,string>|null */
    private function substitutions(): ?array
    {
        $pick = fn($opt, $table, $where, $n = 0) => $this->option($opt) ?: DB::table($table)->whereRaw($where)->orderBy($table === 'tbl_destinations' ? 'destinationId' : ($table === 'tbl_foods' ? 'foodId' : 'eventId'))->skip($n)->value('slug');
        $d = $pick('dest', 'tbl_destinations', 'isActive = 1');
        $d2 = $pick('dest2', 'tbl_destinations', 'isActive = 1', 1) ?: $d;
        $f = $pick('food', 'tbl_foods', "reviewStatus = 'published'");
        $e = $pick('event', 'tbl_events', "reviewStatus = 'published'");
        if (!$d || !$f || !$e) {
            $this->error('Cần ít nhất 1 điểm đến, 1 món, 1 lễ hội published (hoặc truyền --dest --food --event).');

            return null;
        }
        $name = fn($table, $key, $slug) => DB::table($table)->where('slug', $slug)->value($key);

        return [
            '{dest}' => $name('tbl_destinations', 'name', $d), '{dest2}' => $name('tbl_destinations', 'name', $d2),
            '{food}' => $name('tbl_foods', 'name', $f), '{event}' => $name('tbl_events', 'name', $e),
            '{destSlug}' => $d, '{eventSlug}' => $e, '{foodSlug}' => $f,
        ];
    }

    /** Chấm tự động theo kỳ vọng của từng câu. label: OK | SAI | THIEU-NGUON | TU-CHOI-DUNG | BIA | TU-CHOI-SAI */
    private function judge(array $q, array $r, array $subs, EventDateService $dates): array
    {
        $e = $q['expect'] ?? [];
        $fold = fn(string $s) => SearchCatalog::fold($s);
        $ans = $fold($r['answer']);

        if (!empty($e['refusal'])) {
            return $r['refused'] || $r['answer'] === '' ? ['label' => 'TU-CHOI-DUNG', 'ok' => true, 'wrong' => false, 'srcOk' => null]
                : ['label' => 'BIA', 'ok' => false, 'wrong' => true, 'srcOk' => null];
        }
        if ($r['refused']) {
            return ['label' => 'TU-CHOI-SAI', 'ok' => false, 'wrong' => false, 'srcOk' => false];
        }

        $srcOk = true;
        if (!empty($e['urlContains'])) {
            $needles = array_map(fn($n) => strtr($n, $subs), $e['urlContains']);
            $srcOk = (bool) array_filter($r['sources'], fn($s) => (bool) array_filter($needles, fn($n) => str_contains($s['url'], $n)));
        }

        $contentOk = true;
        if (!empty($e['anyOf'])) {
            $contentOk = (bool) array_filter($e['anyOf'], fn($k) => str_contains($ans, $fold(strtr($k, $subs))));
        }
        if (!empty($e['eventDate'])) {
            $slug = strtr($e['eventDate']['event'], $subs);
            $year = (int) $e['eventDate']['year'];
            $ev = DB::table('tbl_events')->where('slug', $slug)->first();
            $contentOk = false;
            if ($ev) {
                foreach ($dates->upcoming($ev, new \DateTimeImmutable("$year-01-01"), 8) as $d) {
                    if ((int) substr($d['start'], 0, 4) !== $year) {
                        continue;
                    }
                    [$y, $m, $dd] = [(int) substr($d['start'], 0, 4), (int) substr($d['start'], 5, 2), (int) substr($d['start'], 8, 2)];
                    $a = $r['answer'];
                    $re1 = sprintf('#\b0?%d\b[^\n]{0,12}0?%d/%d#u', $dd, $m, $y);
                    $re2 = sprintf('#\b0?%d\b[^\n]{0,12}tháng 0?%d[^\n]{0,8}%d#iu', $dd, $m, $y);
                    if (preg_match($re1, $a) || preg_match($re2, $a)) {
                        $contentOk = true;
                    }
                }
            }
        }

        if (!$contentOk) {
            return ['label' => 'SAI', 'ok' => false, 'wrong' => true, 'srcOk' => $srcOk];
        }
        if (!$srcOk || ($r['mode'] !== 'llm' && !$r['cited'])) {
            return ['label' => 'THIEU-NGUON', 'ok' => false, 'wrong' => false, 'srcOk' => false];
        }

        return ['label' => 'OK', 'ok' => true, 'wrong' => false, 'srcOk' => $srcOk];
    }

    private function report(array $rows, AskService $svc): string
    {
        $by = [];
        foreach ($rows as [$mode, $group, $text, $r, $v]) {
            $by[$mode][] = [$group, $r, $v];
        }
        $pIn = config('rag.llm.price_in');
        $pOut = config('rag.llm.price_out');
        $md = "# Kết quả đánh giá hỏi đáp (G7)\n\nBộ nhúng `" . $svc->embedder()->name() . '`, LLM `' . $svc->llm()->name() . "`, " . now()->format('d/m/Y H:i') . "\n\n";
        $md .= "| Cấu hình | Số câu | Trích nguồn đúng | Sai so với nguồn (tự động) | Từ chối đúng khi thiếu dữ liệu | Thời gian TB (ms) | p95 (ms) | Token vào/ra TB | Chi phí/câu (USD) |\n|---|---|---|---|---|---|---|---|---|\n";
        foreach ($by as $mode => $list) {
            $n = count($list);
            $ans = array_filter($list, fn($x) => $x[0] !== 'nodata');
            $nod = array_filter($list, fn($x) => $x[0] === 'nodata');
            $srcOk = count(array_filter($ans, fn($x) => $x[2]['label'] === 'OK' || ($x[2]['srcOk'] === true && !$x[2]['wrong'])));
            $wrong = count(array_filter($list, fn($x) => $x[2]['wrong']));
            $refOk = count(array_filter($nod, fn($x) => $x[2]['label'] === 'TU-CHOI-DUNG'));
            $ms = array_map(fn($x) => $x[1]['ms'], $list);
            sort($ms);
            $in = array_sum(array_map(fn($x) => $x[1]['usage']['in'] ?? 0, $list)) / max(1, $n);
            $out = array_sum(array_map(fn($x) => $x[1]['usage']['out'] ?? 0, $list)) / max(1, $n);
            $cost = ($pIn !== null && $pOut !== null) ? sprintf('%.5f', ($in * $pIn + $out * $pOut) / 1e6) : 'n/a';
            $pct = fn($a, $b) => $b ? round(100 * $a / $b) . '%' : 'n/a';
            $md .= sprintf("| %s | %d | %s | %s | %s | %d | %d | %d / %d | %s |\n", $mode, $n, $pct($srcOk, count($ans)), $pct($wrong, $n), $pct($refOk, count($nod)),
                array_sum($ms) / max(1, $n), $ms[(int) floor(0.95 * (count($ms) - 1))] ?? 0, $in, $out, $cost);
        }
        $md .= "\nGhi chú: \"Sai so với nguồn\" chỉ bắt lỗi theo từ khóa/ngày kỳ vọng và việc bịa khi đáng lẽ phải từ chối; hãy rà tay cột `reviewer_ok` trong CSV trước khi đưa số vào báo cáo.\n";

        return $md;
    }

    private function writeCsv(string $file, array $rows): void
    {
        $h = fopen($file, 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['mode', 'group', 'question', 'verdict', 'refused', 'cited', 'engine', 'ms', 'sources', 'answer', 'reviewer_ok']);
        foreach ($rows as [$mode, $group, $text, $r, $v]) {
            fputcsv($h, [$mode, $group, $text, $v['label'], (int) $r['refused'], (int) $r['cited'], $r['engine'], $r['ms'],
                implode(' | ', array_column($r['sources'], 'url')), $r['answer'], '']);
        }
        fclose($h);
    }
}
