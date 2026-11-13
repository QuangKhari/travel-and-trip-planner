<?php

namespace App\Services\Ai\Copilot;

use App\Services\Content\SearchCatalog;
use App\Services\EventDateService;
use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\Health\TripResolver;
use App\Services\Planner\ItemInsights;
use App\Services\Planner\RainPlan;
use App\Services\Planner\Route\HaversineProvider;
use App\Services\Planner\TripPlanner;
use App\Services\Planner\TripWeather;
use App\Support\PlaceKind;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La bàn "Gợi ý cho chuyến này" — chạy bằng LUẬT trên dữ liệu đã duyệt của Travela, KHÔNG cần LLM:
 *
 *   food      món nên thử ở điểm đến của chuyến (đúng mùa), kèm quán gần lịch từng ngày
 *   places    điểm tham quan / trải nghiệm chưa có trong lịch, xếp theo "nên thử" và độ gần với lịch từng ngày
 *   festival  lễ hội diễn ra TRONG khoảng ngày đi (hoặc sát trước / sau), tính từ quy tắc âm / dương lịch (EventDateService)
 *   alerts    cảnh báo chủ động: dông / mưa / nắng nóng theo dự báo giờ, nơi đóng cửa đúng ngày, cảnh báo điểm đến do Travela nhập
 *             (tbl_travel_alerts: sạt lở, tạm đóng, cấm đường...) — mỗi cảnh báo kèm cách đổi lịch cụ thể
 *
 * Mỗi thẻ có các nút "áp dụng" mô tả thao tác (add / replace / move / time). Khi bấm, apply() biên dịch thao tác bằng
 * CHÍNH KeyedChangeCompiler + mô phỏng TripHealth của Copilot rồi trả "phiếu kiểm chứng" — lịch chỉ đổi khi người dùng bấm Áp dụng.
 */
final class TripSuggest
{
    public const INTENTS = ['food', 'places', 'festival', 'alerts'];
    private const LIMIT = 6;

    private const WORDS = [
        'festival' => ['le hoi', 'su kien', 'festival', 'hoi lang', 'dip le', 'ngay le', 'le tet', 'hoi cho', 'hoi hoa', 'phao hoa'],
        'alerts' => ['mua giong', 'giong', 'bao lu', 'con bao', 'ap thap', 'mua lon', 'neu mua', 'troi mua', 'thoi tiet', 'su co', 'canh bao',
            'ngap', 'sat lo', 'tam dong', 'dong cua', 'nang nong', 'du bao', 'co mua khong', 'mua to', 'bi mua'],
        'food' => ['mon an', 'mon nao', 'mon gi', 'an gi', 'an mon', 'dac san', 'am thuc', 'quan an', 'an uong', 'mon ngon', 'an sang', 'an trua',
            'an toi', 'mon dia phuong', 'phai thu', 'nen thu', 'an vat', 'hai san'],
        'places' => ['dia diem', 'cho choi', 'choi gi', 'di dau', 'tham quan', 'diem den', 'check in', 'song ao', 'canh dep', 'diem tham quan',
            'trai nghiem', 'noi nao', 'cho nao', 'them diem', 'diem choi', 'di choi'],
    ];
    /** Câu SỬA cụ thể (thay X, chuyển X sang...) thì để Copilot LLM xử lý, không chặn bằng thẻ gợi ý. */
    private const EDIT = ['thay ', 'chuyen ', 'doi gio', 'doi ngay', 'xep lai', 'sap xep', 'xoa ', 'bo bot', 'bot di', 'gop ', 'tach ', 'som hon', 'muon hon', 'can bang', 'nhe hon'];

    public function __construct(
        private TripPlanner $planner,
        private TripHealth $health,
        private TripResolver $resolver,
        private RainPlan $rain,
        private EventDateService $dates,
    ) {}

    /** Nhận ý định từ câu người dùng; null = không phải câu xin gợi ý (đi luồng Hỏi / Copilot như cũ). */
    public static function intent(string $text, array $mentions = []): ?string
    {
        if ($mentions) {
            return null;
        }
        $f = ' ' . SearchCatalog::fold($text) . ' ';
        foreach (self::EDIT as $w) {
            if (str_contains($f, ' ' . $w)) {
                return null;
            }
        }
        foreach (['festival', 'alerts', 'food', 'places'] as $k) {
            foreach (self::WORDS[$k] as $w) {
                if (str_contains($f, ' ' . $w . ' ') || str_contains($f, ' ' . $w . '?')) {
                    return $k;
                }
            }
        }

        return null;
    }

    /** "ngày 2" trong câu -> 2 (để ưu tiên gợi ý cho ngày đó). */
    public static function dayOf(string $text): ?int
    {
        return preg_match('/\bngay\s*(\d{1,2})\b/', SearchCatalog::fold($text), $m) ? (int) $m[1] : null;
    }

    public function suggest(object $trip, string $intent, ?int $day = null): array
    {
        $c = $this->context($trip);
        if ($day !== null && ($day < 1 || $day > $c['days'])) {
            $day = null;
        }
        [$say, $cards] = match ($intent) {
            'food' => $this->food($c, $day),
            'places' => $this->places($c, $day),
            'festival' => $this->festival($c),
            default => $this->alerts($c),
        };

        return ['ok' => true, 'kind' => 'suggest', 'intent' => $intent, 'rev' => (int) ($trip->rev ?? 0), 'days' => $c['days'],
            'dayLabels' => $c['dayLabels'], 'say' => [['t' => $say]], 'cards' => array_values($cards),
            'more' => $this->more($intent)];
    }

    /**
     * Áp một nút của thẻ gợi ý: dựng thao tác dạng khóa, đưa qua CopilotService (biên dịch + mô phỏng) -> phiếu kiểm chứng.
     * @param array{op:string,type?:string,refId?:int,toDay?:?int,item?:string,startMin?:int,rev:int} $a
     */
    public function apply(object $trip, ?int $userId, array $a, CopilotService $copilot): array
    {
        $rev = (int) ($trip->rev ?? 0);
        if ((int) $a['rev'] !== $rev) {
            return ['ok' => false, 'kind' => 'reply', 'rev' => $rev, 'stale' => true,
                'say' => [['t' => 'Lịch vừa thay đổi sau khi La bàn gợi ý. Bạn bấm “Gợi ý lại” để có danh sách mới nhé.']]];
        }
        $cands = [];
        $keyed = [];
        $explicit = [];
        $with = null;
        if (in_array($a['op'], ['add', 'replace'], true)) {
            $full = $this->resolver->candidate($trip, (string) $a['type'], (int) $a['refId']);
            if (!$full) {
                return ['ok' => false, 'kind' => 'reply', 'rev' => $rev, 'say' => [['t' => 'Mục này không còn trong kho Travela.']]];
            }
            $with = 'c:' . $full['type'] . ':' . $full['refId'];
            $cands[$with] = $full;
        }
        if (!empty($a['item'])) {
            $explicit[] = (string) $a['item'];   // người dùng bấm đúng thẻ của mục này = nêu đích danh
        }
        $say = '';
        switch ($a['op']) {
            case 'add':
                $keyed[] = ['op' => 'add', 'with' => $with, 'toDay' => $a['toDay'] ?? null];
                $say = 'Thêm ' . $cands[$with]['name'] . (($a['toDay'] ?? null) ? ' vào ngày ' . $a['toDay'] : ' vào Đã lưu') . '.';
                break;
            case 'replace':
                $keyed[] = ['op' => 'replace', 'item' => $a['item'] ?? null, 'with' => $with];
                $say = 'Đổi sang ' . $cands[$with]['name'] . ' cho an toàn hơn.';
                break;
            case 'move':
                $keyed[] = ['op' => 'move', 'item' => $a['item'] ?? null, 'toDay' => $a['toDay'] ?? null];
                break;
            case 'time':
                $keyed[] = ['op' => 'time', 'item' => $a['item'] ?? null, 'startMin' => $a['startMin'] ?? null];
                break;
        }

        return $copilot->proposeDirect($trip, $userId, $keyed, $cands, $explicit, $say);
    }

    // ------------------------------------------------------------------------------------------------ bối cảnh

    private function context(object $trip): array
    {
        $payload = TripHealth::withWeather($trip, $this->planner->payload($trip));
        $health = $this->health->forTrip($trip);
        $ctx = CopilotContext::build($payload, $health, null, null);
        $keys = $ctx['keys'];
        $time = ItemInsights::timeContext($trip);

        $have = [];
        $destIds = [(int) ($trip->destinationId ?? 0)];
        $centroid = [];
        foreach ($keys as $k => $x) {
            if ($x['refId'] !== null) {
                $have[$x['type'] . ':' . $x['refId']] = $k;
            }
            if ($x['destinationId']) {
                $destIds[] = $x['destinationId'];
            }
            if ($x['day'] !== null && $x['lat'] !== null) {
                $centroid[$x['day']][] = [$x['lat'], $x['lng']];
            }
        }
        foreach ($centroid as $d => $pts) {
            $centroid[$d] = [array_sum(array_column($pts, 0)) / count($pts), array_sum(array_column($pts, 1)) / count($pts)];
        }
        $load = [];
        foreach ($health['days'] ?? [] as $d) {
            $load[(int) $d['day']] = (int) $d['loadPct'];
        }
        $start = $time['tripStart'] ? new DateTimeImmutable($time['tripStart']) : null;
        $labels = [];
        for ($d = 1; $d <= $ctx['days']; $d++) {
            $labels[$d] = 'Ngày ' . $d . ($start ? ' · ' . $start->modify('+' . ($d - 1) . ' days')->format('d/m') : '');
        }

        return ['trip' => $trip, 'payload' => $payload, 'health' => $health, 'keys' => $keys, 'days' => $ctx['days'], 'dayLabels' => $labels,
            'have' => $have, 'destIds' => array_values(array_unique(array_filter($destIds))), 'centroid' => $centroid, 'load' => $load,
            'time' => $time, 'start' => $start, 'dows' => $ctx['dows']];
    }

    /** Ngày nên thêm: gần lịch ngày đó nhất, tránh ngày đã quá dày; không có tọa độ thì chọn ngày nhẹ nhất. */
    private function bestDay(array $c, ?float $lat, ?float $lng, ?int $prefer = null): ?int
    {
        if ($c['days'] < 1) {
            return null;
        }
        if ($prefer) {
            return $prefer;
        }
        $best = null;
        $score = PHP_FLOAT_MAX;
        for ($d = 1; $d <= $c['days']; $d++) {
            $km = $lat !== null && isset($c['centroid'][$d]) ? HaversineProvider::straightKm($c['centroid'][$d], [$lat, $lng]) : 15.0;
            $s = $km + max(0, ($c['load'][$d] ?? 0) - 80) * 0.5;
            if ($s < $score) {
                $score = $s;
                $best = $d;
            }
        }

        return $best;
    }

    private function nearKm(array $c, ?int $day, ?float $lat, ?float $lng): ?float
    {
        return $day && $lat !== null && isset($c['centroid'][$day]) ? round(HaversineProvider::straightKm($c['centroid'][$day], [$lat, $lng]), 1) : null;
    }

    private static function excerpt(?string $s, int $n = 150): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s)) ?? '');

        return Str::limit($s, $n, '…');
    }

    private static function inSeason($from, $to, array $months): ?bool
    {
        if (!$from || !$to || !$months) {
            return null;
        }
        foreach ($months as $m) {
            if ($from <= $to ? ($m >= $from && $m <= $to) : ($m >= $from || $m <= $to)) {
                return true;
            }
        }

        return false;
    }

    private function addActions(array $c, string $type, int $refId, ?int $best): array
    {
        return [['label' => $best ? 'Thêm vào ngày ' . $best : 'Thêm vào Đã lưu', 'primary' => true, 'pickDay' => true,
            'apply' => ['op' => 'add', 'type' => $type, 'refId' => $refId, 'toDay' => $best]]];
    }

    // ------------------------------------------------------------------------------------------------ món ăn

    private function food(array $c, ?int $day): array
    {
        $cards = [];
        if ($c['destIds']) {
            $q = DB::table('tbl_foods as f')->where('f.reviewStatus', 'published')
                ->where(function ($w) use ($c) {
                    $w->whereIn('f.destinationId', $c['destIds']);
                    if (Schema::hasTable('tbl_food_destination')) {
                        $w->orWhereIn('f.foodId', DB::table('tbl_food_destination')->whereIn('destinationId', $c['destIds'])->select('foodId'));
                    }
                });
            $hasPlace = Schema::hasColumn('tbl_foods', 'placeId');
            if ($hasPlace) {
                $q->leftJoin('tbl_external_places as p', function ($j) {
                    $j->on('p.placeId', '=', 'f.placeId')->where('p.reviewStatus', 'published');
                });
            }
            $rows = $q->orderByDesc('f.isMustTry')->orderBy('f.name')->limit(40)
                ->get(array_merge(['f.foodId', 'f.slug', 'f.name', 'f.category', 'f.description', 'f.coverImage', 'f.isMustTry', 'f.monthFrom', 'f.monthTo', 'f.whereToTry'],
                    $hasPlace ? ['p.name as pName', 'p.latitude as pLat', 'p.longitude as pLng'] : []));
            $list = [];
            foreach ($rows as $f) {
                if (isset($c['have']['food:' . $f->foodId])) {
                    continue;
                }
                $season = self::inSeason($f->monthFrom, $f->monthTo, $c['time']['months']);
                if ($season === false) {
                    continue;   // trái mùa trong dịp đi: không gợi ý
                }
                $lat = isset($f->pLat) && $f->pLat !== null ? (float) $f->pLat : null;
                $lng = $lat !== null ? (float) $f->pLng : null;
                $best = $this->bestDay($c, $lat, $lng, $day);
                $tags = [];
                if ($f->isMustTry) {
                    $tags[] = ['t' => 'Nên thử', 'tone' => 'hot'];
                }
                if ($season) {
                    $tags[] = ['t' => 'Đúng mùa', 'tone' => 'ok'];
                }
                if ($km = $this->nearKm($c, $best, $lat, $lng)) {
                    $tags[] = ['t' => '~' . str_replace('.', ',', (string) $km) . ' km từ lịch ngày ' . $best, 'tone' => ''];
                }
                $where = trim((string) ($f->whereToTry ?? '')) ?: (isset($f->pName) && $f->pName ? 'Quán gợi ý: ' . $f->pName : '');
                $list[] = ['id' => 'food:' . $f->foodId, 'kind' => 'food', 'title' => $f->name, 'sub' => (string) ($f->category ?: 'Món địa phương'),
                    'text' => self::excerpt($f->description) . ($where ? "\n" . Str::limit($where, 120, '…') : ''), 'tags' => $tags,
                    'img' => $f->coverImage ? asset($f->coverImage) : null, 'url' => route('foods.show', ['slug' => $f->slug]),
                    'actions' => $this->addActions($c, 'food', (int) $f->foodId, $best), 'score' => ($f->isMustTry ? 2 : 0) + ($season ? 1 : 0)];
            }
            usort($list, fn($a, $b) => $b['score'] <=> $a['score']);
            $cards = array_slice($list, 0, 4);
        }
        // quán ăn thật gần lịch (tbl_external_places nhóm 'eat')
        $eat = $this->placeRows($c, (array) config('planner.kind_groups.eat', ['restaurant']), $day, 3);
        foreach ($eat as $p) {
            $cards[] = $p;
        }
        $say = $cards
            ? 'Đây là món nên thử' . ($day ? ' quanh lịch ngày ' . $day : ' ở điểm đến của chuyến') . '. Bấm “Thêm” để La bàn kiểm tra giờ và quãng đường trước khi đưa vào lịch.'
            : 'Travela chưa có món ăn đã duyệt cho điểm đến này. Bạn xem thêm ở trang Ẩm thực hoặc thêm quán tự chọn nhé.';

        return [$say, array_map(fn($x) => array_diff_key($x, ['score' => 1]), $cards)];
    }

    // ------------------------------------------------------------------------------------------------ địa điểm

    private function places(array $c, ?int $day): array
    {
        $kinds = array_merge(...array_map(fn($g) => (array) config('planner.kind_groups.' . $g, []), ['see', 'culture', 'nature', 'fun']));
        $cards = $this->placeRows($c, $kinds, $day, self::LIMIT - 2);
        // trải nghiệm đã duyệt (đúng mùa)
        if ($c['destIds']) {
            foreach (DB::table('tbl_experiences')->where('reviewStatus', 'published')->whereIn('destinationId', $c['destIds'])
                ->orderByDesc('isMustTry')->limit(20)->get() as $x) {
                if (isset($c['have']['experience:' . $x->experienceId]) || self::inSeason($x->monthFrom, $x->monthTo, $c['time']['months']) === false) {
                    continue;
                }
                $lat = $x->latitude !== null ? (float) $x->latitude : null;
                $best = $this->bestDay($c, $lat, $lat !== null ? (float) $x->longitude : null, $day);
                $tags = array_values(array_filter([
                    $x->isMustTry ? ['t' => 'Nên thử', 'tone' => 'hot'] : null,
                    $x->indoor ? ['t' => 'Trong nhà', 'tone' => 'ok'] : null,
                    $x->duration ? ['t' => (string) $x->duration, 'tone' => ''] : null,
                ]));
                $cards[] = ['id' => 'experience:' . $x->experienceId, 'kind' => 'place', 'title' => $x->name, 'sub' => 'Trải nghiệm' . ($x->category ? ' · ' . $x->category : ''),
                    'text' => self::excerpt($x->description), 'tags' => $tags, 'img' => $x->coverImage ? asset($x->coverImage) : null,
                    'url' => route('experiences.show', ['slug' => $x->slug]), 'actions' => $this->addActions($c, 'experience', (int) $x->experienceId, $best)];
                if (count($cards) >= self::LIMIT) {
                    break;
                }
            }
        }
        $say = $cards
            ? 'Một vài nơi chưa có trong lịch' . ($day ? ', ưu tiên gần lịch ngày ' . $day : '') . '. La bàn chọn ngày gần nhất, bạn đổi ngày được trước khi thêm.'
            : 'Chưa có địa điểm đã duyệt nào khác cho điểm đến này. Bạn thử trang Khám phá nhé.';

        return [$say, $cards];
    }

    /** Địa điểm đã duyệt (tbl_external_places) chưa có trong chuyến, gần lịch, kèm giờ mở cửa đúng thứ của ngày gợi ý. */
    private function placeRows(array $c, array $kinds, ?int $day, int $limit): array
    {
        if (!$c['destIds'] || !$kinds) {
            return [];
        }
        $rows = DB::table('tbl_external_places')->where('reviewStatus', 'published')->whereIn('destinationId', $c['destIds'])
            ->whereIn('kind', $kinds)->orderByDesc('isMustTry')->orderByDesc('rank')->limit(80)
            ->get(['placeId', 'name', 'kind', 'latitude', 'longitude', 'isMustTry', 'address', 'description']);
        $list = [];
        foreach ($rows as $r) {
            if (isset($c['have']['place:' . $r->placeId])) {
                continue;
            }
            $lat = $r->latitude !== null ? (float) $r->latitude : null;
            $lng = $lat !== null ? (float) $r->longitude : null;
            $best = $this->bestDay($c, $lat, $lng, $day);
            $km = $this->nearKm($c, $best, $lat, $lng);
            $list[] = ['r' => $r, 'best' => $best, 'km' => $km, 'score' => ($r->isMustTry ? -3 : 0) + ($km ?? 6)];
        }
        usort($list, fn($a, $b) => $a['score'] <=> $b['score']);
        $list = array_slice($list, 0, $limit);
        $hours = app(\App\Services\Planner\OpeningHours::class)->forItems(array_map(fn($x) => ['type' => 'place', 'refId' => (int) $x['r']->placeId], $list));

        $out = [];
        foreach ($list as $x) {
            $r = $x['r'];
            $h = \App\Services\Planner\OpeningHours::describe($hours['place:' . $r->placeId] ?? null, $x['best'] ? ($c['dows'][$x['best']] ?? null) : null);
            $tags = array_values(array_filter([
                $r->isMustTry ? ['t' => 'Nên thử', 'tone' => 'hot'] : null,
                $x['km'] !== null ? ['t' => '~' . str_replace('.', ',', (string) $x['km']) . ' km từ lịch ngày ' . $x['best'], 'tone' => ''] : null,
                $h && $x['best'] ? ['t' => 'Ngày ' . $x['best'] . ': ' . $h, 'tone' => $h === 'đóng cửa' ? 'bad' : ''] : null,
            ]));
            $out[] = ['id' => 'place:' . $r->placeId, 'kind' => 'place', 'title' => $r->name, 'sub' => (string) (PlaceKind::label($r->kind) ?? 'Địa điểm'),
                'text' => self::excerpt($r->description ?: $r->address, 120), 'tags' => $tags, 'img' => null, 'url' => null,
                'actions' => $this->addActions($c, 'place', (int) $r->placeId, $x['best'])];
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ lễ hội

    private function festival(array $c): array
    {
        $start = $c['start'];
        $end = $start ? $start->modify('+' . max(0, $c['days'] - 1) . ' days') : null;
        $rows = DB::table('tbl_events')->where('reviewStatus', 'published')
            ->where(fn($w) => $w->whereIn('destinationId', $c['destIds'] ?: [0])->orWhereNull('destinationId'))->limit(60)->get();
        $cards = [];
        foreach ($rows as $e) {
            if (isset($c['have']['event:' . $e->eventId])) {
                continue;
            }
            $from = $start ? $start->modify('-10 days') : $this->monthStart($c['time']['month']);
            $hit = null;
            foreach ($this->dates->upcoming($e, $from, 4) as $r) {
                $rs = new DateTimeImmutable($r['start']);
                $re = new DateTimeImmutable($r['end']);
                if ($start) {
                    $overlap = $rs <= $end && $re >= $start;
                    $gap = $re < $start ? (int) $re->diff($start)->days : ($rs > $end ? (int) $end->diff($rs)->days : 0);
                    if ($overlap || $gap <= 7) {
                        $hit = $r + ['overlap' => $overlap, 'gap' => $gap, 'after' => $rs > $end];
                        break;
                    }
                } elseif ($c['time']['months'] && in_array((int) $rs->format('n'), $c['time']['months'], true)) {
                    $hit = $r + ['overlap' => true, 'gap' => 0, 'after' => false];
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $rs = new DateTimeImmutable($hit['start']);
            $re = new DateTimeImmutable($hit['end']);
            $when = $rs->format('d/m') . ($hit['end'] !== $hit['start'] ? '–' . $re->format('d/m') : '') . ($hit['approx'] ? ' (dự kiến, chờ ban tổ chức công bố)' : '');
            $tripDay = null;
            if ($start && $hit['overlap']) {
                $first = max($rs, $start);
                $tripDay = (int) $start->diff($first)->days + 1;
            }
            $tags = [$hit['overlap'] ? ['t' => $tripDay ? 'Trùng ngày ' . $tripDay . ' của chuyến' : 'Trong tháng đi', 'tone' => 'hot']
                : ['t' => ($hit['after'] ? 'Sau chuyến ' : 'Trước chuyến ') . $hit['gap'] . ' ngày', 'tone' => '']];
            $actions = [];
            if ($hit['overlap']) {
                $actions = $this->addActions($c, 'event', (int) $e->eventId, $tripDay ?: $this->bestDay($c, null, null));
            }
            $actions[] = ['label' => 'Xem chi tiết', 'url' => route('events.show', ['slug' => $e->slug])];
            $cards[] = ['id' => 'event:' . $e->eventId, 'kind' => 'event', 'title' => $e->name, 'sub' => 'Lễ hội · ' . $when,
                'text' => self::excerpt($e->description) . ($e->dateNote ? "\n" . Str::limit((string) $e->dateNote, 120, '…') : ''), 'tags' => $tags,
                'img' => $e->coverImage ? asset($e->coverImage) : null, 'url' => route('events.show', ['slug' => $e->slug]), 'actions' => $actions,
                'score' => ($hit['overlap'] ? 0 : 10) + $hit['gap']];
        }
        usort($cards, fn($a, $b) => $a['score'] <=> $b['score']);
        $cards = array_map(fn($x) => array_diff_key($x, ['score' => 1]), array_slice($cards, 0, self::LIMIT));
        $say = !$start && !$c['time']['months']
            ? 'Chuyến chưa có ngày đi nên La bàn chưa đối chiếu được lễ hội. Bạn đặt ngày khởi hành rồi hỏi lại nhé.'
            : ($cards
                ? 'Lễ hội trong hoặc sát dịp đi của bạn (ngày âm lịch đã quy ra dương lịch). Ngày “dự kiến” nên kiểm tra lại với ban tổ chức.'
                : 'Không có lễ hội nào trong kho Travela trùng dịp đi này. Bạn xem lịch lễ hội cả năm ở trang Lễ hội.');

        return [$say, $cards];
    }

    // ------------------------------------------------------------------------------------------------ cảnh báo chủ động

    /** Số cảnh báo cần xem (badge trên nút La bàn) — dùng chung luồng alerts. */
    public function alertCount(object $trip): int
    {
        return count($this->alerts($this->context($trip))[1]);
    }

    private function alerts(array $c): array
    {
        $cards = [];
        $byPos = [];
        foreach ($c['keys'] as $k => $x) {
            if ($x['day'] !== null) {
                $byPos[$x['day'] . ':' . $x['index']] = $k;
            }
        }
        $wDays = $c['payload']['weather']['days'] ?? [];
        $dryDay = function (int $not) use ($wDays, $c): ?int {
            $best = null;
            $p = 101;
            foreach ($wDays as $n => $wd) {
                $r = TripWeather::dayRain($wd);
                if ((int) $n !== $not && $r < $p && ($c['load'][$n] ?? 0) < 100) {
                    $p = $r;
                    $best = (int) $n;
                }
            }

            return $best !== null && $p < (int) config('planner.weather.rain_warn', 60) ? $best : null;
        };
        $allItems = array_values(array_map(fn($x) => ['type' => $x['type'], 'refId' => $x['refId']], $c['keys']));
        $seen = [];

        foreach ($c['health']['checks'] ?? [] as $ch) {
            $code = (string) ($ch['code'] ?? '');
            if (!in_array($code, ['weather.storm', 'weather.rain', 'weather.heat', 'hours.closed'], true) || ($ch['level'] ?? '') === 'info') {
                continue;
            }
            $key = isset($ch['day'], $ch['index']) ? ($byPos[$ch['day'] . ':' . $ch['index']] ?? null) : null;
            if (!$key || isset($seen[$code . $key])) {
                continue;
            }
            $seen[$code . $key] = true;
            $it = $c['keys'][$key];
            $d = (int) $ch['day'];
            $actions = [];
            if ($code === 'weather.storm' || $code === 'weather.rain') {
                $dayItems = array_values(array_filter($c['keys'], fn($x) => $x['day'] === $d));
                foreach ($this->rain->suggest($c['destIds'], $allItems, $dayItems, [], 2) as $alt) {
                    $actions[] = ['label' => 'Thay bằng ' . $alt['name'], 'primary' => !$actions, 'hint' => $alt['sub'] ?: 'Trong nhà',
                        'apply' => ['op' => 'replace', 'item' => $key, 'type' => $alt['type'], 'refId' => (int) $alt['refId']]];
                }
                if ($to = $dryDay($d)) {
                    $actions[] = ['label' => 'Dời sang ngày ' . $to . ' (khô ráo hơn)', 'apply' => ['op' => 'move', 'item' => $key, 'toDay' => $to]];
                }
            } elseif ($code === 'weather.heat') {
                $actions[] = ['label' => 'Dời sang 07:30', 'primary' => true, 'apply' => ['op' => 'time', 'item' => $key, 'startMin' => 450]];
                $actions[] = ['label' => 'Dời sang 16:00', 'apply' => ['op' => 'time', 'item' => $key, 'startMin' => 960]];
            } else {
                $actions[] = ['label' => 'Nhờ La bàn sắp lại ngày ' . $d, 'primary' => true, 'prompt' => 'Ngày ' . $d . ' có ' . $it['name'] . ' đóng cửa, sửa giúp tôi.'];
                $actions[] = ['label' => 'Chuyển vào Đã lưu', 'apply' => ['op' => 'move', 'item' => $key, 'toDay' => null]];
            }
            $title = match ($code) {
                'weather.storm' => 'Dông / gió mạnh ngày ' . $d,
                'weather.rain' => 'Có thể mưa ngày ' . $d,
                'weather.heat' => 'Nắng nóng giữa trưa ngày ' . $d,
                default => 'Đóng cửa đúng ngày ' . $d,
            };
            $cards[] = ['id' => 'alert:' . $code . ':' . $key, 'kind' => 'alert', 'tone' => in_array($code, ['weather.storm', 'hours.closed'], true) ? 'bad' : 'warn',
                'title' => $title, 'sub' => $it['name'], 'text' => (string) $ch['text'], 'tags' => [], 'day' => $d, 'item' => $key,
                'source' => str_starts_with($code, 'weather.') ? 'Dự báo theo giờ Open-Meteo' : 'Giờ mở cửa đã duyệt',
                'actions' => $actions ?: [['label' => 'Hỏi La bàn cách xử lý', 'prompt' => 'Ngày ' . $d . ' ' . mb_strtolower($title) . ', nên đổi thế nào?']]];
        }

        foreach ($this->travelAlerts($c) as $a) {
            $cards[] = $a;
        }
        usort($cards, fn($a, $b) => [($a['tone'] ?? '') === 'bad' ? 0 : 1, $a['day'] ?? 99] <=> [($b['tone'] ?? '') === 'bad' ? 0 : 1, $b['day'] ?? 99]);

        $fc = ($c['payload']['weather']['source'] ?? 'none') === 'forecast';
        $say = $cards
            ? 'La bàn thấy ' . count($cards) . ' điều nên xem trước khi đi. Mỗi gợi ý đổi lịch đều được kiểm tra lại giờ mở cửa và quãng đường.'
            : ($fc ? 'Chưa thấy dông, mưa lớn, nắng nóng hay sự cố nào trùng giờ các hoạt động ngoài trời. La bàn sẽ nhắc nếu dự báo đổi.'
                : 'Chuyến còn xa (dự báo theo giờ chỉ có trong 16 ngày tới) và chưa có cảnh báo điểm đến nào trùng dịp đi.');

        return [$say, $cards];
    }

    /** Cảnh báo điểm đến do Travela nhập, trùng khoảng ngày đi và (điểm đến hoặc địa điểm) của chuyến. */
    private function travelAlerts(array $c): array
    {
        if (!Schema::hasTable('tbl_travel_alerts')) {
            return [];
        }
        $start = $c['start'] ?? null;
        $from = $start ? $start->format('Y-m-d') : now()->toDateString();
        $to = $start ? $start->modify('+' . max(0, $c['days'] - 1) . ' days')->format('Y-m-d') : now()->addDays(30)->toDateString();
        $placeIds = [];
        foreach ($c['have'] as $k => $key) {
            if (str_starts_with($k, 'place:')) {
                $placeIds[(int) substr($k, 6)] = $key;
            }
        }
        $rows = DB::table('tbl_travel_alerts')->where('isActive', 1)->where('startsOn', '<=', $to)
            ->where(fn($w) => $w->whereNull('endsOn')->orWhere('endsOn', '>=', $from))
            ->where(function ($w) use ($c, $placeIds) {
                $w->whereIn('destinationId', $c['destIds'] ?: [0]);
                if ($placeIds) {
                    $w->orWhereIn('placeId', array_keys($placeIds));
                }
            })->orderBy('startsOn')->limit(6)->get();
        $out = [];
        foreach ($rows as $a) {
            $key = $a->placeId && isset($placeIds[(int) $a->placeId]) ? $placeIds[(int) $a->placeId] : null;
            $day = $key ? $c['keys'][$key]['day'] : null;
            if (!$day && $start) {
                $s = new DateTimeImmutable(max($from, $a->startsOn));
                $day = (int) $start->diff($s)->days + 1;
            }
            $actions = [];
            if ($key) {
                $actions[] = ['label' => 'Nhờ La bàn tìm nơi thay ' . $c['keys'][$key]['name'], 'primary' => true,
                    'prompt' => 'Thay ' . $c['keys'][$key]['name'] . ' bằng một nơi khác gần đó' . ($day ? ' trong ngày ' . $day : '') . '.'];
                $actions[] = ['label' => 'Chuyển vào Đã lưu', 'apply' => ['op' => 'move', 'item' => $key, 'toDay' => null]];
            } elseif ($day) {
                $actions[] = ['label' => 'Hỏi La bàn nên đổi gì', 'prompt' => 'Ngày ' . $day . ' có cảnh báo: ' . $a->title . '. Nên điều chỉnh lịch thế nào?'];
            }
            if ($a->sourceUrl) {
                $actions[] = ['label' => 'Xem nguồn', 'url' => $a->sourceUrl];
            }
            $when = date('d/m', strtotime($a->startsOn)) . ($a->endsOn ? '–' . date('d/m', strtotime($a->endsOn)) : ' (chưa rõ ngày kết thúc)');
            $out[] = ['id' => 'travel:' . $a->alertId, 'kind' => 'alert', 'tone' => $a->level === 'bad' ? 'bad' : ($a->level === 'info' ? 'info' : 'warn'),
                'title' => $a->title, 'sub' => $key ? $c['keys'][$key]['name'] . ' · ' . $when : $when, 'text' => trim($a->detail . ($a->advice ? "\nNên: " . $a->advice : '')),
                'tags' => [], 'day' => $day, 'item' => $key, 'source' => 'Cảnh báo Travela', 'actions' => $actions];
        }

        return $out;
    }

    private function monthStart(?int $m): DateTimeImmutable
    {
        $now = new DateTimeImmutable('first day of this month');
        if (!$m) {
            return $now;
        }
        $y = (int) $now->format('Y') + ($m < (int) $now->format('n') ? 1 : 0);

        return new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));
    }

    private function more(string $intent): array
    {
        $all = ['food' => ['Món nên thử', 'food'], 'places' => ['Thêm điểm tham quan', 'places'], 'festival' => ['Lễ hội dịp đi', 'festival'], 'alerts' => ['Cảnh báo thời tiết, sự cố', 'alerts']];
        unset($all[$intent]);

        return array_values(array_map(fn($x) => ['label' => $x[0], 'intent' => $x[1]], $all));
    }
}
