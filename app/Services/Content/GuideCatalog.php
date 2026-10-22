<?php

namespace App\Services\Content;

use App\Support\DiscoverImage;
use App\Support\Seo;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * C1: bài cẩm nang (tbl_guides, tbl_guide_categories). CHỈ trả bài reviewStatus = 'published'.
 * Nội dung bài (cột body) viết bằng Markdown: "## " là mục (sinh mục lục), "> " là hộp lưu ý.
 */
class GuideCatalog
{
    /** Bài chưa gán danh mục được gom vào danh mục ảo này. */
    public const OTHER_SLUG = 'khac';
    public const OTHER_NAME = 'Khác';

    private function base()
    {
        return DB::table('tbl_guides as g')
            ->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')
            ->select('g.*', 'c.slug as catSlug', 'c.name as catName');
    }

    public function url(object $g): string
    {
        return route('guides.show', ['category' => $g->catSlug ?: self::OTHER_SLUG, 'slug' => $g->slug]);
    }

    /** Đoạn tóm tắt thuần chữ lấy từ Markdown (cho thẻ, meta description, tìm kiếm). */
    public static function excerpt(?string $markdown, int $max = 160): string
    {
        $t = preg_replace('/^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+/mu', '', (string) $markdown) ?? '';
        $t = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $t) ?? '';
        $t = preg_replace('/[*_`]+/u', '', $t) ?? '';

        return Seo::text($t, $max);
    }

    /** Một thẻ cho partial gx.card. */
    public function card(object $g): object
    {
        return (object) [
            'kind' => 'guide',
            'slug' => $g->slug,
            'title' => $g->title,
            'badge' => $g->catName ?: self::OTHER_NAME,
            'meta' => $g->updatedOn ? 'Cập nhật ' . Carbon::parse($g->updatedOn)->format('d/m/Y') : null,
            'text' => self::excerpt($g->body),
            'image' => DiscoverImage::url($g->coverImage),
            'credit' => $g->imageCredit,
            'url' => $this->url($g),
        ];
    }

    /** Danh mục ảo "Khác" hoặc danh mục thật theo slug; null nếu không có. */
    public function category(string $slug): ?object
    {
        if ($slug === self::OTHER_SLUG) {
            return (object) ['categoryId' => null, 'slug' => self::OTHER_SLUG, 'name' => self::OTHER_NAME];
        }

        return DB::table('tbl_guide_categories')->where('slug', $slug)->first();
    }

    /**
     * Trang chỉ mục: mỗi danh mục có bài, kèm tối đa $perGroup bài mới nhất.
     *
     * @return list<array{category:object,items:Collection,total:int}>
     */
    public function groups(int $perGroup = 6): array
    {
        $rows = $this->base()->orderByDesc('g.updatedOn')->orderBy('g.title')->get();
        $by = $rows->groupBy(fn($g) => $g->catSlug ?: self::OTHER_SLUG);
        $out = [];
        foreach (DB::table('tbl_guide_categories')->orderBy('displayOrder')->orderBy('name')->get() as $c) {
            if ($by->has($c->slug)) {
                $out[] = ['category' => $c, 'items' => $by[$c->slug]->take($perGroup)->map(fn($g) => $this->card($g))->values(), 'total' => $by[$c->slug]->count()];
            }
        }
        if ($by->has(self::OTHER_SLUG)) {
            $out[] = ['category' => $this->category(self::OTHER_SLUG), 'items' => $by[self::OTHER_SLUG]->take($perGroup)->map(fn($g) => $this->card($g))->values(), 'total' => $by[self::OTHER_SLUG]->count()];
        }

        return $out;
    }

    public function list(object $cat, int $page, int $perPage = 12): LengthAwarePaginator
    {
        $q = $this->base();
        $cat->categoryId === null ? $q->whereNull('g.categoryId') : $q->where('g.categoryId', $cat->categoryId);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('g.updatedOn')->orderBy('g.title')->forPage($page, $perPage)->get();

        return new LengthAwarePaginator($rows->map(fn($g) => $this->card($g))->values(), $total, $perPage, $page, ['path' => route('guides.category', ['category' => $cat->slug])]);
    }

    public function find(string $slug): ?object
    {
        return $this->base()->where('g.slug', $slug)->first();
    }

    /** Bài liên quan: cùng danh mục trước, thiếu thì lấy bài mới nhất. */
    public function related(object $g, int $limit = 3): Collection
    {
        $same = $this->base()->where('g.guideId', '!=', $g->guideId)
            ->when($g->categoryId, fn($q) => $q->where('g.categoryId', $g->categoryId))
            ->orderByDesc('g.updatedOn')->limit($limit)->get();
        if ($same->count() < $limit) {
            $more = $this->base()->where('g.guideId', '!=', $g->guideId)->whereNotIn('g.guideId', $same->pluck('guideId'))
                ->orderByDesc('g.updatedOn')->limit($limit - $same->count())->get();
            $same = $same->concat($more);
        }

        return $same->map(fn($x) => $this->card($x))->values();
    }

    /**
     * Markdown -> HTML an toàn kèm mục lục. HTML thô trong bài bị bỏ, liên kết javascript: bị chặn, ảnh nhúng bị bỏ.
     *
     * @return array{html:string,toc:list<array{id:string,text:string}>}
     */
    public function render(string $markdown): array
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
        $toc = [];
        $used = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/su', function ($m) use (&$toc, &$used) {
            $text = trim(strip_tags($m[1]));
            $base = Str::slug($text) ?: 'muc';
            $id = $base;
            for ($i = 2; isset($used[$id]); $i++) {
                $id = $base . '-' . $i;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'text' => $text];

            return '<h2 id="' . e($id) . '">' . $m[1] . '</h2>';
        }, $html) ?? $html;

        return ['html' => $html, 'toc' => $toc];
    }
}
