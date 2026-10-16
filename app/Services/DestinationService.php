<?php

namespace App\Services;

use App\Services\Discover\WeatherService;
use App\Support\DiscoverImage;
use Illuminate\Support\Facades\DB;

/**
 * Trang chi tiết điểm đến + API: ghép dữ liệu của hệ thống (DB), thời tiết cache, POI cache.
 * Không gọi API ngoài.
 */
class DestinationService
{
    public function __construct(private WeatherService $weather) {}

    public function find(string $slug): ?object
    {
        $d = DB::table('tbl_destinations')->where('slug', $slug)->where('isActive', 1)->first();
        if (!$d) {
            return null;
        }
        $d->image = DiscoverImage::url($d->coverImage);
        $d->domainName = InspirationService::DOMAINS[$d->domain] ?? '';
        $d->url = route('destination.show', ['slug' => $d->slug]);

        return $d;
    }

    /** Cảm hứng mà điểm đến này hợp, điểm cao trước. */
    public function inspirations(object $d): array
    {
        return DB::table('tbl_destination_inspiration as di')
            ->join('tbl_inspirations as i', 'i.inspirationId', '=', 'di.inspirationId')
            ->where('di.destinationId', $d->destinationId)
            ->where('i.isActive', 1)
            ->orderByDesc('di.score')
            ->get(['i.slug', 'i.name', 'i.icon', 'di.score'])
            ->map(fn($r) => ['slug' => $r->slug, 'name' => $r->name, 'icon' => $r->icon, 'score' => (int) $r->score])
            ->all();
    }

    /** @return array<string,array> category => places (đã sắp theo rank) */
    public function places(object $d, ?string $category = null): array
    {
        $q = DB::table('tbl_external_places')->where('destinationId', $d->destinationId);
        if ($category !== null) {
            $q->where('category', $category);
        }
        $out = [];
        foreach ($q->orderByDesc('rank')->orderBy('name')->get() as $p) {
            $out[$p->category][] = [
                'id' => (int) $p->placeId,
                'name' => $p->name,
                'category' => $p->category,
                'kind' => $p->kind,
                'address' => $p->address,
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'website' => $p->website,
                'source' => $p->source,
            ];
        }

        return $out;
    }

    public function weather(object $d): ?array
    {
        return $this->weather->get((int) $d->destinationId);
    }

    public function tours(object $d, int $limit = 6)
    {
        return DB::table('tbl_tours')
            ->where('destinationId', $d->destinationId)
            ->where('availability', 1)
            ->where('quantity', '>', 0)
            ->whereDate('startDate', '>', now()->toDateString())
            ->orderBy('startDate')
            ->limit($limit)
            ->get();
    }

    /** Dạng JSON dùng chung cho API và trang chi tiết. */
    public function payload(object $d): array
    {
        return [
            'slug' => $d->slug,
            'name' => $d->name,
            'province' => $d->province,
            'domain' => $d->domain,
            'domainName' => $d->domainName,
            'description' => $d->description,
            'summary' => $d->wikiExtract,
            'latitude' => (float) $d->latitude,
            'longitude' => (float) $d->longitude,
            'image' => $d->image,
            'imageCredit' => $d->imageCredit,
            'imageSourceUrl' => $d->imageSourceUrl,
            'wikiUrl' => $d->wikiUrl,
            'url' => $d->url,
            'inspirations' => $this->inspirations($d),
            'weather' => $this->weather($d),
            'places' => $this->places($d),
        ];
    }
}
