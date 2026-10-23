<?php

namespace App\Console\Commands;

use App\Services\Rag\ChunkBuilder;
use App\Services\Rag\Embedding\EmbedderFactory;
use App\Services\Rag\VectorMath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * G1: so sánh các mô hình nhúng trên câu hỏi thật. Không ghi DB: nhúng tạm toàn bộ đoạn published trong bộ nhớ,
 * rồi đo xem đoạn đúng (theo expect.urlContains trong eval_questions.json) nằm ở hạng mấy.
 */
class RagBench extends Command
{
    protected $signature = 'rag:bench
        {--drivers=local : các bộ nhúng cần thử, vd local,openai,voyage}
        {--file=database/rag/eval_questions.json}
        {--dest=} {--dest2=} {--food=} {--event=}';

    protected $description = 'G1: so sánh mô hình nhúng (hit@1, hit@3, hit@5, MRR) trên bộ câu hỏi thật.';

    public function handle(EmbedderFactory $ef): int
    {
        $chunks = ChunkBuilder::make()->build();
        if (!$chunks) {
            $this->error('Chưa có nội dung published.');

            return self::FAILURE;
        }
        $subs = $this->subs();
        $qs = [];
        foreach ((json_decode(File::get(base_path($this->option('file'))), true)['questions'] ?? []) as $q) {
            if (!empty($q['expect']['urlContains'])) {
                $qs[] = ['q' => strtr($q['q'], $subs), 'need' => array_map(fn($n) => strtr($n, $subs), $q['expect']['urlContains'])];
            }
        }
        $this->info(count($chunks) . ' đoạn, ' . count($qs) . ' câu hỏi có đáp án đoạn kỳ vọng.');
        if (!$qs) {
            return self::FAILURE;
        }

        $rows = [];
        foreach (array_filter(array_map('trim', explode(',', $this->option('drivers')))) as $drv) {
            try {
                $emb = $ef->make($drv);
                $t0 = hrtime(true);
                $docVecs = $emb->embed(array_map(fn($c) => $c['title'] . "\n" . $c['text'], $chunks), 'document');
                $qVecs = $emb->embed(array_column($qs, 'q'), 'query');
                $sec = (hrtime(true) - $t0) / 1e9;
            } catch (\Throwable $e) {
                $this->warn("$drv: bỏ qua ({$e->getMessage()})");
                continue;
            }
            $h1 = $h3 = $h5 = 0;
            $mrr = 0.0;
            foreach ($qs as $i => $q) {
                $sc = [];
                foreach ($docVecs as $j => $dv) {
                    $sc[$j] = VectorMath::dot($qVecs[$i], $dv);
                }
                arsort($sc);
                $rank = 0;
                foreach (array_keys($sc) as $pos => $j) {
                    if (array_filter($q['need'], fn($n) => str_contains($chunks[$j]['url'], $n))) {
                        $rank = $pos + 1;
                        break;
                    }
                }
                $h1 += $rank === 1;
                $h3 += $rank >= 1 && $rank <= 3;
                $h5 += $rank >= 1 && $rank <= 5;
                $mrr += $rank ? 1 / $rank : 0;
            }
            $n = count($qs);
            $rows[] = [$emb->name(), count($docVecs[0]), round(100 * $h1 / $n) . '%', round(100 * $h3 / $n) . '%', round(100 * $h5 / $n) . '%', round($mrr / $n, 3), round($sec, 1) . ' s'];
        }
        $this->table(['Mô hình', 'Chiều', 'hit@1', 'hit@3', 'hit@5', 'MRR', 'Thời gian'], $rows);
        $dir = storage_path('app/rag');
        File::ensureDirectoryExists($dir);
        $f = $dir . '/bench-' . now()->format('Ymd-His') . '.md';
        File::put($f, "| Mô hình | Chiều | hit@1 | hit@3 | hit@5 | MRR | Thời gian |\n|---|---|---|---|---|---|---|\n" . implode("\n", array_map(fn($r) => '| ' . implode(' | ', $r) . ' |', $rows)) . "\n");
        $this->info('Đã lưu ' . $f);

        return self::SUCCESS;
    }

    private function subs(): array
    {
        $d = $this->option('dest') ?: DB::table('tbl_destinations')->where('isActive', 1)->orderBy('destinationId')->value('slug');
        $d2 = $this->option('dest2') ?: DB::table('tbl_destinations')->where('isActive', 1)->orderBy('destinationId')->skip(1)->value('slug') ?: $d;
        $f = $this->option('food') ?: DB::table('tbl_foods')->where('reviewStatus', 'published')->orderBy('foodId')->value('slug');
        $e = $this->option('event') ?: DB::table('tbl_events')->where('reviewStatus', 'published')->orderBy('eventId')->value('slug');
        $n = fn($t, $s) => DB::table($t)->where('slug', $s)->value('name');

        return ['{dest}' => $n('tbl_destinations', $d), '{dest2}' => $n('tbl_destinations', $d2), '{food}' => $n('tbl_foods', $f), '{event}' => $n('tbl_events', $e),
            '{destSlug}' => $d, '{eventSlug}' => $e, '{foodSlug}' => $f];
    }
}
