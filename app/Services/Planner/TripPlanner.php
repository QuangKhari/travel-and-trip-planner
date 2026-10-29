<?php

namespace App\Services\Planner;

use App\Services\Content\TravelAdvice;
use App\Services\Content\TripTray;
use App\Services\EventDateService;
use App\Support\Geo;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tuần 5-7: Trip Planner. Dùng chung cho trang web (PlannerController) và API (Api\V1\TripController).
 *
 * Mô hình dữ liệu: tbl_trips (chuyến) + tbl_trip_items (mục: điểm đến, món, lễ hội, trải nghiệm, tour; dayNo null = chưa xếp).
 * Khay chuyến đi (TripTray) là một chuyến nháp tên cố định; Planner không bao giờ sửa hay liệt kê nó như một chuyến thường.
 * Quãng đường: đường chim bay x ROAD_FACTOR, tốc độ trung bình SPEED_KMH (ước lượng, ghi rõ trên giao diện).
 */
class TripPlanner
{
    public const TYPES = ['destination', 'place', 'food', 'event', 'experience', 'tour'];
    /** Mục tự thêm (không trỏ tới nội dung): ghi chú/lưu trú/ăn uống tự nhập, và di chuyển. refId = null, tên nằm ở cột label. */
    public const CUSTOM_TYPES = ['note', 'transport'];
    public const SEARCH_TYPES = ['destination', 'place', 'food', 'experience', 'event', 'tour'];
    /** Nguồn tọa độ của mục tự thêm: dán link/tọa độ, bấm trên bản đồ, tìm ngoài Travela (dành cho giai đoạn sau). */
    public const POSITION_SOURCES = ['link', 'map', 'search'];
    public const SLOTS = ['morning', 'noon', 'afternoon', 'evening'];
    public const MAX_DAYS = 14;
    public const MAX_ITEMS = 80;
    public const ROAD_FACTOR = 1.3;
    public const SPEED_KMH = 40;
    public const DAY_ITEMS_WARN = 6;
    public const DAY_TRAVEL_WARN_MIN = 300;

    /** Thứ tự trong ngày khi tự xếp: điểm đến, trải nghiệm, lễ hội, món ăn, tour. */
    private const WEIGHT = ['destination' => 0, 'place' => 1, 'experience' => 2, 'event' => 3, 'food' => 4, 'tour' => 5];

    public function __construct(private EventDateService $dates, private TravelAdvice $advice) {}

    // ------------------------------------------------------------------ Luật kiểm tra dữ liệu (dùng chung web + API)

    public static function tripRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160', 'not_in:' . TripTray::TITLE],
            'startDate' => ['nullable', 'date'],
            'days' => ['required', 'integer', 'between:1,' . self::MAX_DAYS],
            'groupSize' => ['required', 'integer', 'between:1,50'],
            'budget' => ['nullable', 'numeric', 'between:0,100000000000'],
            'destinationId' => ['nullable', 'integer', 'exists:tbl_destinations,destinationId'],
        ];
    }

    public static function itemRules(): array
    {
        return [
            'items' => ['array', 'max:' . self::MAX_ITEMS],
            'items.*.type' => ['required', 'in:' . implode(',', array_merge(self::TYPES, self::CUSTOM_TYPES))],
            'items.*.refId' => ['nullable', 'integer', 'min:1'],   // bắt buộc với mục nội dung: save() kiểm tra, mục tự thêm thì không có
            'items.*.label' => ['nullable', 'string', 'max:120'],
            // Vị trí chỉ có nghĩa với mục tự thêm (mục nội dung lấy tọa độ từ kho, giá trị gửi lên bị bỏ qua); phải có đủ cặp lat+lng, save() kiểm tra
            'items.*.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'items.*.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'items.*.source' => ['nullable', 'in:' . implode(',', self::POSITION_SOURCES)],
            'items.*.day' => ['nullable', 'integer', 'between:1,' . self::MAX_DAYS],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'items.*.cost' => ['nullable', 'numeric', 'between:0,100000000000'],
            'items.*.slot' => ['nullable', 'in:' . implode(',', self::SLOTS)],
            'items.*.reasons' => ['nullable', 'array', 'max:8'],
            'items.*.reasons.*' => ['string', 'max:200'],
        ];
    }

    // ------------------------------------------------------------------ Xếp ngày (hàm thuần)

    /**
     * Chia các mục vào $days ngày: gom theo điểm đến (cụm), cụm xuất hiện trước đi trước, mục không có điểm đến xếp cuối;
     * trong cụm: điểm đến, trải nghiệm, lễ hội, món, tour; rồi cắt liền mạch thành các ngày có số mục gần bằng nhau.
     *
     * @param list<array{key:string,type:string,cluster:?int}> $items
     * @return array<string,array{day:int,order:int}>  key => vị trí
     */
    public static function distribute(array $items, int $days): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $clusters = [];
        foreach ($items as $i => $it) {
            $k = ($it['cluster'] ?? null) === null ? 'free' : 'c' . $it['cluster'];
            $clusters[$k][] = $i;
        }
        if (isset($clusters['free'])) {
            $free = $clusters['free'];
            unset($clusters['free']);
            $clusters['free'] = $free;
        }

        $flat = [];
        foreach ($clusters as $idx) {
            usort($idx, fn($a, $b) => (self::WEIGHT[$items[$a]['type']] ?? 9) <=> (self::WEIGHT[$items[$b]['type']] ?? 9));
            foreach ($idx as $i) {
                $flat[] = $items[$i]['key'];
            }
        }

        $n = count($flat);
        $base = intdiv($n, $days);
        $extra = $n % $days;
        $out = [];
        $pos = 0;
        for ($d = 1; $d <= $days; $d++) {
            $take = $base + ($d <= $extra ? 1 : 0);
            for ($o = 0; $o < $take; $o++) {
                $out[$flat[$pos++]] = ['day' => $d, 'order' => $o];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ Đọc dữ liệu

    /** Chuyến của người dùng (không gồm khay). */
    public function owned(int $userId, int $tripId): ?object
    {
        return DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $userId])->where('title', '!=', TripTray::TITLE)->first() ?: null;
    }

    public function trips(int $userId): array
    {
        return DB::table('tbl_trips as t')->where('t.userId', $userId)->where('t.title', '!=', TripTray::TITLE)
            ->selectRaw('t.*, (SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId) AS itemCount')
            ->orderByDesc(DB::raw('COALESCE(t.updatedAt, t.createdAt)'))->get()->all();
    }

    /** Các mục đang có trong khay: [['type'=>..., 'refId'=>...], ...] theo thứ tự thêm. */
    public function trayRefs(int $userId): array
    {
        $tray = DB::table('tbl_trips')->where(['userId' => $userId, 'title' => TripTray::TITLE, 'status' => 'draft'])->value('tripId');
        if (!$tray) {
            return [];
        }

        return DB::table('tbl_trip_items')->where('tripId', $tray)->orderBy('orderNo')->get(['refType', 'refId'])
            ->map(fn($r) => ['type' => $r->refType, 'refId' => (int) $r->refId])->all();
    }

    /**
     * Giải các tham chiếu thành dữ liệu hiển thị; bỏ qua nội dung không còn công khai (đã tắt/nháp/bị xóa).
     *
     * @param list<array{type:string,refId:int}> $refs
     * @return array<string,array>  khóa "type:refId"
     */
    public function resolve(array $refs): array
    {
        $ids = [];
        foreach ($refs as $r) {
            if (in_array($r['type'], self::TYPES, true)) {
                $ids[$r['type']][(int) $r['refId']] = true;
            }
        }
        $rows = [];
        $add = function (string $type, int $id, array $row) use (&$rows) {
            $rows[$type . ':' . $id] = $row + ['type' => $type, 'refId' => $id, 'slug' => null, 'destinationId' => null, 'price' => null, 'sub' => '', 'raw' => null, 'ownLat' => null, 'ownLng' => null];
        };

        if (!empty($ids['destination'])) {
            foreach (DB::table('tbl_destinations')->whereIn('destinationId', array_keys($ids['destination']))->where('isActive', 1)->get() as $d) {
                $add('destination', (int) $d->destinationId, ['name' => $d->name, 'slug' => $d->slug, 'sub' => (string) $d->province,
                    'destinationId' => (int) $d->destinationId, 'url' => route('destination.show', ['slug' => $d->slug])]);
            }
        }
        if (!empty($ids['place'])) {
            foreach (DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
                ->whereIn('p.placeId', array_keys($ids['place']))->where('p.reviewStatus', 'published')->where('d.isActive', 1)
                ->get(['p.placeId', 'p.name', 'p.kind', 'p.destinationId', 'p.latitude', 'p.longitude', 'd.slug as destSlug']) as $p) {
                $add('place', (int) $p->placeId, ['name' => $p->name, 'sub' => (string) (\App\Support\PlaceKind::label($p->kind) ?? ''),
                    'destinationId' => (int) $p->destinationId, 'url' => route('destination.show', ['slug' => $p->destSlug]) . '#gan-day',
                    'ownLat' => $p->latitude !== null ? (float) $p->latitude : null, 'ownLng' => $p->longitude !== null ? (float) $p->longitude : null]);
            }
        }
        if (!empty($ids['food'])) {
            foreach (DB::table('tbl_foods')->whereIn('foodId', array_keys($ids['food']))->where('reviewStatus', 'published')->get() as $f) {
                $add('food', (int) $f->foodId, ['name' => $f->name, 'slug' => $f->slug, 'sub' => (string) $f->category,
                    'destinationId' => $f->destinationId ? (int) $f->destinationId : null, 'url' => route('foods.show', ['slug' => $f->slug])]);
            }
        }
        if (!empty($ids['event'])) {
            foreach (DB::table('tbl_events')->whereIn('eventId', array_keys($ids['event']))->where('reviewStatus', 'published')->get() as $e) {
                $add('event', (int) $e->eventId, ['name' => $e->name, 'slug' => $e->slug, 'sub' => 'Lễ hội',
                    'destinationId' => $e->destinationId ? (int) $e->destinationId : null, 'url' => route('events.show', ['slug' => $e->slug]), 'raw' => $e]);
            }
        }
        if (!empty($ids['experience'])) {
            foreach (DB::table('tbl_experiences')->whereIn('experienceId', array_keys($ids['experience']))->where('reviewStatus', 'published')->get() as $x) {
                $add('experience', (int) $x->experienceId, ['name' => $x->name, 'slug' => $x->slug, 'sub' => (string) ($x->duration ?? ''),
                    'destinationId' => $x->destinationId ? (int) $x->destinationId : null, 'url' => route('experiences.show', ['slug' => $x->slug]),
                    'ownLat' => ($x->latitude ?? null) !== null ? (float) $x->latitude : null, 'ownLng' => ($x->longitude ?? null) !== null ? (float) $x->longitude : null]);
            }
        }
        if (!empty($ids['tour'])) {
            foreach (DB::table('tbl_tours')->whereIn('tourId', array_keys($ids['tour']))->get() as $t) {
                $add('tour', (int) $t->tourId, ['name' => $t->title,
                    'sub' => date('d/m/Y', strtotime($t->startDate)) . ' – ' . date('d/m/Y', strtotime($t->endDate)),
                    'destinationId' => !empty($t->destinationId) ? (int) $t->destinationId : null, 'price' => (float) $t->priceAdult,
                    'url' => route('tour-detail', ['id' => $t->tourId])]);
            }
        }

        $dIds = array_values(array_unique(array_filter(array_column($rows, 'destinationId'))));
        $dest = $dIds ? DB::table('tbl_destinations')->whereIn('destinationId', $dIds)->get(['destinationId', 'name', 'latitude', 'longitude'])->keyBy('destinationId') : collect();
        foreach ($rows as $k => $r) {
            $d = $r['destinationId'] ? $dest->get($r['destinationId']) : null;
            $rows[$k]['destName'] = $d?->name;
            $rows[$k]['lat'] = $r['ownLat'] ?? ($d && $d->latitude !== null ? (float) $d->latitude : null);   // địa điểm/trải nghiệm có tọa độ riêng thì dùng, không thì lấy tâm điểm đến
            $rows[$k]['lng'] = $r['ownLng'] ?? ($d && $d->longitude !== null ? (float) $d->longitude : null);
        }

        return $this->enrich($rows);
    }

    /**
     * Thêm thông tin hiển thị cho thẻ địa điểm và thẻ chi tiết trên bản đồ: ảnh, địa chỉ, website, lời giới thiệu ngắn.
     * Chỉ lấy dữ liệu Travela đã có; không có thì để null (giao diện tự bỏ phần đó, không bịa).
     *
     * @param array<string,array> $rows
     * @return array<string,array>
     */
    private function enrich(array $rows): array
    {
        $spec = [
            'destination' => ['tbl_destinations', 'destinationId', ['coverImage', 'description']],
            'food' => ['tbl_foods', 'foodId', ['coverImage', 'description']],
            'event' => ['tbl_events', 'eventId', ['coverImage', 'description']],
            'experience' => ['tbl_experiences', 'experienceId', ['coverImage', 'description']],
            'place' => ['tbl_external_places', 'placeId', ['address', 'website', 'description']],
        ];
        $ids = [];
        foreach ($rows as $r) {
            if (isset($spec[$r['type']])) {
                $ids[$r['type']][] = (int) $r['refId'];
            }
        }
        $extra = [];
        foreach ($ids as $type => $list) {
            [$table, $pk, $cols] = $spec[$type];
            foreach (DB::table($table)->whereIn($pk, $list)->get(array_merge([$pk], $cols)) as $x) {
                $extra[$type . ':' . $x->{$pk}] = $x;
            }
        }
        foreach ($rows as $k => $r) {
            $x = $extra[$k] ?? null;
            $desc = $x && !empty($x->description) ? trim(preg_replace('/\s+/u', ' ', strip_tags((string) $x->description)) ?? '') : '';
            $site = $x && !empty($x->website ?? null) ? (string) $x->website : null;
            $rows[$k]['image'] = $x && !empty($x->coverImage ?? null) ? \App\Support\DiscoverImage::url((string) $x->coverImage) : null;
            $rows[$k]['address'] = $x && !empty($x->address ?? null) ? (string) $x->address : null;
            $rows[$k]['website'] = $site && preg_match('#^https?://#i', $site) ? $site : null;
            $rows[$k]['blurb'] = $desc !== '' ? Str::limit($desc, 260) : null;
        }

        return $rows;
    }

    /** Các mục của một chuyến, đã giải nghĩa, kèm ngày/thứ tự/ghi chú/chi phí. */
    public function tripItems(int $tripId): array
    {
        $rows = DB::table('tbl_trip_items')->where('tripId', $tripId)->orderBy('dayNo')->orderBy('orderNo')->get();
        $res = $this->resolve($rows->filter(fn($r) => !in_array($r->refType, self::CUSTOM_TYPES, true))->map(fn($r) => ['type' => $r->refType, 'refId' => (int) $r->refId])->all());
        $out = [];
        foreach ($rows as $r) {
            if (in_array($r->refType, self::CUSTOM_TYPES, true)) {
                $lat = ($r->latitude ?? null) !== null ? (float) $r->latitude : null;
                $lng = ($r->longitude ?? null) !== null ? (float) $r->longitude : null;
                if ($lat === null || $lng === null) {
                    $lat = $lng = null;
                }
                $out[] = [
                    'type' => $r->refType, 'refId' => null, 'slug' => null, 'destinationId' => null, 'price' => null, 'sub' => '', 'raw' => null,
                    'ownLat' => $lat, 'ownLng' => $lng, 'destName' => null, 'lat' => $lat, 'lng' => $lng,
                    'source' => $lat !== null ? ($r->source ?? null) : null,
                    'url' => $lat !== null ? 'https://www.google.com/maps?q=' . $lat . ',' . $lng : null,
                    'name' => (string) ($r->label ?: ($r->refType === 'transport' ? 'Di chuyển' : 'Ghi chú')),
                    'day' => $r->dayNo !== null ? (int) $r->dayNo : null,
                    'order' => (int) $r->orderNo,
                    'note' => $r->note,
                    'cost' => $r->cost !== null ? (float) $r->cost : null,
                    'slot' => $r->slot ?? null,
                    'reasons' => [],
                ];
                continue;
            }
            $k = $r->refType . ':' . $r->refId;
            if (!isset($res[$k])) {
                continue;
            }
            $out[] = array_merge($res[$k], [
                'day' => $r->dayNo !== null ? (int) $r->dayNo : null,
                'order' => (int) $r->orderNo,
                'note' => $r->note,
                'cost' => $r->cost !== null ? (float) $r->cost : null,
                'slot' => $r->slot ?? null,
                'reasons' => !empty($r->reasons) ? (json_decode((string) $r->reasons, true) ?: []) : [],
            ]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ Tạo / lưu / xóa / chia sẻ

    /** Tạo chuyến mới; $fromTray = true thì lấy các mục trong khay và tự chia vào các ngày. */
    public function create(int $userId, array $f, bool $fromTray = false): int
    {
        return DB::transaction(function () use ($userId, $f, $fromTray) {
            $tripId = (int) DB::table('tbl_trips')->insertGetId([
                'userId' => $userId,
                'title' => $f['title'],
                'startDate' => $f['startDate'] ?? null,
                'days' => (int) $f['days'],
                'budget' => $f['budget'] ?? null,
                'groupSize' => (int) $f['groupSize'],
                'destinationId' => !empty($f['destinationId']) ? (int) $f['destinationId'] : null,
                'status' => 'planned',
                'createdAt' => now(),
                'updatedAt' => now(),
            ]);

            if ($fromTray) {
                $refs = $this->trayRefs($userId);
                $res = $this->resolve($refs);
                $list = [];
                foreach ($refs as $r) {
                    $k = $r['type'] . ':' . $r['refId'];
                    if (isset($res[$k]) && !isset($list[$k])) {
                        $list[$k] = ['key' => $k, 'type' => $r['type'], 'cluster' => $res[$k]['destinationId']];
                    }
                }
                $pos = self::distribute(array_values($list), (int) $f['days']);
                $rows = [];
                foreach ($list as $k => $it) {
                    $rows[] = ['tripId' => $tripId, 'refType' => $it['type'], 'refId' => (int) explode(':', $k)[1],
                        'dayNo' => $pos[$k]['day'] ?? null, 'orderNo' => $pos[$k]['order'] ?? 0];
                }
                if ($rows) {
                    DB::table('tbl_trip_items')->insert($rows);
                }
            }

            return $tripId;
        });
    }

    /**
     * Tuần 4: dùng một lịch trình mẫu làm chuyến đi của người dùng ("Dùng làm chuyến đi của tôi").
     * Chỉ lịch trình đã xuất bản và chỉ đi qua MỘT điểm đến (ItineraryScope). Mục đã xóa/chưa duyệt, mục di chuyển và mục chỉ có nhãn bị bỏ qua.
     *
     * @return array{tripId:int,imported:int,skipped:int}
     * @throws \DomainException với thông báo tiếng Việt hiển thị được cho người dùng
     */
    public function createFromItinerary(int $userId, string $slug): array
    {
        $it = DB::table('tbl_itineraries')->where('slug', $slug)->where('reviewStatus', 'published')->first();
        if (!$it) {
            throw new \DomainException('Không tìm thấy lịch trình.');
        }
        $destId = (new \App\Services\Content\ItineraryScope())->singleDestination((int) $it->itineraryId);
        if (!$destId) {
            throw new \DomainException('Lịch trình này đi qua nhiều điểm đến hoặc chưa gắn rõ điểm đến nên chưa thể chuyển thành chuyến đi.');
        }
        $rows = DB::table('tbl_itinerary_items')->where('itineraryId', $it->itineraryId)->orderBy('dayNo')->orderBy('orderNo')->get();
        $refs = [];
        foreach ($rows as $r) {
            if ($r->refType && $r->refId && in_array($r->refType, self::TYPES, true)) {
                $refs[] = ['type' => $r->refType, 'refId' => (int) $r->refId];
            }
        }
        $valid = $this->resolve($refs);
        $days = max(1, min(self::MAX_DAYS, (int) $it->days));

        return DB::transaction(function () use ($userId, $it, $destId, $rows, $valid, $days) {
            $tripId = (int) DB::table('tbl_trips')->insertGetId([
                'userId' => $userId, 'kind' => 'trip', 'destinationId' => $destId, 'title' => mb_substr((string) $it->title, 0, 160),
                'days' => $days, 'groupSize' => 1, 'status' => 'planned', 'source' => 'itinerary', 'createdAt' => now(), 'updatedAt' => now(),
            ]);
            $seen = [];
            $order = [];
            $out = [];
            $skipped = 0;
            foreach ($rows as $r) {
                $k = $r->refType . ':' . $r->refId;
                if (!$r->refType || !$r->refId || !isset($valid[$k]) || isset($seen[$k])) {
                    $skipped++;
                    continue;
                }
                $seen[$k] = true;
                $day = min($days, max(1, (int) $r->dayNo));
                $order[$day] = ($order[$day] ?? -1) + 1;
                $out[] = ['tripId' => $tripId, 'refType' => $r->refType, 'refId' => (int) $r->refId, 'dayNo' => $day, 'orderNo' => $order[$day],
                    'slot' => in_array($r->slot ?? null, self::SLOTS, true) ? $r->slot : null,
                    'durationMin' => !empty($r->durationMin) ? (int) $r->durationMin : null,
                    'note' => $r->note ? mb_substr(trim((string) $r->note), 0, 255) : null];
            }
            if ($out) {
                DB::table('tbl_trip_items')->insert($out);
            }

            return ['tripId' => $tripId, 'imported' => count($out), 'skipped' => $skipped];
        });
    }

    /**
     * Tuần 12: "Lên kế hoạch quanh tour này". Tạo chuyến đi có sẵn ngày khởi hành, số ngày, điểm đến và chính tour đó;
     * người dùng thêm món, lễ hội, trải nghiệm quanh tour rồi quay lại đặt tour. Chỉ dùng tour còn chỗ và chưa khởi hành.
     *
     * @return array{tripId:int,title:string}
     * @throws \DomainException
     */
    public function createFromTour(int $userId, int $tourId, int $groupSize = 2): array
    {
        $t = DB::table('tbl_tours')->where('tourId', $tourId)->where('availability', 1)->where('quantity', '>', 0)->first();
        if (!$t || strtotime((string) $t->startDate) < strtotime(date('Y-m-d'))) {
            throw new \DomainException('Tour này không còn nhận đặt chỗ hoặc đã khởi hành.');
        }
        $days = TourFit::tourDays($t->time ?? null, $t->startDate, $t->endDate ?? null) ?? 1;
        $days = max(1, min(self::MAX_DAYS, $days));
        $group = max(1, min(50, $groupSize));
        $destId = !empty($t->destinationId) ? (int) $t->destinationId : null;

        return DB::transaction(function () use ($userId, $t, $days, $group, $destId) {
            $title = mb_substr('Quanh tour: ' . $t->title, 0, 160);
            $tripId = (int) DB::table('tbl_trips')->insertGetId([
                'userId' => $userId, 'kind' => 'trip', 'destinationId' => $destId, 'title' => $title, 'startDate' => $t->startDate,
                'days' => $days, 'groupSize' => $group, 'status' => 'planned', 'source' => 'tour', 'month' => (int) date('n', strtotime((string) $t->startDate)),
                'createdAt' => now(), 'updatedAt' => now(),
            ]);
            $rows = [];
            $order = 0;
            if ($destId) {
                $rows[] = ['tripId' => $tripId, 'refType' => 'destination', 'refId' => $destId, 'dayNo' => 1, 'orderNo' => $order++];
            }
            $rows[] = ['tripId' => $tripId, 'refType' => 'tour', 'refId' => (int) $t->tourId, 'dayNo' => 1, 'orderNo' => $order,
                'cost' => round((float) $t->priceAdult * $group, 2)];
            DB::table('tbl_trip_items')->insert($rows);

            return ['tripId' => $tripId, 'title' => $title];
        });
    }

    /**
     * Lưu thông tin chuyến + toàn bộ danh sách mục (ghi đè). Thứ tự trong mảng $items quyết định orderNo trong từng ngày.
     * Trả false nếu chuyến không thuộc người dùng.
     */
    public function save(int $userId, int $tripId, array $f, array $items): bool
    {
        // Chủ chuyến hoặc người được mời quyền "chỉnh sửa" mới lưu được
        $acc = app(TripSharing::class)->editable($userId, $tripId);
        if (!$acc) {
            return false;
        }
        // Khóa lạc quan: nếu client báo phiên bản đã tải mà trên máy chủ đã khác, có người khác vừa lưu: không ghi đè
        if (array_key_exists('rev', $f) && $f['rev'] !== null && (int) $f['rev'] !== (int) ($acc['trip']->rev ?? 0)) {
            $by = app(TripSharing::class)->revision($tripId)['by'];
            throw new TripConflict((int) ($acc['trip']->rev ?? 0), $by);
        }
        $days = (int) $f['days'];
        foreach ($items as $i => $it) {
            if (in_array($it['type'], self::CUSTOM_TYPES, true)) {
                if (trim((string) ($it['label'] ?? '')) === '') {
                    throw ValidationException::withMessages(["items.$i.label" => 'Mục tự thêm cần có tên.']);
                }
                if ((self::hasCoord($it['lat'] ?? null)) !== (self::hasCoord($it['lng'] ?? null))) {
                    throw ValidationException::withMessages(["items.$i.lat" => 'Vị trí cần đủ cả vĩ độ và kinh độ.']);
                }
            } elseif (empty($it['refId'])) {
                throw ValidationException::withMessages(["items.$i.refId" => 'Mục thiếu mã tham chiếu.']);
            }
        }
        $refs = [];
        foreach ($items as $i) {
            if (!in_array($i['type'], self::CUSTOM_TYPES, true)) {
                $refs[] = ['type' => $i['type'], 'refId' => (int) $i['refId']];
            }
        }
        $valid = $this->resolve($refs);
        $prevExternal = DB::table('tbl_trip_items')->where('tripId', $tripId)->where('source', 'search')->pluck('label')->map(fn($l) => mb_strtolower((string) $l))->all();

        DB::transaction(function () use ($tripId, $f, $items, $days, $valid, $userId) {
            $upd = [
                'title' => $f['title'],
                'startDate' => $f['startDate'] ?? null,
                'days' => $days,
                'budget' => $f['budget'] ?? null,
                'groupSize' => (int) $f['groupSize'],
                'updatedAt' => now(),
                'rev' => DB::raw('rev + 1'),
                'lastEditorId' => $userId,
            ];
            if (array_key_exists('destinationId', $f)) {
                $upd['destinationId'] = !empty($f['destinationId']) ? (int) $f['destinationId'] : null;
            }
            if (array_key_exists('rainPlan', $f)) {
                $clean = RainPlan::sanitize($f['rainPlan'], $days, (int) config('planner.rain_plan.max_per_day', 4));
                $upd['rainPlan'] = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
            }
            DB::table('tbl_trips')->where('tripId', $tripId)->update($upd);
            DB::table('tbl_trip_items')->where('tripId', $tripId)->delete();

            $seen = [];
            $counter = [];
            $rows = [];
            foreach ($items as $it) {
                $custom = in_array($it['type'], self::CUSTOM_TYPES, true);
                if (!$custom) {
                    $k = $it['type'] . ':' . (int) $it['refId'];
                    if (!isset($valid[$k]) || isset($seen[$k])) {
                        continue;
                    }
                    $seen[$k] = true;
                }
                $day = isset($it['day']) && $it['day'] !== null && $it['day'] !== '' ? min($days, max(1, (int) $it['day'])) : null;
                $ck = $day === null ? 'x' : (string) $day;
                $counter[$ck] = ($counter[$ck] ?? -1) + 1;
                $pos = $custom && self::hasCoord($it['lat'] ?? null) && self::hasCoord($it['lng'] ?? null);
                $rows[] = [
                    'tripId' => $tripId,
                    'refType' => $it['type'],
                    'refId' => $custom ? null : (int) $it['refId'],
                    'label' => $custom ? mb_substr(trim((string) $it['label']), 0, 120) : null,
                    'latitude' => $pos ? round((float) $it['lat'], 7) : null,
                    'longitude' => $pos ? round((float) $it['lng'], 7) : null,
                    'source' => $pos ? (in_array($it['source'] ?? null, self::POSITION_SOURCES, true) ? $it['source'] : 'map') : null,
                    'dayNo' => $day,
                    'orderNo' => $counter[$ck],
                    'note' => isset($it['note']) && trim((string) $it['note']) !== '' ? mb_substr(trim((string) $it['note']), 0, 255) : null,
                    'cost' => isset($it['cost']) && $it['cost'] !== '' && $it['cost'] !== null ? (float) $it['cost'] : null,
                    'slot' => isset($it['slot']) && in_array($it['slot'], self::SLOTS, true) ? $it['slot'] : null,
                    'reasons' => !empty($it['reasons']) && is_array($it['reasons']) ? json_encode(array_values(array_map('strval', array_slice($it['reasons'], 0, 8))), JSON_UNESCAPED_UNICODE) : null,
                ];
            }
            if ($rows) {
                DB::table('tbl_trip_items')->insert($rows);
            }
        });

        // Địa điểm người dùng thêm từ ngoài kho (tìm trên bản đồ) = nhu cầu mà kho Travela chưa có: ghi vào sổ cho biên tập viên.
        // Chỉ ghi mục MỚI của lần lưu này (lưu lại nhiều lần không đếm trùng) và không bao giờ làm hỏng việc lưu chuyến.
        try {
            $log = app(ContentRequests::class);
            foreach ($items as $it) {
                if (in_array($it['type'], self::CUSTOM_TYPES, true) && ($it['source'] ?? null) === 'search' && !in_array(mb_strtolower(trim((string) ($it['label'] ?? ''))), $prevExternal, true)) {
                    $log->record((string) $it['label'], 'place');
                }
            }
        } catch (\Throwable $e) {
            // bỏ qua: sổ nhu cầu chỉ là thống kê phụ
        }

        return true;
    }

    /** Đổi trạng thái chuyến (draft|planned|done). false nếu không phải chuyến của người dùng hoặc trạng thái không hợp lệ. */
    public function setStatus(int $userId, int $tripId, string $status): bool
    {
        if (!in_array($status, ['draft', 'planned', 'done'], true) || !$this->owned($userId, $tripId)) {
            return false;
        }
        DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $userId])->update(['status' => $status, 'updatedAt' => now()]);

        return true;
    }

    public function delete(int $userId, int $tripId): bool
    {
        if (!$this->owned($userId, $tripId)) {
            return false;
        }
        DB::table('tbl_trips')->where('tripId', $tripId)->delete();   // mục xóa theo khóa ngoại cascade

        return true;
    }

    /** Bật/tắt liên kết chia sẻ chỉ đọc. Trả token (hoặc null khi tắt); false nếu không phải chuyến của người dùng. */
    public function share(int $userId, int $tripId, bool $on): string|null|false
    {
        $trip = $this->owned($userId, $tripId);
        if (!$trip) {
            return false;
        }
        $token = $on ? ($trip->shareToken ?: Str::random(32)) : null;
        DB::table('tbl_trips')->where('tripId', $tripId)->update(['shareToken' => $token]);

        return $token;
    }

    public function bySharedToken(string $token): ?object
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }

        return DB::table('tbl_trips')->where('shareToken', $token)->where('title', '!=', TripTray::TITLE)->first() ?: null;
    }

    // ------------------------------------------------------------------ Tóm tắt (quãng đường, chi phí, cảnh báo)

    private static function fmtRange(array $d): string
    {
        $s = new DateTimeImmutable($d['start']);
        $e = new DateTimeImmutable($d['end']);
        if (!empty($d['approx'])) {
            return 'khoảng tháng ' . $s->format('n/Y');
        }
        if ($d['start'] === $d['end']) {
            return $s->format('d/m/Y');
        }

        return $s->format('Y') === $e->format('Y') ? $s->format('d/m') . '–' . $e->format('d/m/Y') : $s->format('d/m/Y') . '–' . $e->format('d/m/Y');
    }

    /** @return array{0:?string,1:?string} [ngày diễn ra tiếp theo, cảnh báo nếu ngoài thời gian chuyến đi] */
    private function eventInfo(array $it, ?DateTimeImmutable $start, ?DateTimeImmutable $end): array
    {
        if ($it['type'] !== 'event' || empty($it['raw'])) {
            return [null, null];
        }
        $next = $this->dates->upcoming($it['raw'], $start ?? new DateTimeImmutable('today'), 1)[0] ?? null;
        if (!$next) {
            return [null, null];
        }
        $text = self::fmtRange($next);
        $warn = null;
        if ($start && $end) {
            $s = new DateTimeImmutable($next['start']);
            $e = new DateTimeImmutable($next['end']);
            if ($s > $end || $e < $start) {
                $warn = 'Lễ hội "' . $it['name'] . '" diễn ra ' . $text . ', ngoài thời gian chuyến đi.';
            }
        }

        return [$text, $warn];
    }

    /** Dữ liệu đầy đủ cho trang sửa, trang chia sẻ và API. */
    public function payload(object $trip): array
    {
        $days = max(1, (int) ($trip->days ?: 1));
        $start = $trip->startDate ? new DateTimeImmutable($trip->startDate) : null;
        $end = $start ? $start->modify('+' . ($days - 1) . ' days') : null;

        $warnings = [];
        $contentWarnings = [];   // cảnh báo về nội dung (lễ hội ngoài ngày đi, tháng nên cân nhắc): giao diện không tự tính được, cộng thêm vào cảnh báo cấu trúc
        $items = [];
        foreach ($this->tripItems((int) $trip->tripId) as $it) {
            [$when, $warn] = $this->eventInfo($it, $start, $end);
            $it['when'] = $when;
            unset($it['raw']);
            if ($warn) {
                $warnings[] = $warn;
                $contentWarnings[] = $warn;
            }
            $items[] = $it;
        }

        // Tuần 12: "vì sao hợp" cho từng mục + ngữ cảnh mưa của tháng đi + phương án dự phòng đã lưu
        $insights = app(ItemInsights::class);
        $ins = $insights->forTrip($trip, $items);
        foreach ($items as &$itx) {
            $x = $ins[$itx['type'] . ':' . ($itx['refId'] ?? 0)] ?? null;
            $itx['fit'] = $x['fit'] ?? [];
            $itx['outdoor'] = $x['outdoor'] ?? null;
            $itx['src'] = $x['src'] ?? null;
        }
        unset($itx);
        $time = ItemInsights::timeContext($trip);
        $primaryDest = !empty($trip->destinationId) ? (int) $trip->destinationId : null;
        if ($primaryDest === null) {
            foreach ($items as $itx) {
                if (!empty($itx['destinationId'])) {
                    $primaryDest = (int) $itx['destinationId'];
                    break;
                }
            }
        }
        $rainCtx = null;
        if ($time['month'] && $primaryDest) {
            $rr = $insights->rainByDestination([$primaryDest], (int) $time['month'])[$primaryDest] ?? null;
            if ($rr) {
                $rainCtx = ['dest' => (string) DB::table('tbl_destinations')->where('destinationId', $primaryDest)->value('name'), 'month' => (int) $time['month'],
                    'rainy' => $rr['rainy'], 'rainyDays' => (int) round($rr['rainyDays'])];
            }
        }
        $rainPlan = app(RainPlan::class)->resolve($trip->rainPlan ?? null, $this);

        $byDay = [];
        for ($d = 1; $d <= $days; $d++) {
            $byDay[$d] = ['day' => $d, 'items' => [], 'legs' => [], 'km' => 0, 'minutes' => 0, 'cost' => 0.0];
        }
        $unassigned = [];
        foreach ($items as $it) {
            if ($it['day'] !== null && isset($byDay[$it['day']])) {
                $byDay[$it['day']]['items'][] = $it;
            } else {
                $unassigned[] = $it;
            }
        }

        foreach ($byDay as $d => $day) {
            $pts = array_values(array_filter($day['items'], fn($x) => $x['lat'] !== null && $x['lng'] !== null));
            $km = 0;
            $min = 0;
            for ($i = 1; $i < count($pts); $i++) {
                $straight = Geo::distanceKm($pts[$i - 1]['lat'], $pts[$i - 1]['lng'], $pts[$i]['lat'], $pts[$i]['lng']);
                if ($straight < 0.5) {
                    continue;   // cùng một khu: không tính đoạn đường
                }
                $road = $straight * self::ROAD_FACTOR;
                $m = (int) round($road / self::SPEED_KMH * 60);
                $byDay[$d]['legs'][] = ['from' => $pts[$i - 1]['name'], 'to' => $pts[$i]['name'], 'km' => (int) round($road), 'min' => $m];
                $km += $road;
                $min += $m;
            }
            $byDay[$d]['km'] = (int) round($km);
            $byDay[$d]['minutes'] = $min;
            $byDay[$d]['cost'] = array_sum(array_map(fn($x) => $x['cost'] ?? 0, $day['items']));

            $outdoorN = count(array_filter($day['items'], fn($x) => ($x['outdoor'] ?? null) === true));
            $byDay[$d]['outdoor'] = $outdoorN;
            if ($rainCtx && $rainCtx['rainy'] && $outdoorN >= 2 && empty($rainPlan[$d])) {
                $w = 'Ngày ' . $d . ' có ' . $outdoorN . ' mục ngoài trời, tháng ' . $rainCtx['month'] . ' ở ' . $rainCtx['dest'] . ' mưa nhiều (khoảng ' . $rainCtx['rainyDays'] . ' ngày mưa). Hãy chọn phương án dự phòng khi mưa.';
                $warnings[] = $w;
                $contentWarnings[] = $w;
            }
            if (count($day['items']) > self::DAY_ITEMS_WARN) {
                $warnings[] = 'Ngày ' . $d . ' có ' . count($day['items']) . ' mục, hơi dày.';
            }
            if ($min > self::DAY_TRAVEL_WARN_MIN) {
                $warnings[] = 'Ngày ' . $d . ' di chuyển khoảng ' . round($min / 60, 1) . ' giờ, nên bớt điểm hoặc chia sang ngày khác.';
            }
        }
        if ($unassigned) {
            $warnings[] = 'Còn ' . count($unassigned) . ' mục chưa xếp vào ngày nào.';
        }

        $cost = array_sum(array_map(fn($x) => $x['cost'] ?? 0, $items));
        $budget = $trip->budget !== null ? (float) $trip->budget : null;
        if ($budget !== null && $cost > $budget) {
            $warnings[] = 'Chi phí dự kiến ' . number_format($cost, 0, ',', '.') . 'đ vượt ngân sách ' . number_format($budget, 0, ',', '.') . 'đ.';
        }

        // Thời điểm đi: mức tháng của từng điểm đến trong khoảng ngày của chuyến.
        $adviceRows = [];
        if ($start && $end) {
            $destNames = [];
            foreach ($items as $it) {
                if ($it['destinationId']) {
                    $destNames[$it['destinationId']] = $it['destName'] ?: $it['name'];
                }
            }
            $months = [];
            for ($c = $start; $c <= $end; $c = $c->modify('+1 day')) {
                $months[(int) $c->format('n')] = true;
            }
            foreach (array_keys($months) as $mo) {
                foreach ($this->advice->verdicts(array_keys($destNames), $mo) as $destId => $v) {
                    $adviceRows[] = ['dest' => $destNames[$destId], 'month' => $mo, 'level' => $v['level'], 'label' => TravelAdvice::LABELS[$v['level']] ?? '', 'note' => $v['note']];
                    if ($v['level'] === 'avoid') {
                        $w = 'Tháng ' . $mo . ' ở ' . $destNames[$destId] . ' thuộc mức "nên cân nhắc"' . ($v['note'] ? ' (' . $v['note'] . ')' : '') . '.';
                        $warnings[] = $w;
                        $contentWarnings[] = $w;
                    }
                }
            }
        }

        return [
            'trip' => [
                'id' => (int) $trip->tripId,
                'title' => $trip->title,
                'startDate' => $trip->startDate,
                'endDate' => $end?->format('Y-m-d'),
                'days' => $days,
                'groupSize' => (int) $trip->groupSize,
                'budget' => $budget,
                'status' => $trip->status,
                'destinationId' => !empty($trip->destinationId) ? (int) $trip->destinationId : null,
                'shared' => !empty($trip->shareToken),
                'shareToken' => $trip->shareToken ?? null,
                'month' => $time['month'],
            ],
            'rain' => ['context' => $rainCtx, 'plan' => $rainPlan],
            'days' => array_values($byDay),
            'unassigned' => $unassigned,
            'totals' => ['cost' => $cost, 'budget' => $budget, 'left' => $budget !== null ? $budget - $cost : null],
            'warnings' => array_values(array_unique($warnings)),
            'contentWarnings' => array_values(array_unique($contentWarnings)),
            'advice' => $adviceRows,
            'estimateNote' => 'Quãng đường và thời gian là ước tính (đường chim bay x ' . self::ROAD_FACTOR . ', tốc độ ' . self::SPEED_KMH . ' km/h).',
        ];
    }

    // ------------------------------------------------------------------ Gợi ý thêm vào chuyến

    /**
     * Mục nên thêm: tour sắp khởi hành, trải nghiệm, món, lễ hội ở các điểm đến đang có trong chuyến, và các mục trong khay chưa dùng.
     *
     * @return array{tour:list<array>,experience:list<array>,food:list<array>,event:list<array>,tray:list<array>}
     */
    public function suggestions(object $trip, array $payload, int $userId): array
    {
        $all = array_merge($payload['unassigned'], ...array_map(fn($d) => $d['items'], $payload['days']));
        $have = [];
        $destIds = [];
        foreach ($all as $it) {
            $have[$it['type'] . ':' . $it['refId']] = true;
            if ($it['destinationId']) {
                $destIds[$it['destinationId']] = true;
            }
        }
        $destIds = array_keys($destIds);
        $group = max(1, (int) $trip->groupSize);
        $out = ['tour' => [], 'experience' => [], 'food' => [], 'event' => [], 'tray' => []];
        $coords = $destIds ? DB::table('tbl_destinations')->whereIn('destinationId', $destIds)->get(['destinationId', 'latitude', 'longitude'])->keyBy('destinationId') : collect();
        $ll = function ($did) use ($coords) {
            $c = $did ? $coords->get($did) : null;

            return ['lat' => $c && $c->latitude !== null ? (float) $c->latitude : null, 'lng' => $c && $c->longitude !== null ? (float) $c->longitude : null];
        };

        $fill = function (string $type, $rows, callable $map) use (&$out, $have) {
            foreach ($rows as $r) {
                if (!isset($have[$type . ':' . $map($r)['refId']])) {
                    $out[$type][] = $map($r);
                }
            }
        };

        if ($destIds) {
            $tq = DB::table('tbl_tours')->whereIn('destinationId', $destIds)->where('availability', 1)->where('quantity', '>', 0)
                ->whereDate('startDate', '>', now()->toDateString());
            if ($trip->startDate) {
                $tq->orderByRaw('ABS(DATEDIFF(startDate, ?))', [$trip->startDate]);
            } else {
                $tq->orderBy('startDate');
            }
            $fill('tour', $tq->limit(6)->get(), fn($t) => ['type' => 'tour', 'refId' => (int) $t->tourId, 'name' => $t->title,
                'sub' => date('d/m/Y', strtotime($t->startDate)) . ' · ' . number_format((float) $t->priceAdult, 0, ',', '.') . 'đ/người',
                'url' => route('tour-detail', ['id' => $t->tourId]), 'cost' => round((float) $t->priceAdult * $group, 2)] + $ll($t->destinationId));

            $fill('experience', DB::table('tbl_experiences')->where('reviewStatus', 'published')->whereIn('destinationId', $destIds)
                ->orderByDesc('isMustTry')->orderBy('name')->limit(6)->get(), fn($x) => ['type' => 'experience', 'refId' => (int) $x->experienceId,
                'name' => $x->name, 'sub' => (string) ($x->duration ?? ''), 'url' => route('experiences.show', ['slug' => $x->slug]), 'cost' => null] + $ll($x->destinationId));

            $fill('food', DB::table('tbl_foods')->where('reviewStatus', 'published')->where(function ($q) use ($destIds) {
                $q->whereIn('destinationId', $destIds)->orWhereIn('foodId', DB::table('tbl_food_destination')->whereIn('destinationId', $destIds)->select('foodId'));
            })->orderByDesc('isMustTry')->orderBy('name')->limit(6)->get(), fn($f) => ['type' => 'food', 'refId' => (int) $f->foodId,
                'name' => $f->name, 'sub' => (string) $f->category, 'url' => route('foods.show', ['slug' => $f->slug]), 'cost' => null] + $ll($f->destinationId));

            $fill('event', DB::table('tbl_events')->where('reviewStatus', 'published')->whereIn('destinationId', $destIds)
                ->orderBy('name')->limit(4)->get(), fn($e) => ['type' => 'event', 'refId' => (int) $e->eventId,
                'name' => $e->name, 'sub' => 'Lễ hội', 'url' => route('events.show', ['slug' => $e->slug]), 'cost' => null] + $ll($e->destinationId));
        }

        $refs = $this->trayRefs($userId);
        $res = $this->resolve($refs);
        foreach ($refs as $r) {
            $k = $r['type'] . ':' . $r['refId'];
            if (isset($res[$k]) && !isset($have[$k]) && $r['type'] !== 'tour') {
                $out['tray'][] = ['type' => $r['type'], 'refId' => $r['refId'], 'name' => $res[$k]['name'], 'sub' => $res[$k]['sub'], 'url' => $res[$k]['url'], 'cost' => null, 'lat' => $res[$k]['lat'], 'lng' => $res[$k]['lng']];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ Tìm nội dung để thêm vào chuyến (tab Khám phá)

    /**
     * Tìm nội dung ĐÃ XUẤT BẢN để thêm vào chuyến: điểm đến, địa điểm, món, trải nghiệm, lễ hội, tour còn chỗ.
     * $destId null = mọi điểm đến. $q rỗng = duyệt (mục "phải thử" và xếp hạng cao trước). Kết quả cùng dạng với gợi ý để JS dùng chung.
     *
     * @return list<array<string,mixed>>
     */
    public function search(object $trip, string $q, ?string $type, ?int $destId, int $limit = 24): array
    {
        $q = trim(mb_substr($q, 0, 80));
        $like = $q === '' ? null : '%' . addcslashes($q, '%_\\') . '%';
        $group = max(1, (int) $trip->groupSize);
        $types = $type && in_array($type, self::SEARCH_TYPES, true) ? [$type] : self::SEARCH_TYPES;
        $per = count($types) === 1 ? $limit : max(4, intdiv($limit, 3));
        $out = [];
        $num = fn($v) => $v !== null ? (float) $v : null;
        $ll = fn($own, $d) => $own !== null ? $own : $d;

        if (in_array('destination', $types, true)) {
            $r = DB::table('tbl_destinations')->where('isActive', 1)
                ->when($destId, fn($x) => $x->where('destinationId', $destId))
                ->when($like, fn($x) => $x->where(fn($y) => $y->where('name', 'like', $like)->orWhere('province', 'like', $like)))
                ->orderByDesc('isFeatured')->orderBy('name')->limit($per)->get();
            foreach ($r as $d) {
                $out[] = ['type' => 'destination', 'refId' => (int) $d->destinationId, 'name' => $d->name, 'sub' => (string) $d->province, 'destName' => $d->name,
                    'destinationId' => (int) $d->destinationId, 'url' => route('destination.show', ['slug' => $d->slug]), 'cost' => null,
                    'lat' => $num($d->latitude), 'lng' => $num($d->longitude)];
            }
        }
        if (in_array('place', $types, true)) {
            $r = DB::table('tbl_external_places as p')->join('tbl_destinations as d', 'd.destinationId', '=', 'p.destinationId')
                ->where('p.reviewStatus', 'published')->where('d.isActive', 1)
                ->when($destId, fn($x) => $x->where('p.destinationId', $destId))
                ->when($like, fn($x) => $x->where('p.name', 'like', $like))
                ->orderByDesc('p.isMustTry')->orderBy('p.rank')->orderBy('p.name')->limit($per)
                ->get(['p.placeId', 'p.name', 'p.kind', 'p.destinationId', 'p.latitude', 'p.longitude', 'd.slug as destSlug', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng']);
            foreach ($r as $p) {
                $out[] = ['type' => 'place', 'refId' => (int) $p->placeId, 'name' => $p->name, 'sub' => (string) (\App\Support\PlaceKind::label($p->kind) ?? ''), 'destName' => $p->destName,
                    'destinationId' => (int) $p->destinationId, 'url' => route('destination.show', ['slug' => $p->destSlug]) . '#gan-day', 'cost' => null,
                    'lat' => $ll($num($p->latitude), $num($p->dLat)), 'lng' => $ll($num($p->longitude), $num($p->dLng))];
            }
        }
        if (in_array('food', $types, true)) {
            $r = DB::table('tbl_foods as f')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'f.destinationId')
                ->where('f.reviewStatus', 'published')
                ->when($destId, fn($x) => $x->where(function ($y) use ($destId) {
                    $y->where('f.destinationId', $destId)->orWhereIn('f.foodId', DB::table('tbl_food_destination')->where('destinationId', $destId)->select('foodId'));
                }))
                ->when($like, fn($x) => $x->where('f.name', 'like', $like))
                ->orderByDesc('f.isMustTry')->orderBy('f.name')->limit($per)
                ->get(['f.foodId', 'f.name', 'f.slug', 'f.category', 'f.destinationId', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng']);
            foreach ($r as $f) {
                $out[] = ['type' => 'food', 'refId' => (int) $f->foodId, 'name' => $f->name, 'sub' => (string) $f->category, 'destName' => $f->destName,
                    'destinationId' => $f->destinationId ? (int) $f->destinationId : null, 'url' => route('foods.show', ['slug' => $f->slug]), 'cost' => null,
                    'lat' => $num($f->dLat), 'lng' => $num($f->dLng)];
            }
        }
        if (in_array('experience', $types, true)) {
            $r = DB::table('tbl_experiences as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
                ->where('e.reviewStatus', 'published')
                ->when($destId, fn($x) => $x->where('e.destinationId', $destId))
                ->when($like, fn($x) => $x->where('e.name', 'like', $like))
                ->orderByDesc('e.isMustTry')->orderBy('e.name')->limit($per)
                ->select('e.*', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng')->get();
            foreach ($r as $x) {
                $out[] = ['type' => 'experience', 'refId' => (int) $x->experienceId, 'name' => $x->name, 'sub' => (string) ($x->duration ?? ''), 'destName' => $x->destName,
                    'destinationId' => $x->destinationId ? (int) $x->destinationId : null, 'url' => route('experiences.show', ['slug' => $x->slug]), 'cost' => null,
                    'lat' => $ll($num($x->latitude ?? null), $num($x->dLat)), 'lng' => $ll($num($x->longitude ?? null), $num($x->dLng))];
            }
        }
        if (in_array('event', $types, true)) {
            $r = DB::table('tbl_events as e')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'e.destinationId')
                ->where('e.reviewStatus', 'published')
                ->when($destId, fn($x) => $x->where('e.destinationId', $destId))
                ->when($like, fn($x) => $x->where('e.name', 'like', $like))
                ->orderBy('e.name')->limit($per)
                ->get(['e.eventId', 'e.name', 'e.slug', 'e.destinationId', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng']);
            foreach ($r as $e) {
                $out[] = ['type' => 'event', 'refId' => (int) $e->eventId, 'name' => $e->name, 'sub' => 'Lễ hội', 'destName' => $e->destName,
                    'destinationId' => $e->destinationId ? (int) $e->destinationId : null, 'url' => route('events.show', ['slug' => $e->slug]), 'cost' => null,
                    'lat' => $num($e->dLat), 'lng' => $num($e->dLng)];
            }
        }
        if (in_array('tour', $types, true)) {
            $tq = DB::table('tbl_tours as t')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
                ->where('t.availability', 1)->where('t.quantity', '>', 0)->whereDate('t.startDate', '>', now()->toDateString())
                ->when($destId, fn($x) => $x->where('t.destinationId', $destId))
                ->when($like, fn($x) => $x->where('t.title', 'like', $like));
            $trip->startDate ? $tq->orderByRaw('ABS(DATEDIFF(t.startDate, ?))', [$trip->startDate]) : $tq->orderBy('t.startDate');
            foreach ($tq->limit($per)->get(['t.tourId', 't.title', 't.startDate', 't.priceAdult', 't.destinationId', 'd.name as destName', 'd.latitude as dLat', 'd.longitude as dLng']) as $t) {
                $out[] = ['type' => 'tour', 'refId' => (int) $t->tourId, 'name' => $t->title,
                    'sub' => date('d/m/Y', strtotime($t->startDate)) . ' · ' . number_format((float) $t->priceAdult, 0, ',', '.') . 'đ/người', 'destName' => $t->destName,
                    'destinationId' => $t->destinationId ? (int) $t->destinationId : null, 'url' => route('tour-detail', ['id' => $t->tourId]),
                    'cost' => round((float) $t->priceAdult * $group, 2), 'lat' => $num($t->dLat), 'lng' => $num($t->dLng)];
            }
        }

        return array_slice($out, 0, $limit * (count($types) === 1 ? 1 : 3));
    }

    /** Giá trị tọa độ có được điền không (số, kể cả 0; null và chuỗi rỗng là không). */
    private static function hasCoord(mixed $v): bool
    {
        return $v !== null && $v !== '' && is_numeric($v);
    }

    /** Thêm tọa độ cho JS vẽ bản đồ: giữ nguyên mục nhưng chỉ lấy trường cần thiết. */
    public static function itemForClient(array $it): array
    {
        return [
            'type' => $it['type'], 'refId' => $it['refId'], 'name' => $it['name'], 'sub' => $it['sub'], 'url' => $it['url'] ?? null,
            'day' => $it['day'] ?? null, 'note' => $it['note'] ?? '', 'cost' => $it['cost'] ?? null,
            'lat' => $it['lat'], 'lng' => $it['lng'], 'when' => $it['when'] ?? null,
            'slot' => $it['slot'] ?? null, 'reasons' => $it['reasons'] ?? [], 'source' => $it['source'] ?? null,
            'fit' => $it['fit'] ?? [], 'outdoor' => $it['outdoor'] ?? null, 'src' => $it['src'] ?? null,
            'image' => $it['image'] ?? null, 'address' => $it['address'] ?? null, 'website' => $it['website'] ?? null, 'blurb' => $it['blurb'] ?? null,
        ];
    }
}
