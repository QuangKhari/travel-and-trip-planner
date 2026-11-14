<?php

namespace App\Services\Planner;

use App\Services\Content\TravelAdvice;
use App\Services\Content\TripTray;
use App\Services\EventDateService;
use App\Services\Planner\Route\RouteService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tuần 5-7: Trip Planner. Dùng chung cho trang web (PlannerController) và API (Api\V1\TripController).
 *
 * Mô hình dữ liệu: tbl_trips (chuyến) + tbl_trip_items (mục: điểm đến, món, lễ hội, trải nghiệm, tour; dayNo null = chưa xếp).
 * Khay chuyến đi (TripTray) là một chuyến nháp tên cố định; Planner không bao giờ sửa hay liệt kê nó như một chuyến thường.
 * Quãng đường: qua RouteService (một nguồn cho cả trang sửa, sức khỏe chuyến đi và tối ưu tuyến), tham số ở config planner.route.
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
    /** Nguồn chi phí của một mục: tự nhập dự kiến | điền từ giá tham khảo | đã chi thật. */
    public const COST_TYPES = ['planned', 'estimate', 'actual'];
    public const MAX_DAYS = 14;
    public const MAX_ITEMS = 80;

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
            // Timeline: giờ bắt đầu người dùng đặt (phút từ 00:00) và thời lượng ở lại (phút); null = để hệ thống tự xếp
            'items.*.startMin' => ['nullable', 'integer', 'between:0,1439'],
            'items.*.durationMin' => ['nullable', 'integer', 'between:5,720'],
            'items.*.costType' => ['nullable', 'in:' . implode(',', self::COST_TYPES)],
            'items.*.locked' => ['nullable', 'boolean'],
            // T-05: phương tiện cho chặng đi TỪ mục này tới mục có vị trí kế tiếp (null = phương tiện mặc định của chuyến)
            'items.*.legMode' => ['nullable', 'in:' . implode(',', array_keys((array) config('planner.route.leg_modes', [])))],
            // đã ghé (khi đang đi): thời điểm đánh dấu, null = chưa
            'items.*.visitedAt' => ['nullable', 'date'],
            // bỏ qua khi đang đi (khác "đã ghé"): giữ mục trong lịch nhưng không tính là việc còn lại của ngày
            'items.*.skippedAt' => ['nullable', 'date'],
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
        return DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $userId])->where('kind', 'trip')->first() ?: null;
    }

    public function trips(int $userId): array
    {
        $rows = DB::table('tbl_trips as t')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('t.userId', $userId)->where('t.kind', 'trip')
            ->selectRaw('t.*, d.name AS destName, d.coverImage AS destCover, (SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId) AS itemCount,'
                . ' (SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId AND i.dayNo IS NOT NULL) AS placedCount,'
                . ' (SELECT COUNT(*) FROM tbl_trip_members m WHERE m.tripId = t.tripId) AS memberCount')
            ->orderByDesc(DB::raw('COALESCE(t.updatedAt, t.createdAt)'))->get()->all();

        return array_map([self::class, 'cardInfo'], $rows);
    }

    /**
     * Thông tin hiển thị trên thẻ chuyến đi (trang "Chuyến đi của tôi"): ảnh bìa (ảnh điểm đến chính), ngày về,
     * nhóm lọc: planning (chưa có ngày đi hoặc còn xa) | upcoming (khởi hành trong 30 ngày tới) | ongoing | past | done.
     */
    public static function cardInfo(object $t): object
    {
        $t->coverUrl = !empty($t->destCover ?? null) ? \App\Support\DiscoverImage::url((string) $t->destCover) : null;
        $start = !empty($t->startDate) ? new DateTimeImmutable((string) $t->startDate) : null;
        $t->endDate = $start ? $start->modify('+' . max(0, (int) $t->days - 1) . ' days')->format('Y-m-d') : null;
        $today = new DateTimeImmutable('today');
        if (($t->status ?? '') === 'done') {
            $t->phase = 'done';
        } elseif (!$start) {
            $t->phase = 'planning';
        } elseif ($t->endDate < $today->format('Y-m-d')) {
            $t->phase = 'past';
        } elseif ($start <= $today) {
            $t->phase = 'ongoing';
        } else {
            $t->phase = $start <= $today->modify('+30 days') ? 'upcoming' : 'planning';
        }
        $t->daysLeft = $start && $start > $today ? (int) $today->diff($start)->days : null;

        return $t;
    }

    /** Các mục đang có trong khay: [['type'=>..., 'refId'=>...], ...] theo thứ tự thêm. */
    public function trayRefs(int $userId): array
    {
        $tray = DB::table('tbl_trips')->where(['userId' => $userId, 'kind' => 'tray', 'status' => 'draft'])->value('tripId');
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
                $add('place', (int) $p->placeId, ['name' => $p->name, 'sub' => (string) (\App\Support\PlaceKind::label($p->kind) ?? ''), 'kind' => (string) $p->kind,
                    'destinationId' => (int) $p->destinationId, 'url' => route('destination.show', ['slug' => $p->destSlug]) . '#gan-day',
                    'ownLat' => $p->latitude !== null ? (float) $p->latitude : null, 'ownLng' => $p->longitude !== null ? (float) $p->longitude : null]);
            }
        }
        if (!empty($ids['food'])) {
            // món ăn: vị trí lấy từ quán đã gắn (tbl_foods.placeId); chưa gắn quán thì KHÔNG có vị trí (không lấy tâm điểm đến,
            // tránh đoạn đi giả "về trung tâm rồi đi tiếp")
            $q = DB::table('tbl_foods as f')->whereIn('f.foodId', array_keys($ids['food']))->where('f.reviewStatus', 'published');
            $hasPlace = \Illuminate\Support\Facades\Schema::hasColumn('tbl_foods', 'placeId');
            if ($hasPlace) {
                $q->leftJoin('tbl_external_places as fp', function ($j) {
                    $j->on('fp.placeId', '=', 'f.placeId')->where('fp.reviewStatus', 'published');
                })->select('f.*', 'fp.latitude as pLat', 'fp.longitude as pLng', 'fp.name as pName');
            }
            foreach ($q->get() as $f) {
                $has = $hasPlace && $f->pLat !== null && $f->pLng !== null;
                $add('food', (int) $f->foodId, ['name' => $f->name, 'slug' => $f->slug, 'sub' => (string) $f->category . ($has && $f->pName ? ' · ' . $f->pName : ''),
                    'destinationId' => $f->destinationId ? (int) $f->destinationId : null, 'url' => route('foods.show', ['slug' => $f->slug]),
                    'noPos' => !$has, 'ownLat' => $has ? (float) $f->pLat : null, 'ownLng' => $has ? (float) $f->pLng : null]);
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
                    'durationMin' => !empty($x->durationMinutes ?? null) ? (int) $x->durationMinutes : null,
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
            // địa điểm/trải nghiệm có tọa độ riêng thì dùng, không thì lấy tâm điểm đến; món ăn chưa gắn quán thì không có vị trí
            $center = empty($r['noPos']) && $d && $d->latitude !== null;
            $rows[$k]['lat'] = $r['ownLat'] ?? ($center ? (float) $d->latitude : null);
            $rows[$k]['lng'] = $r['ownLng'] ?? ($center ? (float) $d->longitude : null);
            unset($rows[$k]['noPos']);
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
                    'startMin' => isset($r->startMin) ? (int) $r->startMin : null,
                    'durationMin' => !empty($r->durationMin) ? (int) $r->durationMin : null,
                    'costType' => $r->costType ?? null,
                    'locked' => !empty($r->isLocked),
                    'legMode' => $r->legMode ?? null,
                    'visitedAt' => $r->visitedAt ?? null,
                'skippedAt' => $r->skippedAt ?? null,
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
                'startMin' => isset($r->startMin) ? (int) $r->startMin : null,
                'durationMin' => !empty($r->durationMin) ? (int) $r->durationMin : ($res[$k]['durationMin'] ?? null),
                'costType' => $r->costType ?? null,
                'locked' => !empty($r->isLocked),
                'legMode' => $r->legMode ?? null,
                'visitedAt' => $r->visitedAt ?? null,
                'skippedAt' => $r->skippedAt ?? null,
            ]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ Tạo / lưu / xóa / chia sẻ

    /** Tạo chuyến mới; $fromTray = true thì lấy các mục trong khay và tự chia vào các ngày. */
    /**
     * Giới hạn số chuyến mỗi người (chống làm đầy CSDL): áp dụng cho mọi đường tạo chuyến
     * (tạo mới, từ khay, từ lịch trình mẫu, từ tour, tự đề xuất, nhân bản, API).
     */
    public static function assertCanCreate(int $userId): void
    {
        $max = (int) config('planner.max_trips_per_user', 200);
        if (DB::table('tbl_trips')->where('userId', $userId)->where('kind', 'trip')->count() >= $max) {
            throw new \DomainException('Bạn đã có ' . $max . ' chuyến đi (mức tối đa). Hãy xóa bớt chuyến cũ trước khi tạo chuyến mới.');
        }
    }

    public function create(int $userId, array $f, bool $fromTray = false): int
    {
        self::assertCanCreate($userId);

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
        self::assertCanCreate($userId);
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
        self::assertCanCreate($userId);
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
                // chèn nhiều dòng một lần: mọi dòng phải CÙNG tập cột (MySQL báo "Column count doesn't match" nếu khác)
                $rows[] = ['tripId' => $tripId, 'refType' => 'destination', 'refId' => $destId, 'dayNo' => 1, 'orderNo' => $order++, 'cost' => null];
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

        $oldStart = !empty($acc['trip']->startDate) ? substr((string) $acc['trip']->startDate, 0, 10) : null;
        $newStart = !empty($f['startDate']) ? substr((string) $f['startDate'], 0, 10) : null;
        DB::transaction(function () use ($tripId, $f, $items, $days, $valid, $userId, $oldStart, $newStart) {
            // BẮT BUỘC đọc revision sau khi khóa row: tránh hai request cùng vượt qua check cũ.
            $current = DB::table('tbl_trips')->where('tripId', $tripId)->lockForUpdate()->first(['rev']);
            if (!$current) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Chuyến đi không tồn tại.');
            }
            if (array_key_exists('rev', $f) && $f['rev'] !== null && (int) $f['rev'] !== (int) ($current->rev ?? 0)) {
                $by = app(TripSharing::class)->revision($tripId)['by'];
                throw new TripConflict((int) ($current->rev ?? 0), $by);
            }
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
                // ngày ngoài số ngày của chuyến (vừa bớt ngày): vào "Đã lưu", không dồn vào ngày cuối
                $day = isset($it['day']) && $it['day'] !== null && $it['day'] !== '' ? max(1, (int) $it['day']) : null;
                if ($day !== null && $day > $days) {
                    $day = null;
                    $it['startMin'] = null;
                }
                $ck = $day === null ? 'x' : (string) $day;
                $counter[$ck] = ($counter[$ck] ?? -1) + 1;
                // (0,0) / ngoài phạm vi = vị trí hỏng: lưu như "chưa có vị trí", không lưu thành một điểm giữa biển
                $pos = $custom && self::hasCoord($it['lat'] ?? null) && self::hasCoord($it['lng'] ?? null)
                    && \App\Services\Planner\Route\HaversineProvider::validPoint([(float) $it['lat'], (float) $it['lng']]);
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
                ] + self::timeColumns($it);
            }
            // đổi ngày đi: mục nhận phòng / chuyến bay / tàu đã đặt đi theo NGÀY THẬT của đặt chỗ (không đứng yên ở số ngày cũ)
            if ($oldStart !== $newStart) {
                $rows = BookingPlan::realignRows($tripId, $rows, $newStart, $days);
            }
            if ($rows) {
                DB::table('tbl_trip_items')->insert($rows);
            }
            // Lịch sử phiên bản: chụp lại bản vừa lưu (cùng transaction với lần tăng rev)
            app(TripRevisions::class)->record($tripId, isset($f['rev']) ? (int) $f['rev'] : null, $userId, 'save', $rows);
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

    /**
     * Nhân bản một chuyến (của mình hoặc được mời vào) thành chuyến MỚI của người dùng: giữ thông tin, các mục, giờ,
     * chi phí và phương án mưa; không chép người được mời, liên kết chia sẻ, phiên bản. Trả id chuyến mới, null nếu không có quyền xem.
     */
    public function duplicate(int $userId, int $tripId): ?int
    {
        $acc = app(TripSharing::class)->access($userId, $tripId);
        if (!$acc) {
            return null;
        }
        $src = (array) $acc['trip'];
        self::assertCanCreate($userId);

        return DB::transaction(function () use ($src, $userId, $tripId) {
            $row = $src;
            unset($row['tripId']);
            $row['userId'] = $userId;
            $row['title'] = mb_substr(preg_replace('/( \(bản sao\))+$/u', '', (string) $src['title']) . ' (bản sao)', 0, 160);
            $row['status'] = 'draft';
            $row['source'] = 'copy';
            $row['createdAt'] = now();
            $row['updatedAt'] = now();
            $row = self::freshCopyRow($row);
            $newId = (int) DB::table('tbl_trips')->insertGetId($row);
            // đặt chỗ không chép sang chuyến mới, nên mục "Nhận phòng / Bay …" của chuyến cũ cũng không chép
            $items = collect(TripLogistics::withoutBookingRows(DB::table('tbl_trip_items')->where('tripId', $tripId)->orderBy('dayNo')->orderBy('orderNo')->get(), $tripId))
                ->map(fn($r) => self::freshCopyItem((array) $r, $newId, false))->all();
            foreach (array_chunk($items, 50) as $chunk) {
                DB::table('tbl_trip_items')->insert($chunk);
            }

            return $newId;
        });
    }

    /**
     * Đổi số ngày của chuyến (chép từ mẫu với số ngày khác): mục ở các ngày bị bớt chuyển vào "Đã lưu" (chưa xếp ngày), không xóa.
     * @return int số mục đã chuyển
     */
    public function resizeDays(int $tripId, int $days): int
    {
        $days = max(1, min(self::MAX_DAYS, $days));

        return DB::transaction(function () use ($tripId, $days) {
            $moved = DB::table('tbl_trip_items')->where('tripId', $tripId)->where('dayNo', '>', $days)->update(['dayNo' => null, 'startMin' => null]);
            DB::table('tbl_trips')->where('tripId', $tripId)->update(['days' => $days, 'updatedAt' => now()]);

            return $moved;
        });
    }

    /** Lưu bản sao một chuyến được chia sẻ bằng liên kết chỉ xem (người chép không cần là thành viên). */
    public function copyShared(int $userId, int $tripId): int
    {
        $src = DB::table('tbl_trips')->where('tripId', $tripId)->where('kind', 'trip')->whereNotNull('shareToken')->first();
        if (!$src) {
            throw new \DomainException('Liên kết chia sẻ đã tắt.');
        }
        self::assertCanCreate($userId);

        return DB::transaction(function () use ($src, $userId) {
            $row = self::freshCopyRow((array) $src);
            unset($row['tripId']);
            $row = array_merge($row, ['userId' => $userId, 'status' => 'draft', 'source' => 'copy', 'notes' => ($src->shareNotes ?? 1) ? $src->notes : null,
                'sourceTripId' => $src->tripId, 'sourceType' => 'SHARED', 'createdAt' => now(), 'updatedAt' => now()]);
            $newId = (int) DB::table('tbl_trips')->insertGetId($row);
            $hideNotes = !($src->shareNotes ?? 1);
            $rows = DB::table('tbl_trip_items')->where('tripId', $src->tripId)->orderBy('dayNo')->orderBy('orderNo')->get();
            $items = collect(TripLogistics::withoutBookingRows($rows, (int) $src->tripId))
                ->map(function ($r) use ($newId, $hideNotes) {
                    $a = self::freshCopyItem((array) $r, $newId, true);
                    if ($hideNotes) {
                        $a['note'] = null;   // chủ chuyến đã ẩn ghi chú khi chia sẻ thì bản sao cũng không có
                    }

                    return $a;
                })->all();
            foreach (array_chunk($items, 50) as $chunk) {
                DB::table('tbl_trip_items')->insert($chunk);
            }

            return $newId;
        });
    }

    /**
     * Một chuyến SAO CHÉP (nhân bản, dùng lại từ Cộng đồng, lưu bản sao từ liên kết) là chuyến mới hoàn toàn của người chép:
     * không mang theo liên kết chia sẻ / liên kết mời (cột joinToken là UNIQUE), trạng thái công khai, lượt xem / thích / dùng lại,
     * quyền riêng tư đã chỉnh, nhắc đặt chỗ đã bỏ qua.
     */
    public static function freshCopyRow(array $row): array
    {
        $reset = ['shareToken' => null, 'joinToken' => null, 'lastEditorId' => null, 'rev' => 0, 'isPublic' => 0, 'publishedAt' => null,
            'viewCount' => 0, 'likeCount' => 0, 'cloneCount' => 0, 'bookingSkip' => null, 'joinRole' => 'edit',
            'shareNotes' => 1, 'shareBookings' => 0, 'shareCosts' => 0];
        foreach ($reset as $k => $v) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $v;
            }
        }

        return $row;
    }

    /** Mục của bản sao: bỏ "đã ghé" / "bỏ qua" của chuyến gốc; chép cho người KHÁC thì chi phí thực tế đưa về dự kiến. */
    public static function freshCopyItem(array $a, int $newTripId, bool $forOther): array
    {
        unset($a['itemId']);
        $a['tripId'] = $newTripId;
        foreach (['visitedAt', 'skippedAt'] as $k) {
            if (array_key_exists($k, $a)) {
                $a[$k] = null;
            }
        }
        if ($forOther && ($a['costType'] ?? null) === 'actual') {
            $a['costType'] = 'estimate';
        }

        return $a;
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

        return DB::table('tbl_trips')->where('shareToken', $token)->where('kind', 'trip')->first() ?: null;
    }

    // ------------------------------------------------------------------ Read model

    /**
     * Dữ liệu đầy đủ cho trang sửa, trang chia sẻ và API.
     * Phần dựng dữ liệu (read model) nằm ở TripPayloadBuilder; TripPlanner chỉ lo tạo / lưu / nhân bản / quyền.
     * Giữ hàm này để các nơi gọi cũ (API, controller, test) không phải đổi.
     */
    public function payload(object $trip): array
    {
        return app(TripPayloadBuilder::class)->build($trip);
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
            'type' => $it['type'], 'refId' => $it['refId'], 'name' => $it['name'], 'sub' => $it['sub'], 'url' => $it['url'] ?? null, 'kind' => $it['kind'] ?? null,
            'day' => $it['day'] ?? null, 'note' => $it['note'] ?? '', 'cost' => $it['cost'] ?? null,
            'lat' => $it['lat'], 'lng' => $it['lng'], 'when' => $it['when'] ?? null,
            'slot' => $it['slot'] ?? null, 'reasons' => $it['reasons'] ?? [], 'source' => $it['source'] ?? null,
            'fit' => $it['fit'] ?? [], 'outdoor' => $it['outdoor'] ?? null, 'src' => $it['src'] ?? null,
            'image' => $it['image'] ?? null, 'address' => $it['address'] ?? null, 'website' => $it['website'] ?? null, 'blurb' => $it['blurb'] ?? null,
            'startMin' => $it['startMin'] ?? null, 'durationMin' => $it['durationMin'] ?? null, 'defDur' => self::defaultDuration($it),
            'costType' => $it['costType'] ?? null, 'locked' => !empty($it['locked']),
            'legMode' => $it['legMode'] ?? null, 'visitedAt' => $it['visitedAt'] ?? null, 'skippedAt' => $it['skippedAt'] ?? null,
            'hours' => $it['hours'] ?? null,   // giờ mở cửa theo thứ (OpeningHours); null = chưa biết
            'hoursNote' => $it['hoursNote'] ?? null,   // ngày lễ / đóng tạm thời / giờ theo mùa của đúng ngày đang xếp
        ];
    }

    /**
     * Thời lượng mặc định (phút) của một mục khi người dùng chưa đặt: cùng bảng config('planner.default_duration_min') mà PlannerEngine dùng.
     * Địa điểm tra theo nhóm kind; tour tính nửa ngày; mục tự thêm: di chuyển 30 phút, ghi chú/lưu trú/ăn uống 60 phút.
     */
    public static function defaultDuration(array $it): int
    {
        $d = (array) config('planner.default_duration_min', []);
        $type = (string) ($it['type'] ?? '');
        if ($type === 'place') {
            $group = 'default';
            foreach ((array) config('planner.kind_groups', []) as $g => $kinds) {
                if (in_array((string) ($it['kind'] ?? ''), (array) $kinds, true)) {
                    $group = $g;
                    break;
                }
            }

            return (int) ($d['place'][$group] ?? $d['place']['default'] ?? 60);
        }

        return match ($type) {
            'tour' => 240,
            'destination' => 120,
            'transport' => 30,
            'note' => 60,
            default => (int) (is_numeric($d[$type] ?? null) ? $d[$type] : 60),
        };
    }

    /** startMin / durationMin / costType chỉ ghi khi cột đã có (cơ sở dữ liệu chưa chạy migration mới vẫn lưu được các phần còn lại). */
    private static function timeColumns(array $it): array
    {
        static $has = null;
        if ($has === null) {
            $has = ['startMin' => Schema::hasColumn('tbl_trip_items', 'startMin'), 'durationMin' => Schema::hasColumn('tbl_trip_items', 'durationMin'),
                'costType' => Schema::hasColumn('tbl_trip_items', 'costType'), 'isLocked' => Schema::hasColumn('tbl_trip_items', 'isLocked'),
                'legMode' => Schema::hasColumn('tbl_trip_items', 'legMode'), 'visitedAt' => Schema::hasColumn('tbl_trip_items', 'visitedAt'),
                'skippedAt' => Schema::hasColumn('tbl_trip_items', 'skippedAt')];
        }
        $out = [];
        if ($has['legMode']) {
            $m = $it['legMode'] ?? null;
            $out['legMode'] = is_string($m) && isset(((array) config('planner.route.leg_modes', []))[$m]) ? $m : null;
        }
        if ($has['visitedAt']) {
            $v = $it['visitedAt'] ?? null;
            $out['visitedAt'] = $v ? \Carbon\Carbon::parse((string) $v)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
        }
        if ($has['skippedAt']) {
            $v = $it['skippedAt'] ?? null;
            $out['skippedAt'] = $v ? \Carbon\Carbon::parse((string) $v)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
        }
        if ($has['isLocked']) {
            $out['isLocked'] = !empty($it['locked']) ? 1 : 0;
        }
        if ($has['costType']) {
            $hasCost = isset($it['cost']) && $it['cost'] !== '' && $it['cost'] !== null;
            $out['costType'] = $hasCost && in_array($it['costType'] ?? null, self::COST_TYPES, true) ? $it['costType'] : null;
        }
        if ($has['startMin']) {
            $v = $it['startMin'] ?? null;
            $out['startMin'] = $v === null || $v === '' ? null : max(0, min(1439, (int) $v));
        }
        if ($has['durationMin']) {
            $v = $it['durationMin'] ?? null;
            $out['durationMin'] = $v === null || $v === '' ? null : max(5, min(720, (int) $v));
        }

        return $out;
    }
}
