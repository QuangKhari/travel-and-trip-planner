<?php

namespace App\Services\Rag;

use App\Services\Rag\Embedding\Embedder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * G2: đồng bộ tbl_chunks với nội dung published.
 *  - đoạn mới hoặc đổi nội dung (hash đổi) hoặc khác mô hình nhúng -> nhúng lại;
 *  - đoạn không đổi -> chỉ cập nhật metadata, KHÔNG gọi API nhúng;
 *  - đoạn không còn nguồn (bị gỡ duyệt, bị xóa, bài ngắn đi) -> xóa.
 */
class ChunkIndexer
{
    public const VERSION_KEY = 'rag.version';

    /**
     * @param list<array<string,mixed>> $rows kết quả ChunkBuilder::build()
     * @param callable(string):void|null $log
     * @return array{new:int,updated:int,unchanged:int,reembedded:int,deleted:int,embedded:int}
     */
    public function sync(array $rows, Embedder $embedder, bool $force = false, ?callable $log = null): array
    {
        $stat = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'reembedded' => 0, 'deleted' => 0, 'embedded' => 0];
        $key = fn($t, $i, $s) => "$t:$i:$s";

        $existing = [];
        foreach (DB::table('tbl_chunks')->get(['chunkId', 'refType', 'refId', 'seq', 'hash', 'model', DB::raw('(embedding IS NULL) as noEmb')]) as $e) {
            $existing[$key($e->refType, $e->refId, $e->seq)] = $e;
        }

        $toEmbed = [];     // key => row
        $seen = [];
        foreach ($rows as $r) {
            $k = $key($r['refType'], $r['refId'], $r['seq']);
            $seen[$k] = true;
            $e = $existing[$k] ?? null;
            if (!$e) {
                $toEmbed[$k] = $r;
                $stat['new']++;
            } elseif ($force || $e->hash !== $r['hash'] || $e->model !== $embedder->name() || (int) $e->noEmb === 1) {
                $toEmbed[$k] = $r;
                $stat['updated']++;
            } else {
                $stat['unchanged']++;
                DB::table('tbl_chunks')->where('chunkId', $e->chunkId)->update($this->meta($r) + ['updatedAt' => now()]);
            }
        }

        // Xóa trước khi nhúng: nếu dịch vụ nhúng lỗi giữa chừng thì nội dung đã gỡ duyệt cũng không còn trong chỉ mục
        $orphans = [];
        foreach ($existing as $k => $e) {
            if (!isset($seen[$k])) {
                $orphans[] = $e->chunkId;
            }
        }
        foreach (array_chunk($orphans, 500) as $ids) {
            $stat['deleted'] += DB::table('tbl_chunks')->whereIn('chunkId', $ids)->delete();
        }

        $batch = max(1, (int) config('rag.embed.batch', 32));
        foreach (array_chunk($toEmbed, $batch, true) as $part) {
            $texts = array_map(fn($r) => $r['title'] . "\n" . $r['text'], array_values($part));
            $vecs = $embedder->embed($texts, 'document');
            $i = 0;
            foreach ($part as $k => $r) {
                $v = $vecs[$i++];
                $data = $this->meta($r) + [
                    'embedding' => VectorMath::pack($v),
                    'dims' => count($v),
                    'model' => $embedder->name(),
                    'hash' => $r['hash'],
                    'updatedAt' => now(),
                ];
                if (isset($existing[$k])) {
                    DB::table('tbl_chunks')->where('chunkId', $existing[$k]->chunkId)->update($data);
                    $stat['reembedded']++;
                } else {
                    DB::table('tbl_chunks')->insert($data + ['refType' => $r['refType'], 'refId' => $r['refId'], 'seq' => $r['seq']]);
                }
                $stat['embedded']++;
            }
            $log && $log('Đã nhúng ' . $stat['embedded'] . '/' . count($toEmbed) . ' đoạn');
        }

        // Đổi phiên bản chỉ mục: bộ nhớ đệm câu trả lời cũ không còn hiệu lực
        Cache::forever(self::VERSION_KEY, (string) microtime(true));

        return $stat;
    }

    /** @param array<string,mixed> $r */
    private function meta(array $r): array
    {
        return [
            'destinationId' => $r['destinationId'], 'month' => $r['month'], 'title' => $r['title'], 'text' => $r['text'],
            'ftext' => $r['ftext'], 'url' => $r['url'], 'updatedOn' => $r['updatedOn'],
        ];
    }
}
