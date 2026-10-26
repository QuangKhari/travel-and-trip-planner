<?php

namespace App\Services\Content;

use App\Services\EventDateService;
use App\Support\DiscoverImage;
use App\Support\PlaceKind;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tuần 2, mục 7: "Phải thử" gom năm nhóm do BIÊN TẬP đánh dấu isMustTry: điểm đến, địa điểm, món, trải nghiệm, lễ hội.
 * Chỉ nội dung đã duyệt (điểm đến isActive = 1, còn lại reviewStatus = published). Lễ hội còn phải có ngày sắp tới.
 * Dùng chung cho /phai-thu và khối "Điểm phải thử" ở trang điểm đến (không viết logic riêng trong Blade).
 */
class MustDoCatalog
{
    public const PER_GROUP = 12;

    public const GROUPS = [
        'destination' => 'Điểm đến phải đến',
        'place' => 'Địa điểm phải ghé',
        'food' => 'Món phải thử',
        'experience' => 'Trải nghiệm phải thử',
        'event' => 'Lễ hội phải dự',
    ];

    public function __construct(private EventDateService $dates) {}

    /**
     * Danh sách cho trang /phai-thu.
     *
     * @param array<string,mixed> $in query string
     * @return array{groups:array<string,list<object>>,filters:array<string,?string>,total:int}
     */
    public function groups(array $in, int $perGroup = self::PER_GROUP): array
    {
        $slug = is_string($in['diemden'] ?? null) && preg_match('/^[a-z0-9-]{1,120}$/', $in['diemden']) ? $in['diemden'] : null;
        $loai = is_string($in['loai'] ?? null) && isset(self::GROUPS[$in['loai']]) ? $in['loai'] : null;
        $f = ['mien' => ContentCatalog::validRegion($in['mien'] ?? null), 'diemden' => $slug, 'loai' => $loai];
        $destId = $slug ? (int) DB::table('tbl_destinations')->where('slug', $slug)->where('isActive', 1)->value('destinationId') : null;

        $groups = [];
        $total = 0;
        foreach (self::GROUPS as $g => $_label) {
            if ($loai && $loai !== $g) {
                continue;
            }
            $groups[$g] = $slug && !$destId ? [] : $this->fetch($g, $f['mien'], $destId, $loai ? $perGroup * 4 : $perGroup);
            $total += count($groups[$g]);
        }

        return ['groups' => $groups, 'filters' => $f, 'total' => $total];
    }

    /**
     * Khối "Điểm phải thử" của một điểm đến: các nhóm place, food, experience, event (không lặp lại chính điểm đến).
     *
     * @return array{groups:array<string,list<object>>,total:int}
     */
    public function forDestination(int $destinationId, int $perGroup = 4): array
    {
        $groups = [];
        $total = 0;
        foreach (['food', 'experience', 'place', 'event'] as $g) {
            $groups[$g] = $this->fetch($g, null, $destinationId, $perGroup);
            $total += count($groups[$g]);
        }

        return ['groups' => $groups, 'total' => $total];
    }

    /** Điểm đến có ít nhất một mục phải thử (cho ô lọc). @return array<string,string> slug => tên */
    public function destinationOptions(): array
    {
        $ids = collect();
        foreach (['tbl_foods', 'tbl_experiences', 'tbl_events', 'tbl_external_places'] as $t) {
            $ids = $ids->merge(DB::table($t)->where('isMustTry', 1)->where('reviewStatus', 'published')->whereNotNull('destinationId')->distinct()->pluck('destinationId'));
        }

        return DB::table('tbl_destinations')->where('isActive', 1)
            ->where(fn ($w) => $w->where('isMustTry', 1)->orWhereIn('destinationId', $ids->unique()->values()->all()))
            ->orderBy('name')->pluck('name', 'slug')->all();
    }

    /** @return list<object> thẻ cho gx.card */
    private function fetch(string $group, ?string $region, ?int $destId, int $limit): array
    {
        return match ($group) {
            'destination' => $this->destinations($region, $destId, $limit),
            'place' => $this->places($region, $destId, $limit),
            'food' => $this->foods($region, $destId, $limit),
            'experience' => $this->experiences($region, $destId, $limit),
            default => $this->events($region, $destId, $limit),
        };
    }

    private function destinations(?string $region, ?int $destId, int $limit): array
    {
        $q = DB::table('tbl_destinations as d')->where('d.isActive', 1)->where('d.isMustTry', 1);
        $region && $q->where('d.domain', $region);
        $destId && $q->where('d.destinationId', $destId);

        return $q->orderBy('d.name')->limit($limit)->get()->map(fn ($d) => (object) [
            'kind' => 'destination', 'slug' => $d->slug, 'title' => $d->name,
            'badge' => ContentCatalog::REGIONS[$d->domain] ?? '', 'meta' => $d->province, 'chip' => null,
            'text' => $d->description, 'image' => DiscoverImage::url($d->coverImage), 'credit' => $d->imageCredit,
            'must' => true, 'url' => route('destination.show', ['slug' => $d->slug]),
        ])->all();
    }

    private function places(?string $region, ?int $destId, int $limit): array
    {
        $q = DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
            ->where('p.reviewStatus', 'published')->where('p.isMustTry', 1)->where('d.isActive', 1)
            ->whereNotNull('p.latitude')->whereNotNull('p.longitude');   // chỉ địa điểm có tọa độ hợp lệ (mục 5)
        $region && $q->where('d.domain', $region);
        $destId && $q->where('p.destinationId', $destId);

        return $q->orderByDesc('p.rank')->orderBy('p.name')->limit($limit)
            ->get(['p.*', 'd.slug as destSlug', 'd.name as destName', 'd.domain as destDomain'])
            ->map(fn ($p) => (object) [
                'kind' => 'place', 'slug' => (string) $p->placeId, 'title' => $p->name,
                'badge' => ContentCatalog::REGIONS[$p->destDomain] ?? '', 'meta' => $p->destName, 'chip' => PlaceKind::label($p->kind),
                'text' => $p->description ?: $p->address, 'image' => null, 'credit' => null, 'must' => true,
                // Địa điểm OSM chưa có trang riêng: dẫn về khối "Địa điểm gần đây" của điểm đến
                'url' => route('destination.show', ['slug' => $p->destSlug]) . '#gan-day',
            ])->all();
    }

    private function foods(?string $region, ?int $destId, int $limit): array
    {
        $q = DB::table('tbl_foods as f')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('f.reviewStatus', 'published')->where('f.isMustTry', 1);
        $region && $q->where('d.domain', $region);
        $destId && $q->where(function ($w) use ($destId) {
            $w->where('f.destinationId', $destId)->orWhereIn('f.foodId', DB::table('tbl_food_destination')->where('destinationId', $destId)->select('foodId'));
        });

        return $q->orderBy('f.name')->limit($limit)->get(['f.*', 'd.name as destName', 'd.domain as destDomain'])->map(fn ($f) => (object) [
            'kind' => 'food', 'slug' => $f->slug, 'title' => $f->name,
            'badge' => ContentCatalog::REGIONS[$f->destDomain ?? ''] ?? '', 'meta' => $f->destName, 'chip' => $f->category,
            'text' => $f->description, 'image' => DiscoverImage::url($f->coverImage), 'credit' => $f->imageCredit, 'must' => true,
            'url' => route('foods.show', ['slug' => $f->slug]),
        ])->all();
    }

    private function experiences(?string $region, ?int $destId, int $limit): array
    {
        $q = DB::table('tbl_experiences as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->where('e.isMustTry', 1);
        $region && $q->where('d.domain', $region);
        $destId && $q->where('e.destinationId', $destId);

        return $q->orderBy('e.name')->limit($limit)->get(['e.*', 'd.name as destName', 'd.domain as destDomain'])->map(fn ($e) => (object) [
            'kind' => 'experience', 'slug' => $e->slug, 'title' => $e->name,
            'badge' => ContentCatalog::REGIONS[$e->destDomain ?? ''] ?? '', 'meta' => $e->destName, 'chip' => $e->duration,
            'text' => $e->description, 'image' => DiscoverImage::url($e->coverImage), 'credit' => $e->imageCredit, 'must' => true,
            'url' => route('experiences.show', ['slug' => $e->slug]),
        ])->all();
    }

    /** Lễ hội phải dự: đã duyệt, được đánh dấu, và còn ngày sắp tới (không suy diễn "phải dự" chỉ vì lễ hội tồn tại). */
    private function events(?string $region, ?int $destId, int $limit): array
    {
        $q = DB::table('tbl_events as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->where('e.isMustTry', 1);
        $region && $q->where('d.domain', $region);
        $destId && $q->where('e.destinationId', $destId);

        $today = new DateTimeImmutable('today');
        $out = [];
        foreach ($q->get(['e.*', 'd.name as destName', 'd.domain as destDomain']) as $e) {
            $next = $this->dates->upcoming($e, $today, 1)[0] ?? null;
            if (!$next) {
                continue;
            }
            $out[] = ['start' => $next['start'], 'card' => (object) [
                'kind' => 'event', 'slug' => $e->slug, 'title' => $e->name,
                'badge' => $e->destName ?? 'Cả nước', 'meta' => ContentCatalog::range($next), 'chip' => null,
                'text' => $e->description, 'image' => DiscoverImage::url($e->coverImage), 'credit' => $e->imageCredit, 'must' => true,
                'url' => route('events.show', ['slug' => $e->slug]),
            ]];
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return array_map(fn ($x) => $x['card'], array_slice($out, 0, $limit));
    }
}
