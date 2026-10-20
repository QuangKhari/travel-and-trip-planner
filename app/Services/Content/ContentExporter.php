<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * A11: xuất nội dung lớp Khám phá thành một mảng (ghi ra JSON) để máy khác nạp lại bằng ContentSeeder.
 * Khóa tham chiếu là slug (không phải id tự tăng) nên chạy được trên CSDL trống. Ảnh KHÔNG nằm trong JSON,
 * chỉ lưu đường dẫn; thư mục ảnh (MEDIA_PUBLIC_ROOT/discover) phải chép riêng.
 */
class ContentExporter
{
    private const FOOD = ['slug', 'name', 'category', 'description', 'coverImage', 'sourceUrl', 'license', 'imageCredit', 'imageSourceUrl', 'reviewStatus'];
    private const EVENT = ['slug', 'name', 'isLunar', 'lunarMonth', 'lunarDay', 'solarMonth', 'solarDay', 'durationDays', 'dateNote', 'description', 'coverImage', 'sourceUrl', 'license', 'imageCredit', 'imageSourceUrl', 'reviewStatus'];
    private const GUIDE = ['slug', 'title', 'body', 'coverImage', 'updatedOn', 'sourceUrl', 'license', 'imageCredit', 'reviewStatus'];
    private const ITIN = ['slug', 'title', 'days', 'region', 'summary', 'coverImage', 'sourceUrl', 'license', 'imageCredit', 'reviewStatus'];
    private const PLACE = ['source', 'externalId', 'name', 'category', 'kind', 'address', 'latitude', 'longitude', 'rank', 'website', 'reviewStatus'];

    /** @param 'published'|'all' $status  phạm vi nội dung biên tập và địa điểm */
    public function build(string $status = 'published'): array
    {
        $dest = DB::table('tbl_destinations')->pluck('slug', 'destinationId');
        $foodSlug = DB::table('tbl_foods')->pluck('slug', 'foodId');
        $eventSlug = DB::table('tbl_events')->pluck('slug', 'eventId');
        $placeKey = DB::table('tbl_external_places')->selectRaw("placeId, CONCAT(source, ':', externalId) as k")->pluck('k', 'placeId');
        $scope = fn ($q) => $status === 'all' ? $q : $q->where('reviewStatus', 'published');
        $pick = fn ($row, array $cols) => array_intersect_key((array) $row, array_flip($cols));

        $months = [];
        foreach (DB::table('tbl_destination_months')->orderBy('destinationId')->orderBy('month')->get() as $m) {
            $months[$dest[$m->destinationId]][] = ['month' => $m->month, 'level' => $m->level, 'note' => $m->note, 'source' => $m->source];
        }
        $climate = [];
        foreach (DB::table('tbl_destination_climate')->orderBy('destinationId')->orderBy('month')->get() as $c) {
            $climate[$dest[$c->destinationId]][] = ['month' => $c->month, 'tMax' => $c->tMax, 'tMin' => $c->tMin, 'rainMm' => $c->rainMm,
                'rainyDays' => $c->rainyDays, 'years' => $c->years, 'periodFrom' => $c->periodFrom, 'periodTo' => $c->periodTo];
        }

        $foods = $scope(DB::table('tbl_foods'))->orderBy('slug')->get()->map(fn ($r) => ['destination' => $dest[$r->destinationId] ?? null] + $pick($r, self::FOOD))->all();
        $events = $scope(DB::table('tbl_events'))->orderBy('slug')->get()->map(fn ($r) => ['destination' => $dest[$r->destinationId] ?? null] + $pick($r, self::EVENT))->all();

        $catSlug = DB::table('tbl_guide_categories')->pluck('slug', 'categoryId');
        $cats = DB::table('tbl_guide_categories')->orderBy('displayOrder')->get(['slug', 'name', 'displayOrder'])->map(fn ($r) => (array) $r)->all();
        $guides = $scope(DB::table('tbl_guides'))->orderBy('slug')->get()->map(fn ($r) => ['category' => $catSlug[$r->categoryId] ?? null] + $pick($r, self::GUIDE))->all();

        $itins = [];
        foreach ($scope(DB::table('tbl_itineraries'))->orderBy('slug')->get() as $i) {
            $items = DB::table('tbl_itinerary_items')->where('itineraryId', $i->itineraryId)->orderBy('dayNo')->orderBy('orderNo')->get()->map(function ($it) use ($dest, $foodSlug, $eventSlug, $placeKey) {
                $ref = match ($it->refType) {
                    'destination' => $dest[$it->refId] ?? null, 'food' => $foodSlug[$it->refId] ?? null,
                    'event' => $eventSlug[$it->refId] ?? null, 'place' => $placeKey[$it->refId] ?? null, default => null,
                };

                return ['dayNo' => $it->dayNo, 'orderNo' => $it->orderNo, 'refType' => $it->refType, 'ref' => $ref, 'label' => $it->label, 'note' => $it->note];
            })->all();
            $itins[] = $pick($i, self::ITIN) + ['items' => $items];
        }

        $places = $scope(DB::table('tbl_external_places'))->orderBy('destinationId')->orderBy('placeId')->get()
            ->map(fn ($r) => ['destination' => $dest[$r->destinationId] ?? null] + $pick($r, self::PLACE))->all();

        return [
            'version' => 1, 'exportedAt' => now()->toIso8601String(), 'status' => $status,
            'months' => $months, 'climate' => $climate, 'foods' => $foods, 'events' => $events,
            'guideCategories' => $cats, 'guides' => $guides, 'itineraries' => $itins, 'places' => $places,
        ];
    }
}
