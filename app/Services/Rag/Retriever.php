<?php

namespace App\Services\Rag;

use App\Services\Rag\Embedding\Embedder;
use Illuminate\Support\Facades\DB;

/**
 * G3: truy xuất lai = độ giống vector (khớp ý) + BM25 trên văn bản đã bỏ dấu (khớp tên riêng, từ hiếm),
 * hợp nhất bằng Reciprocal Rank Fusion, lọc theo điểm đến, tăng điểm theo tháng.
 *
 * Vì sao BM25 tự viết thay cho FULLTEXT của MySQL: cùng lý do SearchCatalog đã nêu (FULLTEXT bỏ từ ngắn,
 * không tìm "bun cha" ra "Bún chả"), và cùng một mã chạy giống nhau trên MySQL lẫn SQLite khi kiểm thử.
 * Quy mô vài nghìn đoạn thì quét tuần tự trong PHP vẫn dưới một giây. Lớn hơn nữa: Laravel Scout hoặc DB vector (mục 9.3).
 */
class Retriever
{
    private const K1 = 1.2;
    private const B = 0.75;

    /** Số đoạn đã nhúng bằng đúng mô hình này (vector khác mô hình không so sánh được). */
    public function indexed(Embedder $embedder): int
    {
        return (int) DB::table('tbl_chunks')->where('model', $embedder->name())->whereNotNull('embedding')->count();
    }

    /**
     * @param list<string> $terms từ khóa nội dung (đã bỏ dấu) từ QueryAnalyzer::terms()
     * @return list<array<string,mixed>> tối đa $k đoạn, điểm cao trước
     */
    public function search(Embedder $embedder, string $question, array $terms, ?int $destinationId = null, ?int $month = null, ?int $k = null): array
    {
        $k ??= (int) config('rag.retrieval.top_k', 6);
        $rrfK = (int) config('rag.retrieval.rrf_k', 60);
        $pool = (int) config('rag.retrieval.vector_pool', 40);
        $boost = (float) config('rag.retrieval.month_boost', 1.15);

        $scan = $this->scan($embedder, $question, $terms, $destinationId);
        if (!$scan['n'] && $destinationId) {
            $scan = $this->scan($embedder, $question, $terms, null);   // lọc điểm đến làm rỗng kết quả: bỏ lọc
        }
        if (!$scan['n']) {
            return [];
        }

        // Hạng theo vector và theo BM25
        $cos = $scan['cos'];
        arsort($cos);
        $vecRank = array_flip(array_slice(array_keys($cos), 0, $pool));

        $bm25 = $this->bm25($scan, $terms);
        arsort($bm25);
        $lexRank = array_flip(array_keys(array_filter($bm25, fn($s) => $s > 0)));

        $fused = [];
        foreach (array_unique(array_merge(array_keys($vecRank), array_keys($lexRank))) as $id) {
            $s = (isset($vecRank[$id]) ? 1.0 / ($rrfK + $vecRank[$id] + 1) : 0.0)
                + (isset($lexRank[$id]) ? 1.0 / ($rrfK + $lexRank[$id] + 1) : 0.0);
            if ($month && ($scan['month'][$id] ?? null) === $month) {
                $s *= $boost;
            }
            $fused[$id] = $s;
        }
        arsort($fused);
        $topIds = array_slice(array_keys($fused), 0, $k * 2);   // lấy dư để còn lọc nguồn đã gỡ duyệt

        $rows = DB::table('tbl_chunks')->whereIn('chunkId', $topIds)
            ->get(['chunkId', 'refType', 'refId', 'destinationId', 'title', 'text', 'url', 'updatedOn'])->keyBy('chunkId');
        $alive = $this->stillPublished($rows->all());

        $nTerms = max(1, count($terms));
        $out = [];
        foreach ($topIds as $id) {
            $r = $rows[$id] ?? null;
            if (!$r || !isset($alive[$r->chunkId])) {
                continue;
            }
            $out[] = [
                'chunkId' => (int) $r->chunkId, 'refType' => $r->refType, 'refId' => (int) $r->refId,
                'destinationId' => $r->destinationId ? (int) $r->destinationId : null,
                'type' => $r->refType, 'title' => $r->title, 'text' => $r->text, 'url' => $r->url, 'updatedOn' => $r->updatedOn,
                'cos' => round($scan['cos'][$id], 4), 'bm25' => round($bm25[$id] ?? 0.0, 4),
                'coverage' => $terms ? round(count(array_filter($scan['tf'][$id] ?? [])) / $nTerms, 3) : 0.0,
                'score' => round($fused[$id], 5),
            ];
            if (count($out) >= $k) {
                break;
            }
        }

        return $out;
    }

    /**
     * Một lượt quét các đoạn đã nhúng bằng đúng mô hình: tính cosine và đếm tần suất từ khóa.
     * Chỉ giữ số liệu nhỏ (không giữ vector) nên bộ nhớ không phụ thuộc số đoạn.
     */
    private function scan(Embedder $embedder, string $question, array $terms, ?int $destinationId): array
    {
        $q = DB::table('tbl_chunks')->where('model', $embedder->name())->whereNotNull('embedding');
        if ($destinationId) {
            $q->where(fn($w) => $w->where('destinationId', $destinationId)->orWhereNull('destinationId'));
        }
        $res = ['n' => 0, 'cos' => [], 'tf' => [], 'len' => [], 'month' => []];
        if (!(clone $q)->exists()) {
            return $res;
        }
        $qv = $embedder->embed([$question], 'query')[0];
        foreach ($q->select('chunkId', 'month', 'embedding', 'ftext')->orderBy('chunkId')->cursor() as $r) {
            $blob = is_resource($r->embedding) ? stream_get_contents($r->embedding) : (string) $r->embedding;
            $id = (int) $r->chunkId;
            $res['cos'][$id] = VectorMath::dot($qv, VectorMath::unpack($blob));
            $hay = ' ' . $r->ftext . ' ';
            foreach ($terms as $t) {
                $res['tf'][$id][$t] = substr_count($hay, ' ' . $t . ' ');
            }
            $res['len'][$id] = substr_count($r->ftext, ' ') + 1;
            $res['month'][$id] = $r->month ? (int) $r->month : null;
            $res['n']++;
        }

        return $res;
    }

    /** @return array<int,float> */
    private function bm25(array $scan, array $terms): array
    {
        $n = $scan['n'];
        $avg = max(1.0, array_sum($scan['len']) / max(1, $n));
        $idf = [];
        foreach ($terms as $t) {
            $df = 0;
            foreach ($scan['tf'] as $tf) {
                $df += ($tf[$t] ?? 0) > 0 ? 1 : 0;
            }
            $idf[$t] = log(1 + ($n - $df + 0.5) / ($df + 0.5));
        }
        $out = [];
        foreach ($scan['cos'] as $id => $_) {
            $s = 0.0;
            foreach ($terms as $t) {
                $f = $scan['tf'][$id][$t] ?? 0;
                if ($f > 0) {
                    $s += $idf[$t] * ($f * (self::K1 + 1)) / ($f + self::K1 * (1 - self::B + self::B * $scan['len'][$id] / $avg));
                }
            }
            $out[$id] = $s;
        }

        return $out;
    }

    /**
     * Kiểm tra lại lúc truy vấn: nguồn của đoạn vẫn còn published? (Chỉ mục có thể cũ hơn nội dung nếu vừa gỡ duyệt
     * mà chưa chạy rag:build.) Đây là chốt chặn thứ hai của quy tắc "chỉ dùng nội dung published".
     *
     * @param array<int,object> $rows
     * @return array<int,true> chunkId còn hợp lệ
     */
    public function stillPublished(array $rows): array
    {
        $map = [
            'guide' => ['tbl_guides', 'guideId', 'reviewStatus', 'published'],
            'destination' => ['tbl_destinations', 'destinationId', 'isActive', 1],
            'food' => ['tbl_foods', 'foodId', 'reviewStatus', 'published'],
            'event' => ['tbl_events', 'eventId', 'reviewStatus', 'published'],
            'itinerary' => ['tbl_itineraries', 'itineraryId', 'reviewStatus', 'published'],
        ];
        $byType = [];
        foreach ($rows as $r) {
            $byType[$r->refType][] = (int) $r->refId;
        }
        $ok = [];
        foreach ($byType as $type => $ids) {
            if (!isset($map[$type])) {
                continue;
            }
            [$table, $pk, $col, $val] = $map[$type];
            $live = DB::table($table)->whereIn($pk, array_unique($ids))->where($col, $val)->pluck($pk)->map(fn($x) => (int) $x)->all();
            $ok[$type] = array_flip($live);
        }
        $out = [];
        foreach ($rows as $r) {
            if (isset($ok[$r->refType][(int) $r->refId])) {
                $out[(int) $r->chunkId] = true;
            }
        }

        return $out;
    }
}
