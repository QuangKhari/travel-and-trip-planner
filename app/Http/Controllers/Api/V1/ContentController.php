<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EventDateService;
use App\Support\DiscoverImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A10: API công khai lớp Khám phá. CHỈ trả nội dung reviewStatus = published
 * (tháng đẹp và khí hậu là dữ liệu số, không qua duyệt). Mọi bản ghi kèm giấy phép/nguồn/ghi công để hiển thị.
 *
 * GET /destinations/{slug}/months      12 tháng: level best|ok|avoid|unknown, note, khí hậu
 * GET /destinations/{slug}/foods
 * GET /destinations/{slug}/events      lễ hội của điểm đến + lễ hội chung, kèm 3 đợt ngày dương sắp tới
 * GET /foods?destination=&category=&limit=
 * GET /events?month=&year=&destination=&limit=
 * GET /guides  GET /guides/{slug}
 * GET /itineraries?region=&days=  GET /itineraries/{slug}
 */
class ContentController extends Controller
{
    public function __construct(private EventDateService $dates)
    {
        parent::__construct();
    }

    private function destination(string $slug): ?object
    {
        return DB::table('tbl_destinations')->where('slug', $slug)->where('isActive', 1)->first();
    }

    private function notFound(string $what): JsonResponse
    {
        return response()->json(['message' => "Không tìm thấy {$what}."], 404);
    }

    private function limit(Request $r, int $def = 50): int
    {
        return max(1, min(100, (int) $r->query('limit', $def)));
    }

    private function legal(object $r): array
    {
        return [
            'image' => DiscoverImage::url($r->coverImage ?? null),
            'imageCredit' => $r->imageCredit ?? null,
            'imageSourceUrl' => $r->imageSourceUrl ?? null,
            'sourceUrl' => $r->sourceUrl ?? null,
            'license' => $r->license ?? null,
        ];
    }

    public function months(string $slug): JsonResponse
    {
        $d = $this->destination($slug);
        if (!$d) {
            return $this->notFound('điểm đến');
        }
        $m = DB::table('tbl_destination_months')->where('destinationId', $d->destinationId)->get()->keyBy('month');
        $c = DB::table('tbl_destination_climate')->where('destinationId', $d->destinationId)->get()->keyBy('month');

        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $out[] = [
                'month' => $i,
                'level' => $m[$i]->level ?? 'unknown',
                'note' => $m[$i]->note ?? null,
                'source' => $m[$i]->source ?? null,
                'climate' => isset($c[$i]) ? [
                    'tMax' => (float) $c[$i]->tMax,
                    'tMin' => (float) $c[$i]->tMin,
                    'rainMm' => (float) $c[$i]->rainMm,
                    'rainyDays' => (float) $c[$i]->rainyDays,
                    'period' => $c[$i]->periodFrom . '-' . $c[$i]->periodTo,
                ] : null,
            ];
        }

        return response()->json(['data' => $out, 'attribution' => 'Khí hậu: Open-Meteo.com (CC BY 4.0)']);
    }

    private function foodPayload(object $f): array
    {
        return [
            'slug' => $f->slug,
            'name' => $f->name,
            'category' => $f->category,
            'description' => $f->description,
            'destination' => $f->destSlug ? ['slug' => $f->destSlug, 'name' => $f->destName] : null,
        ] + $this->legal($f);
    }

    private function foodsQuery()
    {
        return DB::table('tbl_foods as f')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('f.reviewStatus', 'published')->select('f.*', 'd.slug as destSlug', 'd.name as destName')->orderBy('f.name');
    }

    public function destinationFoods(Request $request, string $slug): JsonResponse
    {
        $d = $this->destination($slug);
        if (!$d) {
            return $this->notFound('điểm đến');
        }
        $rows = $this->foodsQuery()->where('f.destinationId', $d->destinationId)->limit($this->limit($request))->get();

        return response()->json(['data' => $rows->map(fn($f) => $this->foodPayload($f))->values()]);
    }

    public function foods(Request $request): JsonResponse
    {
        $q = $this->foodsQuery();
        if (is_string($s = $request->query('destination')) && $s !== '') {
            $q->where('d.slug', $s);
        }
        if (is_string($c = $request->query('category')) && $c !== '') {
            $q->where('f.category', $c);
        }

        return response()->json(['data' => $q->limit($this->limit($request))->get()->map(fn($f) => $this->foodPayload($f))->values()]);
    }

    private function eventsQuery()
    {
        return DB::table('tbl_events as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->select('e.*', 'd.slug as destSlug', 'd.name as destName')->orderBy('e.name');
    }

    private function eventPayload(object $e, array $dates): array
    {
        return [
            'slug' => $e->slug,
            'name' => $e->name,
            'description' => $e->description,
            'destination' => $e->destSlug ? ['slug' => $e->destSlug, 'name' => $e->destName] : null,
            'national' => $e->destinationId === null,
            'rule' => [
                'isLunar' => (bool) $e->isLunar,
                'lunarMonth' => $e->lunarMonth,
                'lunarDay' => $e->lunarDay,
                'solarMonth' => $e->solarMonth,
                'solarDay' => $e->solarDay,
                'durationDays' => (int) $e->durationDays,
            ],
            'dateNote' => $e->dateNote,
            'dates' => $dates,   // [{start,end,approx}]; approx = chỉ biết tháng
        ] + $this->legal($e);
    }

    public function destinationEvents(string $slug): JsonResponse
    {
        $d = $this->destination($slug);
        if (!$d) {
            return $this->notFound('điểm đến');
        }
        $rows = $this->eventsQuery()->where(fn($q) => $q->where('e.destinationId', $d->destinationId)->orWhereNull('e.destinationId'))->get();

        return response()->json(['data' => $rows->map(fn($e) => $this->eventPayload($e, $this->dates->upcoming($e, null, 3)))->values()]);
    }

    public function events(Request $request): JsonResponse
    {
        $q = $this->eventsQuery();
        if (is_string($s = $request->query('destination')) && $s !== '') {
            $q->where(fn($w) => $w->where('d.slug', $s)->orWhereNull('e.destinationId'));
        }
        $month = (int) $request->query('month', 0);
        $out = [];

        foreach ($q->get() as $e) {
            if ($month >= 1 && $month <= 12) {
                $year = (int) $request->query('year', now()->format('Y'));
                $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                $last = $first->modify('last day of this month');
                $dates = array_values(array_filter($this->dates->upcoming($e, $first, 40), fn($x) => $x['start'] <= $last->format('Y-m-d')));
                if (!$dates) {
                    continue;
                }
            } else {
                $dates = $this->dates->upcoming($e, null, 3);
            }
            $out[] = $this->eventPayload($e, $dates);
        }
        usort($out, fn($a, $b) => ($a['dates'][0]['start'] ?? '9999') <=> ($b['dates'][0]['start'] ?? '9999'));

        return response()->json(['data' => array_slice($out, 0, $this->limit($request))]);
    }

    public function guides(): JsonResponse
    {
        $rows = DB::table('tbl_guides as g')->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')->select('g.*', 'c.slug as catSlug', 'c.name as catName')
            ->orderBy('c.displayOrder')->orderBy('g.title')->get();

        return response()->json(['data' => $rows->map(fn($g) => [
            'slug' => $g->slug,
            'title' => $g->title,
            'updatedOn' => $g->updatedOn,
            'category' => $g->catSlug ? ['slug' => $g->catSlug, 'name' => $g->catName] : null,
        ] + $this->legal($g))->values()]);
    }

    public function guide(string $slug): JsonResponse
    {
        $g = DB::table('tbl_guides as g')->leftJoin('tbl_guide_categories as c', 'c.categoryId', '=', 'g.categoryId')
            ->where('g.reviewStatus', 'published')->where('g.slug', $slug)->select('g.*', 'c.slug as catSlug', 'c.name as catName')->first();
        if (!$g) {
            return $this->notFound('bài cẩm nang');
        }

        return response()->json(['data' => [
            'slug' => $g->slug,
            'title' => $g->title,
            'body' => $g->body,
            'updatedOn' => $g->updatedOn,
            'category' => $g->catSlug ? ['slug' => $g->catSlug, 'name' => $g->catName] : null,
        ] + $this->legal($g)]);   // body là nội dung biên tập: phía hiển thị phải escape/sanitize
    }

    public function itineraries(Request $request): JsonResponse
    {
        $q = DB::table('tbl_itineraries')->where('reviewStatus', 'published')->orderBy('days')->orderBy('title');
        if (in_array($request->query('region'), ['b', 't', 'n'], true)) {
            $q->where('region', $request->query('region'));
        }
        if ($request->query('days') !== null) {
            $q->where('days', (int) $request->query('days'));
        }

        return response()->json(['data' => $q->get()->map(fn($i) => [
            'slug' => $i->slug,
            'title' => $i->title,
            'days' => (int) $i->days,
            'region' => $i->region,
            'summary' => $i->summary,
        ] + $this->legal($i))->values()]);
    }

    public function itinerary(string $slug): JsonResponse
    {
        $i = DB::table('tbl_itineraries')->where('reviewStatus', 'published')->where('slug', $slug)->first();
        if (!$i) {
            return $this->notFound('lịch trình');
        }
        $items = DB::table('tbl_itinerary_items')->where('itineraryId', $i->itineraryId)->orderBy('dayNo')->orderBy('orderNo')->get();
        $days = [];
        foreach ($items as $it) {
            $days[$it->dayNo][] = ['order' => (int) $it->orderNo, 'refType' => $it->refType, 'refId' => $it->refId, 'label' => $it->label, 'note' => $it->note, 'slot' => $it->slot ?? null, 'durationMin' => isset($it->durationMin) ? (int) $it->durationMin : null];
        }
        ksort($days);

        return response()->json(['data' => [
            'slug' => $i->slug,
            'title' => $i->title,
            'days' => (int) $i->days,
            'region' => $i->region,
            'summary' => $i->summary,
            'plan' => array_map(fn($d, $items) => ['day' => $d, 'items' => $items], array_keys($days), array_values($days)),
        ] + $this->legal($i)]);
    }
}
