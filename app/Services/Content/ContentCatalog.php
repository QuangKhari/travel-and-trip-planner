<?php

namespace App\Services\Content;

use App\Services\EventDateService;
use App\Services\InspirationService;
use App\Support\DiscoverImage;
use App\Support\Geo;
use DateTimeImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B2-B5: truy vấn nội dung lớp Khám phá cho các trang web (danh sách, chi tiết, thẻ liên kết chéo).
 * Quy tắc cứng: CHỈ trả nội dung reviewStatus = 'published' (điểm đến thì isActive = 1).
 * Mọi giá trị lọc từ URL được kiểm tra danh sách cho phép trước khi vào truy vấn.
 */
class ContentCatalog
{
    public const REGIONS = InspirationService::DOMAINS;

    public function __construct(private EventDateService $dates) {}

    private function paginate(Collection $all, int $perPage, int $page, string $path, array $query): LengthAwarePaginator
    {
        return (new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => $query],
        ));
    }

    private static function region(?string $v): ?string
    {
        return isset(self::REGIONS[$v ?? '']) ? $v : null;
    }

    private static function month(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 12 ? (int) $v : null;
    }

    private static function like(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($q), 0, 60)) . '%';
    }

    // ------------------------------------------------------------------ Điểm đến

    /** @return array{items:LengthAwarePaginator,filters:array,total:int} */
    public function destinations(array $in, int $page, int $perPage = 12): array
    {
        $f = [
            'mien' => self::region($in['mien'] ?? null),
            'tinh' => is_string($in['tinh'] ?? null) ? mb_substr($in['tinh'], 0, 120) : null,
            'camhung' => is_string($in['camhung'] ?? null) && preg_match('/^[a-z0-9-]{1,60}$/', $in['camhung']) ? $in['camhung'] : null,
            'thang' => self::month($in['thang'] ?? null),
            'q' => is_string($in['q'] ?? null) ? trim($in['q']) : '',
        ];
        $q = DB::table('tbl_destinations as d')->where('d.isActive', 1);
        if ($f['mien']) {
            $q->where('d.domain', $f['mien']);
        }
        if ($f['tinh']) {
            $q->where('d.province', $f['tinh']);
        }
        if ($f['camhung']) {
            $q->whereExists(fn($s) => $s->from('tbl_destination_inspiration as di')
                ->join('tbl_inspirations as i', 'i.inspirationId', '=', 'di.inspirationId')
                ->whereColumn('di.destinationId', 'd.destinationId')->where('i.slug', $f['camhung']));
        }
        if ($f['thang']) {
            $q->whereExists(fn($s) => $s->from('tbl_destination_months as dm')
                ->whereColumn('dm.destinationId', 'd.destinationId')->where('dm.month', $f['thang'])->where('dm.level', 'best'));
        }
        if ($f['q'] !== '') {
            $like = self::like($f['q']);
            $q->where(fn($w) => $w->where('d.name', 'like', $like)->orWhere('d.province', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('d.name')->forPage($page, $perPage)
            ->get(['d.destinationId', 'd.slug', 'd.name', 'd.province', 'd.domain', 'd.description', 'd.coverImage', 'd.imageCredit', 'd.isMustTry']);

        $items = $rows->map(fn($d) => (object) [
            'kind' => 'destination',
            'slug' => $d->slug,
            'title' => $d->name,
            'badge' => self::REGIONS[$d->domain] ?? '',
            'meta' => $d->province,
            'text' => $d->description,
            'image' => DiscoverImage::url($d->coverImage),
            'credit' => $d->imageCredit,
            'must' => (bool) ($d->isMustTry ?? false),
            'url' => route('destination.show', ['slug' => $d->slug]),
        ]);

        $pager = new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => route('destination'), 'query' => array_filter($f, fn($v) => $v !== null && $v !== '')]);

        return ['items' => $pager, 'filters' => $f, 'total' => $total];
    }

    public function provinces(): array
    {
        return DB::table('tbl_destinations')->where('isActive', 1)->distinct()->orderBy('province')->pluck('province')->all();
    }

    // ------------------------------------------------------------------ Ẩm thực

    private function foodsBase()
    {
        return DB::table('tbl_foods as f')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('f.reviewStatus', 'published')->select('f.*', 'd.slug as destSlug', 'd.name as destName', 'd.province as destProvince', 'd.domain as destDomain');
    }

    private function foodCard(object $f): object
    {
        return (object) [
            'kind' => 'food',
            'slug' => $f->slug,
            'title' => $f->name,
            'badge' => $f->category,
            'meta' => $f->destName,
            'text' => $f->description,
            'image' => DiscoverImage::url($f->coverImage),
            'credit' => $f->imageCredit,
            'must' => (bool) ($f->isMustTry ?? false),
            'featured' => (bool) ($f->isFeatured ?? false),
            'url' => route('foods.show', ['slug' => $f->slug]),
        ];
    }

    public function foods(array $in, int $page, int $perPage = 12): array
    {
        $f = [
            'mien' => self::region($in['mien'] ?? null),
            'nhom' => is_string($in['nhom'] ?? null) ? mb_substr($in['nhom'], 0, 40) : null,
            'diemden' => is_string($in['diemden'] ?? null) && preg_match('/^[a-z0-9-]{1,80}$/', $in['diemden']) ? $in['diemden'] : null,
            'tinh' => is_string($in['tinh'] ?? null) ? mb_substr($in['tinh'], 0, 120) : null,
            'q' => is_string($in['q'] ?? null) ? trim($in['q']) : '',
        ];
        $q = $this->foodsBase();
        if ($f['tinh']) {
            $q->where('d.province', $f['tinh']);
        }
        if ($f['mien']) {
            $q->where('d.domain', $f['mien']);
        }
        if ($f['nhom']) {
            $q->where('f.category', $f['nhom']);
        }
        if ($f['diemden']) {
            $q->where('d.slug', $f['diemden']);
        }
        if ($f['q'] !== '') {
            $q->where('f.name', 'like', self::like($f['q']));
        }
        $total = (clone $q)->count();
        $items = $q->orderBy('f.name')->forPage($page, $perPage)->get()->map(fn($r) => $this->foodCard($r));

        return ['items' => new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => route('foods.index'), 'query' => array_filter($f, fn($v) => $v !== null && $v !== '')]), 'filters' => $f, 'total' => $total];
    }

    public function foodGroups(): array
    {
        return DB::table('tbl_foods')->where('reviewStatus', 'published')->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }

    /** Số món theo nhóm (cho chip lọc có số lượng). Áp miền/tỉnh đang chọn, không áp nhóm. @return array<string,int> */
    public function foodGroupCounts(?string $mien = null, ?string $tinh = null): array
    {
        $q = DB::table('tbl_foods as f')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('f.reviewStatus', 'published')->whereNotNull('f.category');
        if (self::region($mien)) {
            $q->where('d.domain', $mien);
        }
        if ($tinh) {
            $q->where('d.province', $tinh);
        }

        return $q->groupBy('f.category')->orderBy('f.category')->selectRaw('f.category as name, COUNT(*) as n')
            ->pluck('n', 'name')->map(fn($n) => (int) $n)->all();
    }

    /** Tỉnh/thành có ít nhất một món đã duyệt (cho ô lọc tỉnh ở /am-thuc). */
    public function foodProvinces(): array
    {
        return DB::table('tbl_foods as f')->join('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
            ->where('f.reviewStatus', 'published')->distinct()->orderBy('d.province')->pluck('d.province')->all();
    }

    /** 3 món cho hero: món có ảnh, ưu tiên "Phải thử", xoay theo ngày. */
    public function featuredFoods(int $n = 3): Collection
    {
        $day = date('Ymd');

        return $this->foodsBase()->whereNotNull('f.coverImage')->limit(60)->get()
            ->sortBy(fn($r) => [-(int) ($r->isFeatured ?? 0), -(int) ($r->isMustTry ?? 0), crc32($r->slug . $day)])
            ->take($n)->values()->map(fn($r) => $this->foodCard($r))->filter(fn($c) => !empty($c->image))->values();
    }

    public function food(string $slug): ?object
    {
        $f = $this->foodsBase()->where('f.slug', $slug)->first();
        if ($f && !empty($f->placeId)) {   // địa điểm ăn uống đã duyệt, có tọa độ: "nơi nên thử" có bản đồ; không có thì chỉ hiện chữ whereToTry
            $f->place = DB::table('tbl_external_places')->where('placeId', $f->placeId)->where('reviewStatus', 'published')->first(['placeId', 'name', 'address', 'kind', 'latitude', 'longitude']);
        }

        return $f;
    }

    public function foodsOf(int $destinationId, int $limit = 12, ?int $exceptId = null): Collection
    {
        $q = $this->foodsBase()->where(function ($w) use ($destinationId) {
            $w->where('f.destinationId', $destinationId)
                ->orWhereIn('f.foodId', DB::table('tbl_food_destination')->where('destinationId', $destinationId)->select('foodId'));
        });
        if ($exceptId) {
            $q->where('f.foodId', '!=', $exceptId);
        }

        return $q->orderBy('f.name')->limit($limit)->get()->map(fn($r) => $this->foodCard($r));
    }

    /** Món cùng nhóm ở điểm đến khác (biến thể liên kết chéo, tài liệu mục 4.4). */
    public function foodVariants(object $food, int $limit = 6): Collection
    {
        if (!$food->category) {
            return collect();
        }

        return $this->foodsBase()->where('f.category', $food->category)->where('f.foodId', '!=', $food->foodId)
            ->orderBy('f.name')->limit($limit)->get()->map(fn($r) => $this->foodCard($r));
    }

    // ------------------------------------------------------------------ Lễ hội

    private function eventsBase()
    {
        return DB::table('tbl_events as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
            ->where('e.reviewStatus', 'published')->select('e.*', 'd.slug as destSlug', 'd.name as destName', 'd.province as destProvince', 'd.domain as destDomain');
    }

    private function eventCard(object $e, array $dates): object
    {
        $next = $dates[0] ?? null;

        return (object) [
            'kind' => 'event',
            'slug' => $e->slug,
            'title' => $e->name,
            'badge' => $e->destName ?? 'Cả nước',
            'meta' => $next ? self::range($next) : ($e->dateNote ?: null),
            'text' => $e->description,
            'image' => DiscoverImage::url($e->coverImage),
            'credit' => $e->imageCredit,
            'url' => route('events.show', ['slug' => $e->slug]),
            'start' => $next['start'] ?? null,
        ];
    }

    /** "15/10/2026" hoặc "15 – 18/10/2026"; ngày chưa rõ: "Tháng 10/2026". */
    public static function range(array $d): string
    {
        $s = new DateTimeImmutable($d['start']);
        if (!empty($d['approx'])) {
            return 'Tháng ' . $s->format('n/Y');
        }
        $e = new DateTimeImmutable($d['end']);
        if ($d['start'] === $d['end']) {
            return $s->format('d/m/Y');
        }

        return $s->format('Y-m') === $e->format('Y-m')
            ? $s->format('d') . ' – ' . $e->format('d/m/Y')
            : $s->format('d/m') . ' – ' . $e->format('d/m/Y');
    }

    public function events(array $in, int $page, int $perPage = 12): array
    {
        $f = [
            'mien' => self::region($in['mien'] ?? null),
            'thang' => self::month($in['thang'] ?? null),
            'loai' => in_array($in['loai'] ?? null, ['am', 'duong'], true) ? $in['loai'] : null,
            'q' => is_string($in['q'] ?? null) ? trim($in['q']) : '',
        ];
        $q = $this->eventsBase();
        if ($f['mien']) {
            $q->where('d.domain', $f['mien']);
        }
        if ($f['loai']) {
            $q->where('e.isLunar', $f['loai'] === 'am' ? 1 : 0);
        }
        if ($f['q'] !== '') {
            $q->where('e.name', 'like', self::like($f['q']));
        }
        $year = (int) date('Y');
        $out = collect();
        foreach ($q->get() as $e) {
            if ($f['thang']) {
                // đợt nào bắt đầu trong tháng đó, trong năm nay hoặc năm sau (đợt gần nhất sắp tới)
                $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $f['thang']));
                if ($from < new DateTimeImmutable('first day of this month')) {
                    $from = $from->modify('+1 year');
                }
                $last = $from->modify('last day of this month')->format('Y-m-d');
                $dates = array_values(array_filter($this->dates->upcoming($e, $from, 40), fn($x) => $x['start'] <= $last));
                if (!$dates) {
                    continue;
                }
            } else {
                $dates = $this->dates->upcoming($e, null, 1);
            }
            $out->push($this->eventCard($e, $dates));
        }
        $out = $out->sortBy(fn($c) => $c->start ?? '9999')->values();

        return ['items' => $this->paginate($out, $perPage, $page, route('events.index'), array_filter($f, fn($v) => $v !== null && $v !== '')), 'filters' => $f, 'total' => $out->count()];
    }

    public function event(string $slug): ?object
    {
        return $this->eventsBase()->where('e.slug', $slug)->first();
    }

    /** Khung ngày dương của 3 đợt tới (tài liệu mục 4.5). */
    public function eventDates(object $e, int $limit = 3): array
    {
        return array_map(fn($d) => $d + ['label' => self::range($d)], $this->dates->upcoming($e, null, $limit));
    }

    /** Lễ hội của điểm đến + lễ hội chung cả nước. */
    public function eventsOf(int $destinationId, int $limit = 6, ?int $exceptId = null): Collection
    {
        $q = $this->eventsBase()->where(fn($w) => $w->where('e.destinationId', $destinationId)->orWhereNull('e.destinationId'));
        if ($exceptId) {
            $q->where('e.eventId', '!=', $exceptId);
        }

        return $q->get()->map(fn($e) => $this->eventCard($e, $this->dates->upcoming($e, null, 1)))
            ->sortBy(fn($c) => $c->start ?? '9999')->take($limit)->values();
    }

    /** Lịch tháng: ngày (1..31) => danh sách lễ hội bắt đầu hoặc đang diễn ra trong ngày đó. */
    public function eventCalendar(int $year, int $month): array
    {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month');
        $days = [];
        foreach ($this->eventsBase()->get() as $e) {
            foreach ($this->dates->upcoming($e, $first, 40) as $d) {
                if ($d['start'] > $last->format('Y-m-d')) {
                    continue;
                }
                $s = max(new DateTimeImmutable($d['start']), $first);
                $en = min(new DateTimeImmutable($d['end']), $last);
                for ($x = $s; $x <= $en; $x = $x->modify('+1 day')) {
                    $days[(int) $x->format('j')][$e->slug] = ['name' => $e->name, 'url' => route('events.show', ['slug' => $e->slug]), 'approx' => $d['approx']];
                }
            }
        }

        return $days;
    }

    // ------------------------------------------------------------------ Lịch trình

    private function itinCard(object $i): object
    {
        return (object) [
            'kind' => 'itinerary',
            'slug' => $i->slug,
            'title' => $i->title,
            'badge' => $i->days . ' ngày',
            'meta' => self::REGIONS[$i->region ?? ''] ?? null,
            'text' => $i->summary,
            'image' => DiscoverImage::url($i->coverImage),
            'credit' => $i->imageCredit,
            'url' => route('itineraries.show', ['slug' => $i->slug]),
        ];
    }

    public function itineraries(array $in, int $page, int $perPage = 12): array
    {
        $f = [
            'mien' => self::region($in['mien'] ?? null),
            'songay' => is_numeric($in['songay'] ?? null) && (int) $in['songay'] >= 1 && (int) $in['songay'] <= 30 ? (int) $in['songay'] : null,
        ];
        $q = DB::table('tbl_itineraries')->where('reviewStatus', 'published');
        if ($f['mien']) {
            $q->where('region', $f['mien']);
        }
        if ($f['songay']) {
            $q->where('days', $f['songay']);
        }
        $total = (clone $q)->count();
        $items = $q->orderBy('days')->orderBy('title')->forPage($page, $perPage)->get()->map(fn($i) => $this->itinCard($i));

        return ['items' => new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => route('itineraries.index'), 'query' => array_filter($f, fn($v) => $v !== null)]), 'filters' => $f, 'total' => $total];
    }

    public function itineraryDays(): array
    {
        return DB::table('tbl_itineraries')->where('reviewStatus', 'published')->distinct()->orderBy('days')->pluck('days')->all();
    }

    /**
     * Lịch trình theo ngày; mỗi điểm dừng được giải ra tên, đường dẫn, tọa độ (nếu trỏ tới điểm đến/địa điểm/món/lễ hội).
     *
     * @return array{itinerary:object,days:array,points:array}|null
     */
    public function itinerary(string $slug): ?array
    {
        $i = DB::table('tbl_itineraries')->where('reviewStatus', 'published')->where('slug', $slug)->first();
        if (!$i) {
            return null;
        }
        $items = DB::table('tbl_itinerary_items')->where('itineraryId', $i->itineraryId)->orderBy('dayNo')->orderBy('orderNo')->get();
        $ids = fn(string $t) => $items->where('refType', $t)->pluck('refId')->filter()->unique()->all();
        $dest = DB::table('tbl_destinations')->where('isActive', 1)->whereIn('destinationId', $ids('destination'))->get()->keyBy('destinationId');
        $place = DB::table('tbl_external_places')->whereIn('placeId', $ids('place'))->get()->keyBy('placeId');
        $food = DB::table('tbl_foods')->where('reviewStatus', 'published')->whereIn('foodId', $ids('food'))->get()->keyBy('foodId');
        $event = DB::table('tbl_events')->where('reviewStatus', 'published')->whereIn('eventId', $ids('event'))->get()->keyBy('eventId');
        $exp = DB::table('tbl_experiences')->where('reviewStatus', 'published')->whereIn('experienceId', $ids('experience'))->get()->keyBy('experienceId');

        $days = [];
        $dayMinutes = [];
        $points = [];
        foreach ($items as $it) {
            $url = null;
            $lat = $lng = null;
            if ($it->refType === 'destination' && isset($dest[$it->refId])) {
                $d = $dest[$it->refId];
                $url = route('destination.show', ['slug' => $d->slug]);
                [$lat, $lng] = [(float) $d->latitude, (float) $d->longitude];
            } elseif ($it->refType === 'place' && isset($place[$it->refId])) {
                [$lat, $lng] = [(float) $place[$it->refId]->latitude, (float) $place[$it->refId]->longitude];
            } elseif ($it->refType === 'food' && isset($food[$it->refId])) {
                $url = route('foods.show', ['slug' => $food[$it->refId]->slug]);
            } elseif ($it->refType === 'event' && isset($event[$it->refId])) {
                $url = route('events.show', ['slug' => $event[$it->refId]->slug]);
            } elseif ($it->refType === 'experience' && isset($exp[$it->refId])) {
                $url = route('experiences.show', ['slug' => $exp[$it->refId]->slug]);
                if ($exp[$it->refId]->latitude !== null && $exp[$it->refId]->longitude !== null) {   // chỉ dùng tọa độ riêng đã xác minh
                    [$lat, $lng] = [(float) $exp[$it->refId]->latitude, (float) $exp[$it->refId]->longitude];
                }
            }
            $slot = !empty($it->slot) ? (string) $it->slot : null;
            $minutes = isset($it->durationMin) && $it->durationMin !== null ? (int) $it->durationMin : null;
            $row = [
                'label' => $it->label, 'note' => $it->note, 'refType' => $it->refType, 'url' => $url, 'lat' => $lat, 'lng' => $lng,
                'slot' => $slot, 'slotLabel' => ItineraryValidator::slotLabel($slot), 'durationMin' => $minutes,
            ];
            $days[$it->dayNo][] = $row;
            if ($minutes) {
                $dayMinutes[$it->dayNo] = ($dayMinutes[$it->dayNo] ?? 0) + $minutes;
            }
            if ($lat !== null) {
                $points[] = ['day' => (int) $it->dayNo, 'label' => $it->label, 'lat' => $lat, 'lng' => $lng];
            }
        }
        ksort($days);

        // Điểm đến liên quan: điểm đến trực tiếp + điểm đến của món/lễ hội trong lịch trình (dùng cho số liệu và "Tour khớp")
        $destIds = collect($dest->keys())->merge($food->pluck('destinationId'))->merge($event->pluck('destinationId'))
            ->filter()->map(fn($v) => (int) $v)->unique()->values()->all();

        return [
            'itinerary' => $i,
            'days' => $days,
            'dayMinutes' => $dayMinutes,
            'points' => $points,
            'destIds' => $destIds,
            'stats' => $this->itineraryStats($i, $items->where('refType', '!=', 'transport')->count(), $destIds),
        ];
    }

    /** 4 ô số liệu đầu trang lịch trình: số ngày, điểm dừng, số tỉnh/thành, thời điểm đẹp (từ tbl_destination_months). */
    private function itineraryStats(object $i, int $stops, array $destIds): array
    {
        $provinces = 0;
        $months = [];
        if ($destIds) {
            $provinces = DB::table('tbl_destinations')->whereIn('destinationId', $destIds)->whereNotNull('province')->distinct()->count('province');
            $by = DB::table('tbl_destination_months')->whereIn('destinationId', $destIds)->get(['month', 'level'])->groupBy('month');
            for ($m = 1; $m <= 12; $m++) {
                $g = $by->get($m);
                $known = $g ? $g->whereIn('level', ['best', 'ok', 'avoid'])->count() : 0;
                if ($known > 0 && $g->where('level', 'best')->count() / $known >= 0.5) {
                    $months[] = $m;
                }
            }
        }

        return [
            'days' => (int) $i->days,
            'stops' => $stops,
            'provinces' => $provinces,
            'region' => self::REGIONS[$i->region ?? ''] ?? null,
            'best' => self::monthRuns($months),
        ];
    }

    /** [11,12,1,2,3,4] -> "Tháng 11–4" (gộp đợt vắt qua cuối năm); [3,4,9,10] -> "Tháng 3–4, 9–10". */
    private static function monthRuns(array $months): ?string
    {
        if (!$months) {
            return null;
        }
        sort($months);
        $runs = [];
        $s = $p = null;
        foreach ($months as $m) {
            if ($s === null) {
                $s = $p = $m;
            } elseif ($m === $p + 1) {
                $p = $m;
            } else {
                $runs[] = [$s, $p];
                $s = $p = $m;
            }
        }
        $runs[] = [$s, $p];
        if (count($runs) > 1 && $runs[0][0] === 1 && end($runs)[1] === 12) {
            $first = array_shift($runs);
            $last = array_pop($runs);
            $runs[] = [$last[0], $first[1]];
        }

        return 'Tháng ' . implode(', ', array_map(fn($r) => $r[0] === $r[1] ? (string) $r[0] : $r[0] . '–' . $r[1], $runs));
    }

    // ------------------------------------------------------------------ Chi tiết điểm đến (B3)

    /** 12 ô tháng: level best|ok|avoid|unknown kèm ghi chú và khí hậu. */
    public function months(int $destinationId): array
    {
        $m = DB::table('tbl_destination_months')->where('destinationId', $destinationId)->get()->keyBy('month');
        $c = DB::table('tbl_destination_climate')->where('destinationId', $destinationId)->get()->keyBy('month');
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $out[$i] = [
                'month' => $i,
                'level' => $m[$i]->level ?? 'unknown',
                'note' => $m[$i]->note ?? null,
                'tMax' => isset($c[$i]) ? round((float) $c[$i]->tMax) : null,
                'tMin' => isset($c[$i]) ? round((float) $c[$i]->tMin) : null,
                'rainMm' => isset($c[$i]) ? round((float) $c[$i]->rainMm) : null,
            ];
        }

        return $out;
    }

    /** Địa điểm đã duyệt quanh điểm đến, kèm khoảng cách (km) tính từ tọa độ, gần trước. */
    public function nearbyPlaces(object $d, int $limit = 8, float $radiusKm = 30): array
    {
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::bbox((float) $d->latitude, (float) $d->longitude, $radiusKm);
        $rows = DB::table('tbl_external_places')->where('reviewStatus', 'published')
            ->whereBetween('latitude', [$minLat, $maxLat])->whereBetween('longitude', [$minLng, $maxLng])
            ->orderByDesc('rank')->limit(300)->get();
        $out = [];
        foreach ($rows as $p) {
            $km = Geo::distanceKm((float) $d->latitude, (float) $d->longitude, (float) $p->latitude, (float) $p->longitude);
            if ($km <= $radiusKm) {
                $out[] = ['name' => $p->name, 'category' => $p->category, 'kindLabel' => \App\Support\PlaceKind::label($p->kind), 'address' => $p->address, 'km' => round($km, 1), 'rank' => (int) $p->rank];
            }
        }
        usort($out, fn($a, $b) => [$a['km'], -$a['rank']] <=> [$b['km'], -$b['rank']]);

        return array_slice($out, 0, $limit);
    }

    public function itinerariesOf(int $destinationId, int $limit = 4): Collection
    {
        return DB::table('tbl_itineraries as i')->where('i.reviewStatus', 'published')
            ->whereExists(fn($s) => $s->from('tbl_itinerary_items as it')->whereColumn('it.itineraryId', 'i.itineraryId')
                ->where('it.refType', 'destination')->where('it.refId', $destinationId))
            ->orderBy('i.days')->limit($limit)->get()->map(fn($i) => $this->itinCard($i));
    }

    // ------------------------------------------------------------------ Mùa đẹp (C2)

    /**
     * Ma trận điểm đến x 12 tháng cho trang "Nên đi tháng nào" và API /seasons/matrix.
     * Chỉ lấy điểm đến đang bật và đã có ít nhất một tháng được đánh giá. level: best | ok | avoid | unknown.
     *
     * @return list<array{slug:string,name:string,province:string,domain:string,url:string,months:array<int,string>}>
     */
    public function seasonMatrix(?string $region = null): array
    {
        $region = self::region($region);
        $q = DB::table('tbl_destinations')->where('isActive', 1);
        if ($region) {
            $q->where('domain', $region);
        }
        $dests = $q->orderBy('domain')->orderBy('name')->get(['destinationId', 'slug', 'name', 'province', 'domain']);
        $levels = DB::table('tbl_destination_months')->whereIn('destinationId', $dests->pluck('destinationId'))
            ->get(['destinationId', 'month', 'level'])->groupBy('destinationId');

        $rows = [];
        foreach ($dests as $d) {
            if (!isset($levels[$d->destinationId])) {
                continue;
            }
            $byMonth = $levels[$d->destinationId]->pluck('level', 'month');
            $months = [];
            for ($i = 1; $i <= 12; $i++) {
                $months[$i] = $byMonth[$i] ?? 'unknown';
            }
            $rows[] = [
                'slug' => $d->slug,
                'name' => $d->name,
                'province' => $d->province,
                'domain' => $d->domain,
                'url' => route('destination.show', ['slug' => $d->slug]),
                'months' => $months
            ];
        }

        return $rows;
    }

    public static function validRegion(mixed $v): ?string
    {
        return is_string($v) ? self::region($v) : null;
    }

    public static function validMonth(mixed $v): ?int
    {
        return self::month($v);
    }
}
