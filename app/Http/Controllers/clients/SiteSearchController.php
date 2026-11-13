<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\DestinationMonthBrief;
use App\Services\Content\SearchCatalog;
use App\Services\Content\SearchIntent;
use Illuminate\Http\Request;

/** C3: /tim-kiem?q= — kết quả nhóm theo loại. Không thay /search (cửa vào tìm tour). */
class SiteSearchController extends Controller
{
    public function __construct(private SearchCatalog $search, private SearchIntent $intent, private DestinationMonthBrief $brief)
    {
        parent::__construct();
    }

    public function index(Request $r)
    {
        $raw = $r->query('q', $r->query('keyword', ''));
        $q = is_string($raw) ? trim(mb_substr($raw, 0, 60)) : '';
        $intent = $q === '' ? null : $this->intent->parse($q);
        $groups = $q === '' ? [] : $this->search->search($q, 6);
        // Câu tự nhiên dài ("Hà Nội 3 ngày văn hóa") hiếm khi khớp nguyên văn: thử lại bằng từ khóa đã làm sạch, rồi tên điểm đến.
        if ($intent && !array_sum(array_column($groups, 'total')) && $intent['searchQuery'] !== '') {
            $groups = $this->search->search($intent['searchQuery'], 6);
            if (!array_sum(array_column($groups, 'total')) && $intent['destination']) {
                $groups = $this->search->search($intent['destination']['name'], 6);
            }
        }
        // Thẻ trả lời nhanh khi nhận ra điểm đến + tháng (dữ liệu đã duyệt, từ DestinationMonthBrief)
        $quick = null;
        if ($intent && $intent['destination'] && $intent['month']) {
            $b = $this->brief->for($intent['destination']['id'], $intent['month']);
            $quick = $b ? [
                'title' => $intent['destination']['name'] . ' tháng ' . $intent['month'],
                'text' => DestinationMonthBrief::summaryText($b),
                'url' => route('destination.month', ['slug' => $intent['destination']['slug'], 'n' => $intent['month']]),
            ] : null;
        }

        // C-13: tìm theo Ý NGHĨA (vector embedding + BM25 của chỉ mục RAG) khi tìm theo chữ ra ít kết quả: "chỗ ngắm hoàng hôn yên tĩnh"...
        $semantic = $q !== '' && array_sum(array_column($groups, 'total')) < (int) config('rag.semantic_search.when_below', 3)
            ? $this->semantic($q, $groups) : [];

        return view('clients.guide.search', [
            'semantic' => $semantic,
            'title' => $q === '' ? 'Tìm kiếm' : 'Tìm "' . $q . '"',
            'q' => $q,
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'total')),
            'intent' => $intent,
            'quick' => $quick,
            'plannerUrl' => $intent && SearchIntent::plannable($intent) ? route('planner', $intent['plannerQuery']) : null,
            'noindex' => true,   // trang kết quả không đưa vào chỉ mục
        ]);
    }

    /** @return list<array{title:string,text:string,url:string,type:string}> tối đa 6 nội dung khác nhau, còn xuất bản */
    private function semantic(string $q, array $groups): array
    {
        if (!(bool) config('rag.semantic_search.enabled', true)) {
            return [];
        }
        try {
            $embedder = app(\App\Services\Rag\Embedding\EmbedderFactory::class)->make();
            $ret = app(\App\Services\Rag\Retriever::class);
            if ($ret->indexed($embedder) === 0) {
                return [];
            }
            $a = app(\App\Services\Rag\QueryAnalyzer::class)->analyze($q);
            // search() đã tự loại đoạn của nội dung không còn xuất bản (Retriever::stillPublished)
            $rows = $ret->search($embedder, $q, $a['terms'], $a['destination']['id'] ?? null, $a['month'], 12);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
        $seen = [];
        foreach ($groups as $g) {
            foreach ($g['items'] ?? [] as $c) {
                if (!empty($c['url'])) {
                    $seen[$c['url']] = true;   // đã hiện ở kết quả theo chữ
                }
            }
        }
        // cùng cổng liên quan với Hỏi đáp RAG: cosine đủ cao hoặc phủ đủ từ khóa (đoạn "lạc đề" không hiện)
        $gate = (array) config('rag.retrieval.gate');
        $minCos = (float) ($gate['min_cosine'][$embedder->key()] ?? $gate['min_cosine']['default'] ?? 0.3);
        $rows = array_filter($rows, fn($r) => $r['cos'] >= $minCos || $r['coverage'] >= (float) ($gate['min_coverage'] ?? 0.6));
        $labels = ['destination' => 'Điểm đến', 'food' => 'Món ăn', 'experience' => 'Trải nghiệm', 'event' => 'Lễ hội', 'guide' => 'Cẩm nang', 'itinerary' => 'Lịch trình', 'place' => 'Địa điểm'];
        $out = [];
        foreach ($rows as $r) {
            $url = (string) ($r['url'] ?? '');
            $key = $r['type'] . ':' . $r['refId'];
            if ($url === '' || isset($seen[$url]) || isset($out[$key])) {
                continue;
            }
            $out[$key] = ['title' => (string) $r['title'], 'text' => \Illuminate\Support\Str::limit(trim(strip_tags((string) $r['text'])), 160),
                'url' => $url, 'type' => $labels[$r['type']] ?? ucfirst((string) $r['type'])];
            if (count($out) >= 6) {
                break;
            }
        }

        return array_values($out);
    }
}
