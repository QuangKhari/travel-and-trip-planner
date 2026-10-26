<?php

namespace App\Services\Content;

use App\Support\DiscoverImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * C3: tìm kiếm toàn lớp Khám phá (điểm đến, món, trải nghiệm, lễ hội, lịch trình, cẩm nang). CHỈ nội dung published.
 *
 * Cách làm: nạp một bản chỉ mục nhỏ (tên + mô tả) vào bộ nhớ đệm 10 phút, bỏ dấu và chữ hoa/thường rồi so khớp
 * theo đầu từ. Chọn cách này thay cho FULLTEXT của MySQL vì: (1) FULLTEXT mặc định bỏ từ ngắn dưới 3 ký tự
 * (nhiều từ tiếng Việt như "hà", "bún", "an" bị mất); (2) FULLTEXT không tìm "bun cha" ra "Bún chả";
 * (3) cùng một mã chạy giống nhau trên MySQL và SQLite khi kiểm thử. Dữ liệu cỡ vài nghìn dòng vẫn nhanh.
 * Khi dữ liệu lớn hơn: đổi sang Laravel Scout (Meilisearch hoặc Elasticsearch) mà giữ nguyên controller.
 */
class SearchCatalog
{
    public const TYPES = [
        'destination' => 'Điểm đến',
        'food' => 'Ẩm thực',
        'experience' => 'Trải nghiệm',
        'event' => 'Lễ hội',
        'itinerary' => 'Lịch trình',
        'guide' => 'Cẩm nang',
    ];

    private const CACHE_KEY = 'search.index.v2';

    public function __construct(private GuideCatalog $guides) {}

    /** Bỏ dấu, hạ chữ thường, chỉ giữ chữ-số, gọn khoảng trắng. "Bún chả Đà Lạt" -> "bun cha da lat". */
    public static function fold(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii($s))) ?? '');
    }

    private static function has(string $haystack, string $term): bool
    {
        return str_contains(' ' . $haystack, ' ' . $term);   // khớp đầu từ
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<array<string,mixed>> */
    private function index(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, fn() => $this->build());
    }

    /** @return list<array<string,mixed>> */
    private function build(): array
    {
        $rows = [];
        $add = function (string $type, string $slug, string $title, ?string $badge, ?string $meta, ?string $text, ?string $cover, ?string $credit, string $url) use (&$rows) {
            $rows[] = [
                'type' => $type, 'slug' => $slug, 'title' => $title, 'badge' => $badge, 'meta' => $meta,
                'text' => $text, 'image' => DiscoverImage::url($cover), 'credit' => $credit, 'url' => $url,
                't' => self::fold($title), 'x' => self::fold(trim(($meta ?? '') . ' ' . ($badge ?? '') . ' ' . ($text ?? ''))),
            ];
        };

        foreach (DB::table('tbl_destinations')->where('isActive', 1)->get() as $d) {
            $add('destination', $d->slug, $d->name, ContentCatalog::REGIONS[$d->domain] ?? null, $d->province, $d->description, $d->coverImage, $d->imageCredit, route('destination.show', ['slug' => $d->slug]));
        }
        $foods = DB::table('tbl_foods as f')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('f.reviewStatus', 'published')->get(['f.*', 'd.name as destName']);
        foreach ($foods as $f) {
            $add('food', $f->slug, $f->name, $f->category, $f->destName, $f->description, $f->coverImage, $f->imageCredit, route('foods.show', ['slug' => $f->slug]));
        }
        $exps = DB::table('tbl_experiences as x')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'x.destinationId')
            ->where('x.reviewStatus', 'published')->get(['x.*', 'd.name as destName']);
        foreach ($exps as $x) {
            $add('experience', $x->slug, $x->name, $x->category, $x->destName, $x->description, $x->coverImage, $x->imageCredit, route('experiences.show', ['slug' => $x->slug]));
        }
        $events = DB::table('tbl_events as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->get(['e.*', 'd.name as destName']);
        foreach ($events as $e) {
            $add('event', $e->slug, $e->name, null, $e->destName ?: 'Cả nước', $e->description, $e->coverImage, $e->imageCredit, route('events.show', ['slug' => $e->slug]));
        }
        foreach (DB::table('tbl_itineraries')->where('reviewStatus', 'published')->get() as $i) {
            $add('itinerary', $i->slug, $i->title, $i->days . ' ngày', null, $i->summary, $i->coverImage, $i->imageCredit, route('itineraries.show', ['slug' => $i->slug]));
        }
        $guides = DB::table('tbl_guides as g')->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')->get(['g.*', 'c.slug as catSlug', 'c.name as catName']);
        foreach ($guides as $g) {
            $add('guide', $g->slug, $g->title, $g->catName ?: GuideCatalog::OTHER_NAME, null, GuideCatalog::excerpt($g->body, 600), $g->coverImage, $g->imageCredit, $this->guides->url($g));
        }

        return $rows;
    }

    /**
     * Điểm khớp của một dòng chỉ mục; 0 = không khớp. Mọi từ khóa phải xuất hiện (đầu từ) trong tên hoặc mô tả.
     *
     * @param list<string> $terms
     */
    private function score(array $row, string $folded, array $terms): int
    {
        $score = 0;
        foreach ($terms as $term) {
            if (self::has($row['t'], $term)) {
                $score += 10;
            } elseif (self::has($row['x'], $term)) {
                $score += 2;
            } else {
                return 0;
            }
        }
        if ($row['t'] === $folded) {
            $score += 100;
        } elseif (str_starts_with($row['t'], $folded)) {
            $score += 60;
        } elseif (str_contains($row['t'], $folded)) {
            $score += 30;
        }

        return $score;
    }

    /** @return list<array<string,mixed>> các dòng khớp, điểm cao trước */
    private function match(string $q): array
    {
        $folded = self::fold(mb_substr($q, 0, 60));
        if (mb_strlen($folded) < 2) {
            return [];
        }
        $terms = array_values(array_filter(explode(' ', $folded)));
        $hits = [];
        foreach ($this->index() as $row) {
            if ($s = $this->score($row, $folded, $terms)) {
                $hits[] = $row + ['score' => $s];
            }
        }
        usort($hits, fn($a, $b) => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);

        return $hits;
    }

    /**
     * Kết quả nhóm theo loại cho trang /tim-kiem.
     *
     * @return array<string,array{label:string,total:int,items:list<object>}>
     */
    public function search(string $q, int $perType = 6): array
    {
        $out = [];
        foreach ($this->match($q) as $h) {
            $g = &$out[$h['type']];
            $g ??= ['label' => self::TYPES[$h['type']], 'total' => 0, 'items' => []];
            $g['total']++;
            if (count($g['items']) < $perType) {
                $g['items'][] = (object) [
                    'kind' => $h['type'], 'slug' => $h['slug'], 'title' => $h['title'], 'badge' => $h['badge'], 'meta' => $h['meta'],
                    'text' => $h['text'], 'image' => $h['image'], 'credit' => $h['credit'], 'url' => $h['url'],
                ];
            }
            unset($g);
        }
        // giữ thứ tự nhóm cố định như TYPES
        return array_replace(array_intersect_key(array_fill_keys(array_keys(self::TYPES), null), $out), $out);
    }

    /**
     * Gợi ý khi gõ: tối đa $limit dòng, mỗi dòng gồm loại, tiêu đề, dòng phụ và đường dẫn.
     *
     * @return list<array{type:string,typeLabel:string,title:string,meta:?string,url:string}>
     */
    public function suggest(string $q, int $limit = 8): array
    {
        return array_map(fn($h) => [
            'type' => $h['type'],
            'typeLabel' => self::TYPES[$h['type']],
            'title' => $h['title'],
            'meta' => $h['meta'] ?: $h['badge'],
            'url' => $h['url'],
        ], array_slice($this->match($q), 0, $limit));
    }
}
