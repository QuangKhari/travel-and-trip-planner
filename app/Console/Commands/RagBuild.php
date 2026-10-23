<?php

namespace App\Console\Commands;

use App\Services\Rag\ChunkBuilder;
use App\Services\Rag\ChunkIndexer;
use App\Services\Rag\Chunker;
use App\Services\Rag\Embedding\EmbedderFactory;
use Illuminate\Console\Command;

/** G2: chia đoạn nội dung published và nhúng vào tbl_chunks. Chạy lại an toàn: chỉ nhúng đoạn mới/đổi. */
class RagBuild extends Command
{
    protected $signature = 'rag:build
        {--embedder= : local | openai | voyage (mặc định theo RAG_EMBED_DRIVER)}
        {--only= : giới hạn loại nguồn, cách nhau dấu phẩy: guide,destination,food,event,itinerary}
        {--force : nhúng lại tất cả}
        {--dry : chỉ chia đoạn và thống kê, không gọi API nhúng, không ghi DB}';

    protected $description = 'G2: chia đoạn nội dung đã duyệt và nhúng vào tbl_chunks (RAG).';

    public function handle(EmbedderFactory $factory, ChunkIndexer $indexer): int
    {
        $only = $this->option('only') ? array_values(array_intersect(ChunkBuilder::TYPES, array_map('trim', explode(',', $this->option('only'))))) : null;
        $rows = ChunkBuilder::make()->build($only ?: null);

        $by = [];
        foreach ($rows as $r) {
            $by[$r['refType']]['n'] = ($by[$r['refType']]['n'] ?? 0) + 1;
            $by[$r['refType']]['w'] = ($by[$r['refType']]['w'] ?? 0) + Chunker::words($r['text']);
        }
        $this->table(['Loại', 'Số đoạn', 'Từ TB/đoạn'], array_map(fn($t, $v) => [$t, $v['n'], (int) round($v['w'] / $v['n'])], array_keys($by), $by));
        $this->line('Tổng: ' . count($rows) . ' đoạn từ nội dung published.');
        if (!$rows) {
            $this->warn('Chưa có nội dung published nào để lập chỉ mục. Duyệt nội dung ở màn hình admin trước.');
        }
        if ($this->option('dry')) {
            return self::SUCCESS;
        }

        try {
            $embedder = $factory->make($this->option('embedder'));
            $this->info('Bộ nhúng: ' . $embedder->name());
            $stat = $indexer->sync($rows, $embedder, (bool) $this->option('force'), fn($m) => $this->line('  ' . $m));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Mới', 'Đổi/nhúng lại', 'Không đổi', 'Đã xóa (hết published)', 'Số lần gọi nhúng (đoạn)'],
            [[$stat['new'], $stat['updated'], $stat['unchanged'], $stat['deleted'], $stat['embedded']]]);

        return self::SUCCESS;
    }
}
