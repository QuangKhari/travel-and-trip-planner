<?php

namespace App\Services\Content;

use App\Support\DiscoverImage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** /trai-nghiem: chỉ trả bản ghi reviewStatus = 'published' (cùng quy tắc với ContentCatalog). */
class ExperienceCatalog
{
    private function base()
    {
        return DB::table('tbl_experiences as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')
            ->select('e.*', 'd.slug as destSlug', 'd.name as destName', 'd.province as destProvince', 'd.domain as destDomain');
    }

    private static function region(?string $v): ?string
    {
        return isset(ContentCatalog::REGIONS[$v ?? '']) ? $v : null;
    }

    private static function like(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($q), 0, 60)) . '%';
    }

    public function card(object $e): object
    {
        return (object) [
            'kind' => 'experience',
            'slug' => $e->slug,
            'title' => $e->name,
            'badge' => ContentCatalog::REGIONS[$e->destDomain ?? ''] ?? '',
            'meta' => $e->destName,
            'chip' => $e->duration,
            'text' => $e->description,
            'image' => DiscoverImage::url($e->coverImage),
            'credit' => $e->imageCredit,
            'must' => (bool) $e->isMustTry,
            'featured' => (bool) ($e->isFeatured ?? false),
            'url' => route('experiences.show', ['slug' => $e->slug]),
        ];
    }

    /** @return array{items: LengthAwarePaginator, filters: array, total: int} */
    public function list(array $in, int $page, int $perPage = 12): array
    {
        $f = [
            'mien' => self::region($in['mien'] ?? null),
            'nhom' => is_string($in['nhom'] ?? null) ? mb_substr($in['nhom'], 0, 40) : null,
            'tinh' => is_string($in['tinh'] ?? null) ? mb_substr($in['tinh'], 0, 120) : null,
            'q' => is_string($in['q'] ?? null) ? trim($in['q']) : '',
        ];
        $q = $this->base();
        if ($f['mien']) {
            $q->where('d.domain', $f['mien']);
        }
        if ($f['nhom']) {
            $q->where('e.category', $f['nhom']);
        }
        if ($f['tinh']) {
            $q->where('d.province', $f['tinh']);
        }
        if ($f['q'] !== '') {
            $q->where('e.name', 'like', self::like($f['q']));
        }
        $total = (clone $q)->count();
        $items = $q->orderByDesc('e.isMustTry')->orderBy('e.name')->forPage($page, $perPage)->get()->map(fn($r) => $this->card($r));

        return [
            'items' => new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => route('experiences.index'), 'query' => array_filter($f, fn($v) => $v !== null && $v !== '')]),
            'filters' => $f,
            'total' => $total,
        ];
    }

    /** Số trải nghiệm theo loại, áp miền/tỉnh đang chọn (không áp loại). @return array<string,int> */
    public function groupCounts(?string $mien = null, ?string $tinh = null): array
    {
        $q = DB::table('tbl_experiences as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->whereNotNull('e.category');
        if (self::region($mien)) {
            $q->where('d.domain', $mien);
        }
        if ($tinh) {
            $q->where('d.province', $tinh);
        }

        return $q->groupBy('e.category')->orderBy('e.category')->selectRaw('e.category as name, COUNT(*) as n')
            ->pluck('n', 'name')->map(fn($n) => (int) $n)->all();
    }

    public function provinces(): array
    {
        return DB::table('tbl_experiences as e')->join('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->distinct()->orderBy('d.province')->pluck('d.province')->all();
    }

    /** Trải nghiệm đã duyệt của một điểm đến cho trang điểm đến; dòng meta của thẻ là các thông tin Planner dùng (độ khó, trong/ngoài nhà, mùa). */
    public function ofDestination(int $destinationId, int $limit = 6): Collection
    {
        return $this->base()->where('e.destinationId', $destinationId)->orderByDesc('e.isMustTry')->orderBy('e.name')
            ->limit($limit)->get()->map(function ($r) {
                $c = $this->card($r);
                $c->meta = self::facts($r) ?: $c->meta;

                return $c;
            });
    }

    /** "Vừa sức · Ngoài trời · Tháng 11–3 · Hợp: gia đình"; chỉ ghép những gì có dữ liệu. */
    public static function facts(object $e): string
    {
        $level = ['easy' => 'Dễ', 'medium' => 'Vừa sức', 'hard' => 'Khó'];
        $p = [];
        if (!empty($e->difficulty)) {
            $p[] = $level[$e->difficulty] ?? (string) $e->difficulty;
        }
        if (($e->indoor ?? null) !== null) {
            $p[] = $e->indoor ? 'Trong nhà' : 'Ngoài trời';
        }
        if (!empty($e->monthFrom) && !empty($e->monthTo)) {
            $p[] = (int) $e->monthFrom === (int) $e->monthTo ? 'Tháng ' . (int) $e->monthFrom : 'Tháng ' . (int) $e->monthFrom . '–' . (int) $e->monthTo;
        }
        if (!empty($e->suitableFor)) {
            $p[] = 'Hợp: ' . $e->suitableFor;
        }

        return implode(' · ', $p);
    }

    /** 3 trải nghiệm cho hero: có ảnh, ưu tiên "Phải thử", xoay theo ngày. */
    public function featured(int $n = 3): Collection
    {
        $day = date('Ymd');

        return $this->base()->whereNotNull('e.coverImage')->limit(60)->get()
            ->sortBy(fn($r) => [-(int) $r->isMustTry, crc32($r->slug . $day)])
            ->take($n)->values()->map(fn($r) => $this->card($r))->filter(fn($c) => !empty($c->image))->values();
    }

    public function find(string $slug): ?object
    {
        return $this->base()->where('e.slug', $slug)->first();
    }

    /** Trải nghiệm khác cùng điểm đến; thiếu thì lấy cùng loại ở nơi khác. */
    public function related(object $e, int $limit = 6): Collection
    {
        $out = collect();
        if ($e->destinationId) {
            $out = $this->base()->where('e.destinationId', $e->destinationId)->where('e.experienceId', '!=', $e->experienceId)
                ->orderBy('e.name')->limit($limit)->get()->map(fn($r) => $this->card($r));
        }
        if ($out->count() < $limit && $e->category) {
            $have = $out->pluck('slug')->push($e->slug)->all();
            $more = $this->base()->where('e.category', $e->category)->whereNotIn('e.slug', $have)
                ->orderBy('e.name')->limit($limit - $out->count())->get()->map(fn($r) => $this->card($r));
            $out = $out->concat($more);
        }

        return $out->values();
    }

    /** Tour còn chỗ, sắp khởi hành tại cùng điểm đến (cùng điều kiện với PlannerPreviewService). */
    public function toursOf(?int $destinationId, int $limit = 3): array
    {
        if (!$destinationId) {
            return [];
        }

        return DB::table('tbl_tours')->where('destinationId', $destinationId)->where('availability', 1)
            ->where('quantity', '>', 0)->whereDate('startDate', '>', now()->toDateString())
            ->orderBy('startDate')->limit($limit)->get(['tourId', 'title', 'startDate', 'priceAdult'])
            ->map(fn($t) => [
                'title' => $t->title,
                'start' => date('d/m/Y', strtotime($t->startDate)),
                'price' => number_format((float) $t->priceAdult, 0, ',', '.') . 'đ',
                'url' => route('tour-detail', ['id' => $t->tourId]),
            ])->all();
    }
}
