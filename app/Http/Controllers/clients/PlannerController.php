<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\TripTray;
use App\Services\Content\SearchIntent;
use App\Services\Planner\DestinationFinder;
use App\Services\Planner\PlaceFinder;
use App\Services\Planner\PlaceGeocoder;
use App\Services\Planner\PlaceQuery;
use App\Services\Planner\PlannerGate;
use App\Services\Planner\PlannerRules;
use App\Services\Planner\PlannerService;
use App\Services\Planner\RainPlan;
use App\Services\Planner\RouteExplorer;
use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\Health\TripResolver;
use App\Services\Planner\Route\RouteService;
use App\Services\Planner\TourMatch;
use App\Services\Planner\TripConflict;
use App\Services\Planner\TripSharing;
use App\Services\Planner\UnknownPlace;
use App\Services\Planner\OpeningHours;
use App\Services\Planner\TripPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\clients\TravelController;

/**
 * Trip Planner (web). Các route có đăng nhập nằm trong nhóm checkLoginClient; chỉ trang chia sẻ chỉ đọc là công khai.
 */
class PlannerController extends Controller
{
    private function uid(): int
    {
        return (int) session()->get('userId');
    }

    /** Tham số của trang "Travela đề xuất" (link cũ từ trang chủ và trang tìm kiếm vẫn dùng /len-ke-hoach?...). */
    private const SUGGEST_KEYS = ['q', 'thang', 'diem-den', 'ngay', 'nguoi', 'ngan-sach', 'so-thich', 'khong-thich'];

    /**
     * GET /len-ke-hoach : "Chuyến đi của tôi" + form tạo chuyến mới (tự lên kế hoạch). Link có tham số của phần đề xuất
     * (?q=, ?thang=, ?diem-den=...) chuyển sang trang riêng /len-ke-hoach/de-xuat để hai việc không lẫn vào nhau.
     */
    public function index(Request $request, TripPlanner $planner, TripTray $tray)
    {
        if ($request->hasAny(self::SUGGEST_KEYS)) {
            return redirect()->route('planner.suggest', $request->query());
        }
        $uid = $this->uid();

        return view('clients.planner.index', [
            'title' => 'Lên kế hoạch chuyến đi',
            'metaDescription' => 'Xếp các điểm đến, món ăn, lễ hội và tour của bạn vào từng ngày, xem quãng đường và chi phí dự kiến.',
            'noindex' => true,
            'trips' => $planner->trips($uid),
            'sharedTrips' => app(TripSharing::class)->sharedWithMe($uid),
            'tray' => $tray->items($uid),
            'allDests' => DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->limit(300)->get(['destinationId', 'name']),
            // gợi ý nhanh ở bước "Đi đâu?": điểm đến nổi bật trước
            'popularDests' => DB::table('tbl_destinations')->where('isActive', 1)->orderByDesc('isFeatured')->orderByDesc('isHero')->orderByDesc('isMustTry')->orderBy('name')->limit(8)->get(['destinationId', 'name']),
        ]);
    }

    /** GET /len-ke-hoach/de-xuat : Travela đề xuất lịch trình từ dữ liệu đã duyệt (điền sẵn từ ?q= hoặc các tham số do trang tìm kiếm gắn vào). */
    public function suggest(Request $request, PlannerGate $gate, SearchIntent $intent)
    {
        $month = (int) $request->query('thang');
        $pre = [
            'destination' => null,
            'month' => $month >= 1 && $month <= 12 ? $month : null,
            'days' => null,
            'groupSize' => null,
            'budget' => null,
            'interests' => [],
            'dislikes' => [],
            'adults' => null,
            'children' => null,
            'startDate' => null,
            'pace' => null,
            'maxDifficulty' => null
        ];
        $pre['place'] = null;
        if ($request->query('destinationId') || $request->query('placeLat')) {
            // quay lại từ màn xem trước ("Sửa yêu cầu") hoặc từ "đề xuất mở rộng quanh nơi này": điền lại đúng các lựa chọn
            $qv = $request->query();
            if (!$request->query('destinationId') && is_numeric($qv['placeLat'] ?? null) && is_numeric($qv['placeLng'] ?? null)) {
                $pre['place'] = [
                    'name' => mb_substr(trim((string) ($qv['placeName'] ?? '')), 0, 120) ?: 'Nơi đã chọn',
                    'lat' => max(-90, min(90, (float) $qv['placeLat'])),
                    'lng' => max(-180, min(180, (float) $qv['placeLng']))
                ];
            }
            $pre = array_merge($pre, [
                'destination' => !empty($qv['destinationId']) ? DB::table('tbl_destinations')->where('destinationId', (int) $qv['destinationId'])->value('slug') : null,
                'month' => isset($qv['month']) ? (int) $qv['month'] : null,
                'days' => isset($qv['days']) ? (int) $qv['days'] : null,
                'adults' => isset($qv['adults']) ? (int) $qv['adults'] : null,
                'children' => isset($qv['children']) ? (int) $qv['children'] : null,
                'budget' => isset($qv['budget']) && $qv['budget'] !== '' ? (int) $qv['budget'] : null,
                'startDate' => is_string($qv['startDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qv['startDate']) ? $qv['startDate'] : null,
                'pace' => is_string($qv['pace'] ?? null) ? $qv['pace'] : null,
                'maxDifficulty' => is_string($qv['maxDifficulty'] ?? null) ? $qv['maxDifficulty'] : null,
                'interests' => array_values(array_intersect((array) ($qv['interests'] ?? []), array_keys((array) config('interests')))),
                'dislikes' => array_values(array_intersect((array) ($qv['dislikes'] ?? []), array_keys((array) config('planner.dislikes')))),
                'notes' => is_string($qv['notes'] ?? null) ? mb_substr(strip_tags($qv['notes']), 0, 500) : null,
            ]);
        } elseif (is_string($q = $request->query('q')) && trim($q) !== '') {
            $i = $intent->parse(mb_substr($q, 0, 200));
            $pre = [
                'destination' => $i['destination']['slug'] ?? null,
                'month' => $i['month'],
                'days' => $i['days'],
                'groupSize' => $i['groupSize'],
                'budget' => $i['budget'],
                'interests' => $i['interests'],
                'dislikes' => $i['dislikes']
            ];
        } else {
            $pre['destination'] = is_string($request->query('diem-den')) ? $request->query('diem-den') : null;
            $pre['days'] = (int) $request->query('ngay') ?: null;
            $pre['groupSize'] = (int) $request->query('nguoi') ?: null;
            $pre['budget'] = (int) $request->query('ngan-sach') ?: null;
            $pre['interests'] = array_values(array_intersect((array) $request->query('so-thich', []), array_keys((array) config('interests'))));
            $pre['dislikes'] = array_values(array_intersect((array) $request->query('khong-thich', []), array_keys((array) config('planner.dislikes'))));
        }

        // Người dùng gõ một nơi mà Travela chưa có dữ liệu: không báo lỗi, gợi ý "ý bạn là", điểm đến gần nhất và cách lập thủ công
        $unknown = null;
        if (isset($i) && $i['destination'] === null && !empty($i['terms'])) {
            $term = implode(' ', array_slice($i['terms'], 0, 4));
            try {
                $unknown = app(UnknownPlace::class)->resolve($term);
                $unknown['query'] = mb_substr(trim((string) $request->query('q')), 0, 120);
            } catch (\Throwable $e) {
                $unknown = null;   // dịch vụ ngoài lỗi thì bỏ qua gợi ý, trang vẫn dùng bình thường
            }
        }

        $dests = $gate->coreStatus();
        // mọi điểm đến đang bật cho ô "Đi đâu?" (lọc ngay ở trình duyệt); điểm đến lõi có cờ sẵn sàng, còn lại dùng đề xuất mở rộng
        $readyById = array_column($dests, 'ready', 'destinationId');
        $all = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['destinationId', 'slug', 'name', 'province', 'coverImage', 'latitude', 'longitude'])
            ->map(fn($d) => [
                'id' => (int) $d->destinationId,
                'slug' => $d->slug,
                'name' => $d->name,
                'sub' => (string) $d->province,
                'core' => array_key_exists((int) $d->destinationId, $readyById),
                'ready' => (bool) ($readyById[(int) $d->destinationId] ?? false),
                'img' => $d->coverImage ? self::publicUrl($d->coverImage) : null
            ])->values()->all();

        return view('clients.planner.suggest', [
            'aiOn' => app(\App\Services\Ai\AiGateway::class)->featureOn('brief'),
            'unknown' => $unknown,
            'dests' => $dests,
            'allDests' => $all,
            'openMode' => (bool) config('planner.open_mode.enabled', true),
            'pre' => $pre,
            'notReady' => $this->notReady($pre['destination'], $pre['month'], $dests),
            'title' => 'Để Travela đề xuất lịch trình',
            'metaDescription' => 'Travela xếp sẵn lịch trình từ nội dung đã được biên tập duyệt, bạn chỉnh lại theo ý mình.',
            'noindex' => true,
        ]);
    }

    /**
     * GET /len-ke-hoach/chon-diem-den : "Chưa biết đi đâu?". Chọn tháng và cảm hứng, nhận điểm đến xếp theo độ hợp kèm lý do.
     * Mỗi gợi ý dẫn thẳng sang form tạo lịch trình đã điền sẵn.
     */
    public function discover(Request $request, DestinationFinder $finder)
    {
        $max = (int) config('planner.max_days', 7);
        $ins = DB::table('tbl_inspirations')->where('isActive', 1)->orderBy('displayOrder')->get(['slug', 'name', 'tagline', 'icon']);
        $month = (int) $request->query('thang');
        $month = $month >= 1 && $month <= 12 ? $month : null;
        $picked = array_values(array_intersect(array_map('strval', (array) $request->query('cam-hung', [])), $ins->pluck('slug')->all()));
        $days = (int) $request->query('ngay');
        $days = $days >= 1 && $days <= $max ? $days : null;
        $group = (int) $request->query('nguoi');
        $group = $group >= 1 && $group <= 50 ? $group : null;
        $budget = (float) $request->query('ngan-sach');
        $budget = $budget > 0 && $budget <= 100000000000 ? $budget : null;

        $result = $month ? $finder->recommend(['month' => $month, 'inspirations' => $picked, 'days' => $days, 'groupSize' => $group, 'budget' => $budget]) : null;
        if ($result && $result['items']) {
            $covers = DB::table('tbl_destinations')->whereIn('destinationId', array_column($result['items'], 'destinationId'))->pluck('coverImage', 'destinationId');
            foreach ($result['items'] as &$it) {
                $c = $covers[$it['destinationId']] ?? null;
                $it['img'] = $c ? self::publicUrl($c) : null;
            }
            unset($it);
        }

        return view('clients.planner.discover', [
            'title' => 'Chưa biết đi đâu? Chọn tháng và cảm hứng',
            'metaDescription' => 'Chọn tháng đi và cảm hứng, Travela gợi ý điểm đến hợp nhất kèm lý do từ mùa, khí hậu, lễ hội và tour.',
            'noindex' => true,
            'inspirations' => $ins,
            'picked' => $picked,
            'month' => $month,
            'days' => $days,
            'group' => $group,
            'budget' => $budget,
            'maxDays' => $max,
            'result' => $result,
            'now' => (int) date('n'),
            'openMode' => (bool) config('planner.open_mode.enabled', true),
        ]);
    }

    /** GET /len-ke-hoach/{id}/phuong-an-mua?day=N -> {day, items:[...]} : mục trong nhà để làm dự phòng khi mưa cho ngày N. */
    public function rainSuggest(Request $request, int $id, TripPlanner $planner, RainPlan $rain): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate(['day' => ['required', 'integer', 'between:1,' . TripPlanner::MAX_DAYS], 'have' => ['nullable', 'array', 'max:20'], 'have.*' => ['string', 'max:30']]);
        $day = (int) $v['day'];
        $payload = $planner->payload($trip);
        $all = array_merge($payload['unassigned'], ...array_map(fn($d) => $d['items'], $payload['days']));
        $dayItems = $payload['days'][$day - 1]['items'] ?? [];
        $destIds = array_unique(array_filter(array_merge(
            [(int) ($trip->destinationId ?? 0)],
            array_map(fn($x) => (int) ($x['destinationId'] ?? 0), $all)
        )));
        $exclude = array_values(array_filter((array) ($v['have'] ?? []), fn($k) => preg_match('/^(experience|place):\d+$/', $k)));

        return response()->json(['day' => $day, 'items' => $rain->suggest($destIds, $all, $dayItems, $exclude)]);
    }

    /** POST /tour/{id}/len-ke-hoach : tạo chuyến đi có sẵn ngày, số ngày, điểm đến và chính tour đó. */
    public function fromTour(Request $request, int $id, TripPlanner $planner)
    {
        $group = (int) $request->input('groupSize', 2);
        try {
            $r = $planner->createFromTour($this->uid(), $id, $group);
        } catch (\DomainException $e) {
            return redirect()->route('tour-detail', ['id' => $id])->with('planner_error', $e->getMessage());
        }

        return redirect()->route('planner.edit', ['id' => $r['tripId']])
            ->with('planner_notice', 'Đã tạo chuyến đi quanh tour. Thêm món ăn, lễ hội và trải nghiệm quanh nơi đến ở ô tìm kiếm, rồi quay lại đặt tour khi đã vừa ý.');
    }

    /** Quy tắc kiểm tra yêu cầu tự đề xuất (dùng chung cho xem trước và lưu). */
    private function generateRules(): array
    {
        $maxDays = (int) config('planner.max_days', 7);

        return [
            'destinationId' => ['required_without:placeLat', 'nullable', 'integer', 'min:1'],
            'placeName' => ['nullable', 'string', 'max:120'],
            'placeLat' => ['required_without:destinationId', 'nullable', 'numeric', 'between:-90,90', 'required_with:placeLng'],
            'placeLng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:placeLat'],
            'mode' => ['nullable', 'in:auto,curated,open'],
            'startDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:' . now()->subDay()->toDateString(), 'before:' . now()->addYears(2)->toDateString()],
            'month' => ['required_without:startDate', 'nullable', 'integer', 'between:1,12'],
            'days' => ['required', 'integer', 'between:1,' . $maxDays],
            'adults' => ['required_without:groupSize', 'nullable', 'integer', 'between:1,50'],
            'children' => ['nullable', 'integer', 'between:0,30'],
            'groupSize' => ['nullable', 'integer', 'between:1,50'],
            'budget' => ['nullable', 'numeric', 'between:0,100000000000'],
            'pace' => ['nullable', 'in:' . implode(',', array_keys((array) config('planner.pace')))],
            'maxDifficulty' => ['nullable', 'in:' . implode(',', array_keys((array) config('planner.difficulty_rank')))],
            'interests' => ['nullable', 'array', 'max:6'],
            'interests.*' => ['string', 'in:' . implode(',', array_keys((array) config('interests')))],
            'dislikes' => ['nullable', 'array', 'max:6'],
            'dislikes.*' => ['string', 'in:' . implode(',', array_keys((array) config('planner.dislikes')))],
            // ghi chú từ câu mô tả (La bàn F1: ăn chay, đi cùng ông bà...): lưu vào tbl_trips.notes, F2 đọc lại
            'notes' => ['nullable', 'string', 'max:500'],
            // giờ tới nơi ngày đầu / giờ khởi hành về ngày cuối (không bắt buộc)
            'moveKind' => ['nullable', 'in:flight,train,bus,self'],
            'arriveAt' => ['nullable', 'date_format:H:i'],
            'leaveAt' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** Chuẩn hóa: có ngày đi thì tháng lấy theo ngày đi; số người = người lớn + trẻ em. */
    private static function generateInput(array $data): array
    {
        if (!empty($data['startDate'])) {
            $data['month'] = (int) date('n', strtotime($data['startDate']));
        }
        if (!empty($data['adults'])) {
            $data['children'] = (int) ($data['children'] ?? 0);
            $data['groupSize'] = (int) $data['adults'] + $data['children'];
        }
        // giờ tới / giờ về -> phút chơi được: + thời gian ra khỏi ga / sân bay, - thời gian có mặt sớm (PlannerRules::transfer)
        $data = \App\Services\Planner\TransferParser::minutes($data);

        return $data;
    }

    /**
     * Điểm đến chưa Planner-ready: nói rõ còn thiếu gì và gợi ý điểm đến khác đã sẵn sàng (xếp theo tháng đi bằng DestinationFinder).
     * @return ?array{name:string,slug:string,missing:list<string>,percent:int,alternatives:list<array{slug:string,name:string,why:?string}>}
     */
    private function notReady(?string $slug, ?int $month, array $dests): ?array
    {
        $row = $slug ? collect($dests)->firstWhere('slug', $slug) : null;
        if (!$row || $row['ready']) {
            return null;
        }
        $ready = collect($dests)->where('ready', true);
        $alts = [];
        if ($ready->isNotEmpty() && $month) {
            try {
                foreach (app(DestinationFinder::class)->recommend(['month' => $month, 'limit' => 12])['items'] as $it) {
                    if ($ready->firstWhere('slug', $it['slug'] ?? null)) {
                        $alts[] = ['slug' => $it['slug'], 'name' => $it['name'], 'why' => $it['reasons'][0] ?? null];
                    }
                }
            } catch (\Throwable) {
                $alts = [];
            }
        }
        if (!$alts) {
            $alts = $ready->take(3)->map(fn($d) => ['slug' => $d['slug'], 'name' => $d['name'], 'why' => null])->values()->all();
        }

        return [
            'name' => $row['name'],
            'slug' => $row['slug'],
            'destinationId' => $row['destinationId'],
            'missing' => $row['missing'],
            'percent' => $row['percent'],
            'alternatives' => array_slice($alts, 0, 3),
            'open' => (bool) config('planner.open_mode.enabled', true)
        ];
    }

    /**
     * GET /len-ke-hoach/tim-noi?q= : ô "Đi đâu?" của trang đề xuất. Trả điểm đến Travela khớp tên (kèm mức sẵn sàng)
     * và nơi trên bản đồ (Photon) để đề xuất mở rộng ở bất kỳ đâu. Gõ không dấu vẫn khớp.
     */
    public function placeSearch(Request $request, PlannerGate $gate, PlaceGeocoder $geo): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q']);
        $fold = fn(string $x) => trim(preg_replace('/\s+/', ' ', \Illuminate\Support\Str::ascii(str_replace(['đ', 'Đ'], 'd', mb_strtolower($x)))));
        $needle = $fold($q);
        $ready = [];
        foreach ($gate->coreStatus() as $c) {
            $ready[$c['destinationId']] = $c['ready'];
        }
        $dests = DB::table('tbl_destinations')->where('isActive', 1)->get(['destinationId', 'slug', 'name', 'province', 'latitude', 'longitude'])
            ->filter(fn($d) => str_contains($fold($d->name . ' ' . $d->province . ' ' . $d->slug), $needle))
            ->take(6)->map(fn($d) => [
                'type' => 'destination',
                'id' => (int) $d->destinationId,
                'name' => $d->name,
                'sub' => $d->province,
                'ready' => $ready[(int) $d->destinationId] ?? $gate->isReady((int) $d->destinationId),
                'lat' => $d->latitude !== null ? (float) $d->latitude : null,
                'lng' => $d->longitude !== null ? (float) $d->longitude : null
            ])->values()->all();
        $map = [];
        $ok = true;
        if (count($dests) < 6 && config('planner.open_mode.enabled', true)) {
            $r = $geo->search($q);
            $ok = $r['ok'];
            foreach (array_slice($r['results'], 0, 6) as $p) {
                $map[] = ['type' => 'place', 'name' => $p['name'], 'sub' => trim(($p['kind'] ? $p['kind'] . ' · ' : '') . $p['address'], ' ·'), 'lat' => $p['lat'], 'lng' => $p['lng']];
            }
        }

        return response()->json(['ok' => $ok, 'destinations' => $dests, 'places' => $map]);
    }

    /**
     * GET /len-ke-hoach/de-xuat/xem-truoc : Planner tự đề xuất, CHƯA lưu. Người dùng xem lịch, lý do chọn / loại,
     * rồi "Lưu thành chuyến", "Đổi nhịp đi" hoặc sửa yêu cầu. Engine là hàm thuần nên lưu ra đúng lịch đang xem.
     */
    public function preview(Request $request, PlannerService $service)
    {
        $data = self::generateInput($request->validate($this->generateRules()));
        $result = $service->generate($data);   // không truyền $ignoreGate: điểm đến thiếu dữ liệu bị chặn
        if (empty($result['ok'])) {
            return redirect()->route('planner.suggest', $request->query())->withErrors(['generate' => $result['message'] ?? 'Không thể tạo lịch trình.']);
        }
        $empty = !array_filter($result['plan']['days'], fn($d) => !empty($d['items']));

        return view('clients.planner.preview', [
            'r' => $result,
            'query' => array_filter($request->only(array_filter(array_keys($this->generateRules()), fn($k) => !str_contains($k, '.'))), fn($v) => $v !== null && $v !== '' && $v !== []),
            'empty' => $empty,
            'title' => 'Xem trước lịch trình ' . $result['destination']['name'],
            'noindex' => true,
        ]);
    }

    /**
     * POST /len-ke-hoach/so-sanh : cùng yêu cầu, hai phương án "đi chậm" và "đi nhiều" đặt cạnh nhau với số đo
     * (số điểm, giờ kết thúc trung bình, quãng đường, thời gian đi lại, chi phí đã biết, ngày dày nhất). Không ghi gì.
     */
    public function compare(Request $request, PlannerService $service): JsonResponse
    {
        $data = $this->generateInput($request->validate($this->generateRules()));
        $out = [];
        foreach (['relaxed' => 'Đi chậm', 'packed' => 'Đi nhiều'] as $pace => $label) {
            $r = $service->generate(['pace' => $pace] + $data);
            if (empty($r['ok'])) {
                return response()->json(['ok' => false, 'message' => $r['message'] ?? 'Chưa tạo được phương án.'], 422);
            }
            $acts = 0;
            $km = 0.0;
            $travel = 0;
            $ends = [];
            $busiest = 0;
            foreach ($r['plan']['days'] as $d) {
                $n = 0;
                $end = null;
                foreach ($d['items'] as $it) {
                    $km += (float) ($it['travelKm'] ?? 0);
                    $travel += (int) ($it['travelMin'] ?? 0);
                    if (empty($it['isMeal'])) {
                        $n++;
                    }
                    $end = max($end ?? 0, (int) ($it['startMin'] ?? 0) + (int) ($it['durationMin'] ?? 0));
                }
                $acts += $n;
                $busiest = max($busiest, $n);
                if ($end) {
                    $ends[] = $end;
                }
            }
            $avgEnd = $ends ? (int) round(array_sum($ends) / count($ends)) : null;
            $out[] = ['pace' => $pace, 'label' => $label, 'activities' => $acts, 'perDay' => round($acts / max(1, count($r['plan']['days'])), 1), 'busiest' => $busiest,
                'km' => round($km, 1), 'travelMin' => $travel, 'avgEnd' => $avgEnd !== null ? sprintf('%02d:%02d', intdiv($avgEnd, 60), $avgEnd % 60) : null,
                'cost' => (int) round($r['plan']['budget']['knownMin'] ?? 0), 'costStatus' => $r['plan']['budget']['status'] ?? null, 'unplaced' => count($r['plan']['unplaced'] ?? []),
                'sample' => array_values(array_slice(array_map(fn($it) => $it['name'], array_filter($r['plan']['days'][0]['items'] ?? [], fn($it) => empty($it['isMeal']))), 0, 5))];
        }
        [$a, $b] = $out;
        $why = [];
        if ($b['activities'] <= $a['activities'] && !$a['unplaced'] && !$b['unplaced']) {
            // Hai nhịp ra cùng số điểm: không phải bộ xếp lịch "không khác", mà kho chỉ có chừng ấy nơi hợp điều kiện
            $why[] = 'Cả hai nhịp đều xếp hết ' . $a['activities'] . ' điểm hợp điều kiện mà kho có ở nơi này, nên chỉ khác cách dàn trong ngày. Nới ngân sách, bỏ bớt điều không thích hoặc thêm ngày nghỉ để thấy khác biệt rõ hơn.';
        } else {
            $why[] = 'Đi nhiều có thêm ' . max(0, $b['activities'] - $a['activities']) . ' điểm (' . $b['perDay'] . ' so với ' . $a['perDay'] . ' điểm / ngày).';
        }
        if ($a['avgEnd'] && $b['avgEnd']) {
            $why[] = 'Đi chậm kết thúc ngày trung bình lúc ' . $a['avgEnd'] . ', đi nhiều lúc ' . $b['avgEnd'] . '.';
        }
        $why[] = 'Đi lại: ' . $a['km'] . ' km so với ' . $b['km'] . ' km cả chuyến.';
        if (($data['children'] ?? 0) > 0) {
            $why[] = 'Có trẻ em: nên chọn đi chậm để có giờ nghỉ trưa.';
        }

        return response()->json(['ok' => true, 'options' => $out, 'why' => $why]);
    }

    /** POST /len-ke-hoach/tao-tu-dong : lưu lịch vừa xem trước thành chuyến (tạo lại từ cùng yêu cầu). */
    public function generate(Request $request, PlannerService $service)
    {
        $data = self::generateInput($request->validate($this->generateRules()));
        $result = $service->generate($data);
        if (empty($result['ok'])) {
            return redirect()->route('planner.suggest')->withInput()->withErrors(['generate' => $result['message'] ?? 'Không thể tạo lịch trình.']);
        }
        if (!array_filter($result['plan']['days'], fn($d) => !empty($d['items']))) {
            // Không đủ ứng viên thỏa ràng buộc cứng: không lưu chuyến rỗng, nói rõ lý do (mục 12)
            $why = $result['plan']['warnings'] ?? [];

            return redirect()->route('planner.suggest')->withInput()->withErrors(['generate' => 'Chưa đủ nội dung phù hợp để xếp lịch với điều kiện này. ' . implode(' ', $why) . ' Hãy thử nới ngân sách, bỏ bớt điều không thích hoặc đổi tháng.']);
        }
        try {
            $id = $service->save($this->uid(), $result);
        } catch (\DomainException $e) {
            return redirect()->route('planner.suggest')->withInput()->withErrors(['generate' => $e->getMessage()]);
        }
        if (!empty($data['notes']) && \Illuminate\Support\Facades\Schema::hasColumn('tbl_trips', 'notes')) {
            DB::table('tbl_trips')->where('tripId', $id)->update(['notes' => mb_substr(strip_tags((string) $data['notes']), 0, 500)]);
        }
        self::recordTransfers($id, $data);
        $spare = count(array_filter($result['plan']['unplaced'] ?? [], fn($u) => !empty($u['refId']) || !empty($u['osm'])));
        $how = $result['mode'] === 'open'
            ? ' bằng đề xuất mở rộng: các mục ghi "Ngoài dữ liệu Travela" lấy từ bản đồ OpenStreetMap, nên kiểm tra lại giờ mở cửa và giá trước khi đi. '
            : ' từ nội dung đã được duyệt. ';

        return redirect()->route('planner.edit', ['id' => $id])
            ->with('planner_notice', 'Đã tạo lịch trình ' . $result['destination']['name'] . $how . ($result['plan']['budget']['message'] ?? '')
                . ($spare ? ' ' . $spare . ' mục điểm cao chưa xếp được đang nằm ở khu "Đã lưu", bạn kéo vào ngày nào cũng được.' : ''));
    }

    /**
     * Có ngày đi + giờ tới / giờ về + đi bằng máy bay / tàu / xe khách: ghi hai việc "cần đặt" (vé đi, vé về) để lịch ngày đầu / ngày cuối
     * giữ đúng giờ khi sửa sau này, mục Đặt chỗ nhắc đặt vé, email nhắc khi tới hạn. Tự lái thì không ghi gì.
     */
    public static function recordTransfers(int $tripId, array $data): void
    {
        $kind = $data['moveKind'] ?? 'self';
        if (empty($data['startDate']) || !in_array($kind, \App\Services\Planner\BookingPlan::TRANSPORT, true) || !\App\Services\Planner\BookingPlan::ready()) {
            return;
        }
        $label = ['flight' => 'Vé máy bay', 'train' => 'Vé tàu', 'bus' => 'Vé xe khách'][$kind];
        $start = \Carbon\Carbon::parse($data['startDate']);
        $needBy = \Carbon\Carbon::today()->max($start->copy()->subDays(7))->toDateString();
        $row = fn(string $title, \Carbon\Carbon $at, ?\Carbon\Carbon $end) => ['tripId' => $tripId, 'kind' => $kind, 'provider' => 'manual', 'simulated' => false,
            'title' => $title, 'payload' => json_encode(['manual' => true, 'fromPlanner' => true]), 'startAt' => $at, 'endAt' => $end, 'price' => 0, 'createdAt' => now(),
            'status' => 'planned', 'needBy' => $needBy];
        if (!empty($data['arriveAt'])) {
            // chỉ biết giờ tới: coi giờ khởi hành = giờ tới (người dùng sửa khi đặt vé), giờ tới giữ ở endAt
            $at = $start->copy()->setTimeFromTimeString($data['arriveAt']);
            DB::table('tbl_trip_external')->insert($row($label . ' đi (tới ' . $data['arriveAt'] . ')', $at, $at));
        }
        if (!empty($data['leaveAt'])) {
            $at = $start->copy()->addDays(max(1, (int) $data['days']) - 1)->setTimeFromTimeString($data['leaveAt']);
            DB::table('tbl_trip_external')->insert($row($label . ' về (' . $data['leaveAt'] . ')', $at, null));
        }
    }

    /** POST /len-ke-hoach/{id}/trang-thai {status} */
    public function status(Request $request, int $id, TripPlanner $planner)
    {
        $request->validate(['status' => ['required', 'in:draft,planned,done']]);
        $planner->setStatus($this->uid(), $id, (string) $request->input('status')) || abort(404);

        return redirect()->route('planner');
    }

    /** POST /len-ke-hoach */
    public function store(Request $request, TripPlanner $planner)
    {
        $data = $request->validate(TripPlanner::tripRules());
        try {
            $id = $planner->create($this->uid(), $data, $request->boolean('fromTray'));
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        }

        return redirect()->route('planner.edit', ['id' => $id]);
    }

    /** GET /len-ke-hoach/{id} */
    public function edit(int $id, TripPlanner $planner, PlaceGeocoder $geo)
    {
        $uid = $this->uid();
        $acc = app(TripSharing::class)->access($uid, $id) ?? abort(404);
        $trip = $acc['trip'];
        $role = $acc['role'];
        $payload = $planner->payload($trip);
        $sugg = $planner->suggestions($trip, $payload, $uid);
        $rules = PlannerRules::fromConfig();

        if ($role !== 'owner') {
            // liên kết chia sẻ là của chủ chuyến: người được mời không cần và không nhận token
            $payload['trip']['shareToken'] = null;
            $payload['trip']['shared'] = false;
        }
        $all = array_merge($payload['unassigned'], ...array_map(fn($d) => $d['items'], $payload['days']));
        $client = [
            'trip' => $payload['trip'],
            'role' => $role,
            'rev' => (int) ($trip->rev ?? 0),
            'revUrl' => route('planner.revision', ['id' => $id]),
            'historyUrl' => \App\Services\Planner\TripRevisions::ready() ? route('planner.history', ['id' => $id]) : null,
            'lockUrl' => route('planner.lock', ['id' => $id]),
            'membersUrl' => route('planner.members', ['id' => $id]),
            'me' => $uid,
            'googleMapsKey' => config('services.google_maps.key') ?: null,
            'googleMapId' => config('services.google_maps.map_id') ?: 'DEMO_MAP_ID',
            'mapStyleUrl' => \App\Services\Planner\MapStyle::url(),
            'browseUrl' => route('planner.browse', ['id' => $id]),
            'items' => array_map([TripPlanner::class, 'itemForClient'], $all),
            'suggestions' => $sugg,
            'saveUrl' => route('planner.save', ['id' => $id]),
            'shareUrl' => route('planner.share', ['id' => $id]),
            'joinLinkUrl' => $role === 'owner' ? route('planner.joinLink', ['id' => $id]) : null,
            'privacyUrl' => $role === 'owner' ? route('planner.privacy', ['id' => $id]) : null,
            'sharedBase' => url('/chuyen-di') . '/',
            'csrf' => csrf_token(),
            // đường vẽ trên bản đồ theo đường bộ thật: chỉ khi máy chủ cũng dùng OSRM (cùng một nguồn, cùng khóa cấu hình planner.route.*)
            'osrmUrl' => $rules->route()['provider'] === 'osrm' ? $rules->route()['osrmUrl'] : null,
            'osrmProfile' => $rules->route()['osrmProfile'],
            'searchUrl' => route('planner.search', ['id' => $id]),
            'searchExtUrl' => $geo->enabled() ? route('planner.searchExternal', ['id' => $id]) : null,   // null = tắt tìm ngoài kho
            'dests' => DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->limit(300)->get(['destinationId', 'name', 'latitude', 'longitude'])
                ->map(fn($d) => [
                    'id' => (int) $d->destinationId,
                    'name' => $d->name,
                    'lat' => $d->latitude !== null ? (float) $d->latitude : null,
                    'lng' => $d->longitude !== null ? (float) $d->longitude : null
                ])->all(),
            'contentWarnings' => $payload['contentWarnings'],
            'advice' => $payload['advice'],
            'estimateNote' => $payload['estimateNote'],
            'costRef' => config('planner.costs'),
            'rain' => $payload['rain'],
            // chỗ ở (vị trí, đêm) + vé đi / về: trình duyệt tự tính ngữ cảnh từng ngày theo ngày đi đang sửa (TpSchedule.dayCtx)
            'logistics' => $payload['logistics'] ?? ['stays' => [], 'moves' => []],
            'rainSuggestUrl' => route('planner.rainSuggest', ['id' => $id]),
            'rainMax' => (int) config('planner.rain_plan.max_per_day', 4),
            'limits' => ['maxDays' => TripPlanner::MAX_DAYS, 'maxItems' => TripPlanner::MAX_ITEMS],
            // Quy tắc nghiệp vụ (giờ trong ngày, buổi, độ dày, tuyến): MỘT nguồn PlannerRules, planner-schedule.js / planner-geo.js chỉ đọc từ đây
            'rules' => $rules->client(),
            'aroundUrl' => route('planner.around', ['id' => $id]),
            'gapUrl' => route('planner.gapFill', ['id' => $id]),
            'optimizeUrl' => route('planner.optimize', ['id' => $id]),
            'healthUrl' => route('planner.health', ['id' => $id]),
            'resolveUrl' => TripSharing::canEdit($role) ? route('planner.resolve', ['id' => $id]) : null,
        ];

        $destIds = array_values(array_unique(array_filter(array_merge(
            [(int) ($trip->destinationId ?? 0)],
            array_map(fn($x) => (int) ($x['destinationId'] ?? 0), $all)
        ))));

        // ảnh bìa chuyến = ảnh điểm đến chính; "ai vừa cập nhật" cho phần cộng tác
        $cover = !empty($trip->destinationId) ? DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->value('coverImage') : null;
        $rv = app(TripSharing::class)->revision($id);
        // A-01 / A-05: Top-N tour kèm điểm thành phần; banner khi Match ≥ ngưỡng và còn đủ chỗ (người chỉ xem không đặt hộ được)
        $tourMatch = $role === 'view' ? [] : app(TourMatch::class)->rank(TourMatch::wantFromTrip($trip, $all), $destIds);

        return view('clients.planner.edit', [
            'aiExplainOn' => app(\App\Services\Ai\AiGateway::class)->featureOn('explain'),
            // La bàn Copilot chỉ cho người sửa được chuyến; tắt cờ thì không có nút, không tải script
            'aiCopilotOn' => (app(\App\Services\Ai\AiGateway::class)->featureOn('copilot') || config('ai.features.suggest', true)) && TripSharing::canEdit($role),
            'aiLlmOn' => app(\App\Services\Ai\AiGateway::class)->featureOn('copilot'),
            'cover' => $cover ? \App\Support\DiscoverImage::url((string) $cover) : null,
            'lastEdit' => ['by' => $rv['by'], 'at' => $trip->updatedAt ?? null, 'byMe' => (int) ($trip->lastEditorId ?? 0) === $uid],
            'title' => $trip->title,
            'metaDescription' => 'Chuyến đi của bạn.',
            'noindex' => true,
            'tourFit' => $tourMatch,
            'tourBanner' => TourMatch::banner($tourMatch),
            'role' => $role,
            'members' => app(TripSharing::class)->members($trip, $role === 'owner'),
            'payload' => $payload,
            'client' => $client,
            'travel' => TravelController::defaults($trip),
            // đã đặt (cần đặt / đã hủy hiện ở thẻ "Đặt chỗ cho chuyến")
            'externals' => DB::table('tbl_trip_external')->where('tripId', $id)
                ->when(\App\Services\Planner\BookingPlan::ready(), fn($q) => $q->where('status', 'booked'))->orderBy('startAt')->get(),
        ]);
    }

    /**
     * GET /len-ke-hoach/{id}/quanh-tuyen?pts=lat,lng;lat,lng&km=1&group=eat&have[]=place:12
     * -> {results:[...], total, km} : địa điểm / trải nghiệm đã xuất bản nằm gần tuyến của một ngày, xếp theo quãng phải đi vòng thêm.
     * Tuyến gửi từ trình soạn (kể cả thay đổi chưa lưu); chỉ người sửa được chuyến mới gọi được.
     */
    public function around(Request $request, int $id, RouteExplorer $explorer): JsonResponse
    {
        app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404);
        $v = $request->validate([
            'pts' => ['required', 'string', 'max:1200'],
            'km' => ['nullable', 'numeric', 'between:0.3,10'],
            'group' => ['nullable', 'in:' . implode(',', RouteExplorer::GROUPS)],
            'have' => ['nullable', 'array', 'max:80'],
            'have.*' => ['string', 'max:30'],
        ]);
        $pts = [];
        foreach (explode(';', $v['pts']) as $pair) {
            $xy = explode(',', $pair);
            if (count($xy) === 2 && is_numeric($xy[0]) && is_numeric($xy[1]) && abs((float) $xy[0]) <= 90 && abs((float) $xy[1]) <= 180) {
                $pts[] = [(float) $xy[0], (float) $xy[1]];
            }
        }
        if (!$pts) {
            return response()->json(['message' => 'Ngày này chưa có điểm nào có vị trí.', 'results' => [], 'total' => 0], 422);
        }

        return response()->json($explorer->around($pts, (float) ($v['km'] ?? 1), (string) ($v['group'] ?? 'all'), $v['have'] ?? []));
    }

    /**
     * T-03 GET /len-ke-hoach/{id}/lap-khoang-trong?a=lat,lng&b=lat,lng&gap=70&from=600&day=2&have[]=place:12
     * -> {results:[{..., stayMin, spareMin, fitText}]} : nơi vừa khoảng trống giữa hai mục (thời gian ở + đi vòng ≤ khoảng trống, đang mở cửa).
     */
    public function gapFill(Request $request, int $id, RouteExplorer $explorer): JsonResponse
    {
        $a = app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404);
        $v = $request->validate([
            'a' => ['required', 'string', 'max:60', 'regex:/^-?\d{1,2}(\.\d+)?,-?\d{1,3}(\.\d+)?$/'],
            'b' => ['required', 'string', 'max:60', 'regex:/^-?\d{1,2}(\.\d+)?,-?\d{1,3}(\.\d+)?$/'],
            'gap' => ['required', 'integer', 'between:15,600'],
            'from' => ['required', 'integer', 'between:0,1440'],
            'day' => ['required', 'integer', 'between:1,' . TripPlanner::MAX_DAYS],
            'have' => ['nullable', 'array', 'max:80'],
            'have.*' => ['string', 'max:30'],
        ]);
        $pa = array_map('floatval', explode(',', $v['a']));
        $pb = array_map('floatval', explode(',', $v['b']));
        $dow = OpeningHours::dowOf($a['trip']->startDate ?: null, (int) $v['day']);

        return response()->json(['results' => $explorer->fillGap($pa, $pb, (int) $v['gap'], (int) $v['from'], $dow, $v['have'] ?? [])]);
    }

    /**
     * POST /len-ke-hoach/{id}/toi-uu  {stops:[{key, lat?, lng?, anchor}], keepFirst}
     * -> {order:[key...], beforeKm, afterKm, savedKm, savedMin, changed, moved, provider}
     * Tối ưu thứ tự MỘT ngày từ trạng thái đang soạn (kể cả chưa lưu). Điểm neo (giờ cố định, khóa vị trí, đã chọn buổi, điểm đầu ngày khi keepFirst)
     * giữ nguyên chỗ. Chỉ trả đề xuất; giao diện cho xem trước, người dùng bấm Áp dụng mới đổi (rồi tự lưu như mọi thay đổi khác).
     */
    public function optimize(Request $request, int $id, RouteService $route): JsonResponse
    {
        app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404);
        $v = $request->validate([
            'stops' => ['required', 'array', 'min:2', 'max:' . TripPlanner::MAX_ITEMS],
            'stops.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'stops.*.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'stops.*.anchor' => ['nullable', 'boolean'],
            'keepFirst' => ['nullable', 'boolean'],
        ]);
        $stops = array_map(fn($s) => ['key' => $s['key'], 'lat' => $s['lat'] ?? null, 'lng' => $s['lng'] ?? null, 'anchor' => !empty($s['anchor'])], $v['stops']);
        if (!empty($v['keepFirst'])) {
            foreach ($stops as $i => $s) {
                if ($s['lat'] !== null && $s['lng'] !== null) {
                    $stops[$i]['anchor'] = true;   // điểm có vị trí đầu tiên của ngày (thường là khách sạn)
                    break;
                }
            }
        }

        $o = $route->optimize($stops);

        return response()->json($o + ['provider' => $route->provider(), 'route' => $route->status()]);
    }

    /** GET /len-ke-hoach/{id}/suc-khoe -> {score, level, days:[...], checks:[{group, level, code, text, day, action}], provider} (bản đã lưu). */
    public function health(int $id, TripHealth $health): JsonResponse
    {
        $trip = (app(TripSharing::class)->access($this->uid(), $id) ?? abort(404))['trip'];

        return response()->json($health->forTrip($trip));
    }

    /**
     * GET /len-ke-hoach/{id}/suc-khoe/xu-ly?code=capacity.overload&day=2[&index=3]
     * -> {code, day, index, problem, options:[{id, label, reason, changes, effects:[{day, beforePct, afterPct}], resolves}]}
     * Chỉ đề xuất trên bản đã lưu; không ghi gì (trình duyệt áp dụng rồi tự lưu).
     */
    public function resolve(Request $request, int $id, TripResolver $resolver): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z]+\.[a-z_]+$/'],
            'day' => ['nullable', 'integer', 'min:1', 'max:' . TripPlanner::MAX_DAYS],
            'index' => ['nullable', 'integer', 'min:0', 'max:' . TripPlanner::MAX_ITEMS],
            'now' => ['nullable', 'integer', 'min:0', 'max:1439'],   // xếp lại hôm nay: giờ hiện tại (phút) theo máy người dùng
        ]);
        $resolver->nowMin = isset($v['now']) ? (int) $v['now'] : null;

        return response()->json($resolver->forTrip($trip, $v['code'], isset($v['day']) ? (int) $v['day'] : null, isset($v['index']) ? (int) $v['index'] : null));
    }

    /** POST /len-ke-hoach/{id}/nhan-ban : tạo chuyến mới của mình từ chuyến này (kể cả chuyến được mời xem). */
    public function duplicate(int $id, TripPlanner $planner)
    {
        try {
            $new = $planner->duplicate($this->uid(), $id);
        } catch (\DomainException $e) {
            return back()->with('planner_notice', $e->getMessage());
        }
        if ($new === null) {
            abort(404);
        }

        return redirect()->route('planner.edit', ['id' => $new])->with('planner_notice', 'Đã nhân bản thành chuyến đi mới. Bạn đang sửa bản sao, chuyến gốc giữ nguyên.');
    }

    /** POST /lich-trinh/{slug}/dung-lam-chuyen-di : sao chép lịch trình mẫu (một điểm đến) thành chuyến đi của người dùng. */
    public function fromItinerary(string $slug, TripPlanner $planner)
    {
        try {
            $r = $planner->createFromItinerary($this->uid(), $slug);
        } catch (\DomainException $e) {
            return redirect()->route('itineraries.show', ['slug' => $slug])->with('planner_error', $e->getMessage());
        }

        return redirect()->route('planner.edit', ['id' => $r['tripId']])
            ->with('planner_notice', 'Đã tạo chuyến đi từ lịch trình mẫu (' . $r['imported'] . ' mục' . ($r['skipped'] ? ', bỏ qua ' . $r['skipped'] . ' mục di chuyển/không còn công khai' : '') . ').');
    }

    /** POST /len-ke-hoach/{id}/luu  {title,startDate,days,groupSize,budget,items[]} -> {ok:true} */
    public function save(Request $request, int $id, TripPlanner $planner): JsonResponse
    {
        $data = $request->validate(TripPlanner::tripRules() + TripPlanner::itemRules() + ['rainPlan' => ['nullable', 'array', 'max:' . TripPlanner::MAX_DAYS], 'rev' => [config('planner.require_revision', true) ? 'required' : 'nullable', 'integer', 'min:0']]);
        if (!$request->has('rainPlan')) {
            unset($data['rainPlan']);   // không gửi = giữ nguyên phương án dự phòng đã lưu
        }
        // T-40: chuyến có bạn đồng hành -> chỉ người đang giữ khóa sửa mới lưu được
        if (app(TripSharing::class)->editable($this->uid(), $id) && ($by = app(\App\Services\Planner\TripLock::class)->blockerFor($id, $this->uid()))) {
            return response()->json(['message' => $by . ' đang sửa chuyến đi này. Bạn xem được thay đổi, và sửa được khi họ xong (hoặc sau 5 phút họ không thao tác).', 'locked' => true, 'by' => $by], 423);
        }
        try {
            $ok = $planner->save($this->uid(), $id, $data, $data['items'] ?? []);
        } catch (TripConflict $e) {
            return response()->json(['code' => 'REVISION_CONFLICT', 'message' => $e->getMessage(), 'conflict' => true, 'rev' => $e->currentRev, 'by' => $e->by], 409);
        }

        if (!$ok) {
            // không liên quan tới chuyến: 404 (không lộ chuyến có tồn tại); được mời chỉ xem: 403
            return app(TripSharing::class)->access($this->uid(), $id)
                ? response()->json(['message' => 'Bạn chỉ có quyền xem chuyến đi này.'], 403)
                : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
        }
        // Giao diện lưu không tải lại trang: trả phần máy chủ mới tính được (cảnh báo nội dung, thời điểm đi)
        $p = $planner->payload(app(TripSharing::class)->access($this->uid(), $id)['trip']);

        $all = array_merge($p['unassigned'], ...array_map(fn($d) => $d['items'], $p['days']));
        $fit = [];
        foreach ($all as $it) {
            if (!empty($it['refId']) && ($it['fit'] || $it['outdoor'] !== null || $it['src'])) {
                $fit[$it['type'] . ':' . $it['refId']] = ['fit' => $it['fit'], 'outdoor' => $it['outdoor'], 'src' => $it['src']];
            }
        }

        return response()->json(['ok' => true, 'contentWarnings' => $p['contentWarnings'], 'advice' => $p['advice'], 'savedAt' => now()->format('H:i'), 'fit' => $fit, 'rain' => $p['rain'], 'rev' => app(TripSharing::class)->revision($id)['rev']]);
    }

    /** GET /len-ke-hoach/{id}/tim?q=&type=&dest= -> {results:[...]} : nội dung đã xuất bản để thêm vào chuyến. */
    public function search(Request $request, int $id, TripPlanner $planner): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'in:' . implode(',', TripPlanner::SEARCH_TYPES)],
            'dest' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['results' => $planner->search($trip, (string) ($v['q'] ?? ''), $v['type'] ?? null, isset($v['dest']) ? (int) $v['dest'] : null)]);
    }

    /**
     * GET /len-ke-hoach/{id}/tim-ngoai?q=&lat=&lng= -> {ok, provider, results:[{name,address,kind,lat,lng,key}]}
     * Tìm địa điểm theo tên ở ngoài kho (OpenStreetMap/Geoapify). Chỉ trả gợi ý; chỉ thành mục của chuyến khi người dùng bấm Thêm rồi lưu.
     * Dịch vụ ngoài lỗi vẫn trả HTTP 200 với ok=false để giao diện báo nhẹ nhàng thay vì hiện lỗi.
     */
    public function searchExternal(Request $request, int $id, TripPlanner $planner, PlaceGeocoder $geo): JsonResponse
    {
        app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404);
        $v = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $hasPos = isset($v['lat'], $v['lng']);

        return response()->json($geo->search($v['q'], $hasPos ? (float) $v['lat'] : null, $hasPos ? (float) $v['lng'] : null));
    }

    /**
     * GET /len-ke-hoach/{id}/kham-pha?q=&f=&cat=&kinds=&levels=1-2&min=&max=&free=&veg=&cuisine=&indoor=&stars=&km=&sort=&unknown=&lat=&lng=&dest=
     * Tìm theo tiêu chí. Không có f: hiểu câu tìm q (PlaceQuery); câu có tên riêng hoặc không có tiêu chí thì chỉ trả intent (giao diện tìm theo tên như cũ).
     * Có f=1: dùng đúng các tham số lọc người dùng chọn trên giao diện.
     * -> {ok, intent:{cat,tags,specific,rest}, filters:{...}, browse:null|{results,total,hidden,facets,sort,sources,osmOk,note,center}}
     */
    public function browse(Request $request, int $id, PlaceFinder $finder): JsonResponse
    {
        $trip = (app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404))['trip'];
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'f' => ['nullable', 'boolean'],
            'cat' => ['nullable', 'in:hotel,restaurant,cafe,bar,market,sight,experience,tour'],
            'kinds' => ['nullable', 'string', 'max:120', 'regex:/^[a-z_,]*$/'],
            'levels' => ['nullable', 'regex:/^[1-4]-[1-4]$/'],
            'min' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'max' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'free' => ['nullable', 'boolean'],
            'veg' => ['nullable', 'boolean'],
            'cuisine' => ['nullable', 'string', 'max:30', 'regex:/^[a-z_]*$/'],
            'indoor' => ['nullable', 'in:0,1'],
            'stars' => ['nullable', 'integer', 'between:1,5'],
            'km' => ['nullable', 'numeric', 'between:0.3,50'],
            'sort' => ['nullable', 'in:near,rank,price'],
            'unknown' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dest' => ['nullable', 'integer', 'min:1'],
            'osm' => ['nullable', 'in:0,1'],
        ]);
        // osm=0: trả ngay phần có sẵn (CSDL, bản chụp), không gọi mạng; giao diện gọi tiếp osm=1 nếu osmPending
        $live = ($v['osm'] ?? '1') !== '0';
        $intent = PlaceQuery::parse((string) ($v['q'] ?? ''));
        $head = ['cat' => $intent['cat'], 'tags' => $intent['tags'], 'specific' => $intent['specific'], 'rest' => $intent['rest']];
        $pos = ['lat' => $v['lat'] ?? null, 'lng' => $v['lng'] ?? null, 'destId' => $v['dest'] ?? null];

        if (!$request->boolean('f')) {
            if ($intent['specific'] || !PlaceQuery::hasCriteria($intent)) {
                return response()->json(['ok' => true, 'intent' => $head, 'filters' => null, 'browse' => null]);
            }
            $c = PlaceFinder::criteria($pos + [
                'cat' => $intent['cat'],
                'kinds' => $intent['kinds'],
                'levels' => $intent['levels'],
                'minPrice' => $intent['minPrice'],
                'maxPrice' => $intent['maxPrice'],
                'free' => $intent['free'],
                'veg' => $intent['veg'],
                'cuisine' => $intent['cuisine'],
                'indoor' => $intent['indoor'],
                'stars' => $intent['stars'],
                'radiusKm' => $intent['radiusKm'],
                'sort' => $intent['sort'],
            ]);
        } else {
            $lv = isset($v['levels']) ? array_map('intval', explode('-', $v['levels'])) : null;
            $c = PlaceFinder::criteria($pos + [
                'cat' => $v['cat'] ?? null,
                'kinds' => isset($v['kinds']) && $v['kinds'] !== '' ? explode(',', $v['kinds']) : null,
                'levels' => $lv,
                'minPrice' => $v['min'] ?? null,
                'maxPrice' => $v['max'] ?? null,
                'free' => $request->boolean('free'),
                'veg' => $request->boolean('veg'),
                'cuisine' => ($v['cuisine'] ?? '') !== '' ? $v['cuisine'] : null,
                'indoor' => $v['indoor'] ?? null,
                'stars' => $v['stars'] ?? null,
                'radiusKm' => $v['km'] ?? null,
                'sort' => $v['sort'] ?? null,
                'includeUnknown' => $request->boolean('unknown'),
            ]);
        }

        return response()->json(['ok' => true, 'intent' => $head, 'filters' => $c + ['radiusKm' => round($c['radiusM'] / 1000, 1)], 'browse' => $finder->browse($trip, $c, $live)]);
    }

    /** POST /len-ke-hoach/{id}/chia-se  {on:0|1} -> {token, url} */
    public function share(Request $request, int $id, TripPlanner $planner): JsonResponse
    {
        $token = $planner->share($this->uid(), $id, $request->boolean('on'));
        if ($token === false) {
            return response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
        }

        return response()->json(['token' => $token, 'url' => $token ? route('planner.shared', ['token' => $token]) : null]);
    }

    /** POST /len-ke-hoach/{id}/lien-ket-moi {on, role, regen}: liên kết mời cùng lên kế hoạch (chỉ chủ chuyến). */
    public function joinLink(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['on' => ['required', 'boolean'], 'role' => ['nullable', 'in:' . implode(',', TripSharing::ROLES)], 'regen' => ['nullable', 'boolean']]);
        $r = app(TripSharing::class)->joinLink($this->uid(), $id, (bool) $v['on'], $v['role'] ?? 'edit', (bool) ($v['regen'] ?? false));
        if ($r === false) {
            return response()->json(['message' => 'Chỉ chủ chuyến đi mới đổi được liên kết mời.'], 403);
        }

        return response()->json(['ok' => true, 'role' => $r['role'], 'url' => $r['token'] ? route('planner.join', ['token' => $r['token']]) : null]);
    }

    /** POST /len-ke-hoach/{id}/rieng-tu {shareNotes?, shareBookings?, shareCosts?}: bản chia sẻ chỉ xem hiện gì. */
    public function privacy(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['shareNotes' => ['nullable', 'boolean'], 'shareBookings' => ['nullable', 'boolean'], 'shareCosts' => ['nullable', 'boolean']]);
        $ok = app(TripSharing::class)->privacy($this->uid(), $id, array_filter($v, fn($x) => $x !== null));

        return $ok ? response()->json(['ok' => true]) : response()->json(['message' => 'Chỉ chủ chuyến đi mới đổi được.'], 403);
    }

    /** GET /chuyen-di/tham-gia/{token}: trang xác nhận tham gia chuyến đi từ liên kết mời. */
    public function joinShow(string $token)
    {
        $trip = app(TripSharing::class)->byJoinToken($token);
        $owner = $trip ? DB::table('tbl_users')->where('userId', $trip->userId)->value('fullName') : null;

        return view('clients.planner.join', ['title' => 'Tham gia chuyến đi', 'noindex' => true, 'trip' => $trip, 'owner' => $owner, 'token' => $token,
            'already' => $trip ? (app(TripSharing::class)->access($this->uid(), (int) $trip->tripId) !== null) : false]);
    }

    /** POST /chuyen-di/tham-gia/{token} */
    public function joinStore(string $token)
    {
        $r = app(TripSharing::class)->join($this->uid(), $token);
        if (!$r['ok']) {
            return redirect()->route('planner')->with('planner_notice', $r['message']);
        }

        return redirect()->route('planner.edit', ['id' => $r['tripId']])->with('planner_notice', $r['message']);
    }

    /** POST /chuyen-di/{token}/luu-ban-sao: người xem liên kết chỉ xem lưu một bản sao về tài khoản mình (chuyến gốc không đổi). */
    public function sharedCopy(string $token, TripPlanner $planner)
    {
        $trip = $planner->bySharedToken($token) ?? abort(404);
        try {
            $new = $planner->copyShared($this->uid(), (int) $trip->tripId);
        } catch (\DomainException $e) {
            return back()->with('planner_notice', $e->getMessage());
        }

        return redirect()->route('planner.edit', ['id' => $new])->with('planner_notice', 'Đã lưu bản sao vào Chuyến đi của tôi. Chuyến gốc của người chia sẻ giữ nguyên.');
    }

    /** GET /len-ke-hoach/{id}/phien-ban -> {rev, by, role}: giao diện hỏi định kỳ để biết có người khác vừa sửa. */
    public function revision(int $id): JsonResponse
    {
        $sh = app(TripSharing::class);
        $acc = $sh->access($this->uid(), $id) ?? abort(404);

        return response()->json($sh->revision($id) + ['role' => $acc['role']]);
    }

    /** GET /len-ke-hoach/{id}/lich-su -> {rev, revisions:[{rev,type,by,items,at}]}: ai xem được chuyến thì xem được lịch sử. */
    public function history(int $id): JsonResponse
    {
        app(TripSharing::class)->access($this->uid(), $id) ?? abort(404);

        return response()->json(['rev' => app(TripSharing::class)->revision($id)['rev'], 'revisions' => app(\App\Services\Planner\TripRevisions::class)->list($id)]);
    }

    /** GET /len-ke-hoach/{id}/lich-su/{revisionNo} -> nội dung bản đó theo ngày + khác biệt so với hiện tại (xem trước khôi phục). */
    public function historyShow(int $id, int $revisionNo): JsonResponse
    {
        app(TripSharing::class)->access($this->uid(), $id) ?? abort(404);
        $r = app(\App\Services\Planner\TripRevisions::class)->show($id, $revisionNo);

        return $r ? response()->json($r) : response()->json(['message' => 'Không tìm thấy phiên bản này.'], 404);
    }

    /** POST /len-ke-hoach/{id}/lich-su/{revisionNo}/khoi-phuc {rev} -> {ok, rev}: khôi phục bản cũ thành phiên bản mới. */
    public function restoreRevision(Request $request, int $id, int $revisionNo): JsonResponse
    {
        $data = $request->validate(['rev' => ['required', 'integer', 'min:0']]);
        $sh = app(TripSharing::class);
        if (!$sh->editable($this->uid(), $id)) {
            return $sh->access($this->uid(), $id)
                ? response()->json(['message' => 'Bạn chỉ có quyền xem chuyến đi này.'], 403)
                : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
        }
        if ($by = app(\App\Services\Planner\TripLock::class)->blockerFor($id, $this->uid())) {
            return response()->json(['message' => $by . ' đang sửa chuyến đi này.', 'locked' => true, 'by' => $by], 423);
        }
        try {
            $rev = app(\App\Services\Planner\TripRevisions::class)->restore($this->uid(), $id, $revisionNo, (int) $data['rev']);
        } catch (TripConflict $e) {
            return response()->json(['code' => 'REVISION_CONFLICT', 'message' => $e->getMessage(), 'conflict' => true, 'rev' => $e->currentRev, 'by' => $e->by], 409);
        }

        return $rev === null
            ? response()->json(['message' => 'Không tìm thấy phiên bản này.'], 404)
            : response()->json(['ok' => true, 'rev' => $rev]);
    }

    /** GET /len-ke-hoach/{id}/thanh-vien -> {role, members[]} */
    public function members(int $id): JsonResponse
    {
        $sh = app(TripSharing::class);
        $acc = $sh->access($this->uid(), $id) ?? abort(404);

        return response()->json(['role' => $acc['role'], 'members' => $sh->members($acc['trip'], $acc['role'] === 'owner')]);
    }

    /** POST /len-ke-hoach/{id}/thanh-vien {email, role} : mời bạn đồng hành (chỉ chủ chuyến). */
    public function invite(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['email' => ['required', 'string', 'max:190'], 'role' => ['required', 'in:' . implode(',', TripSharing::ROLES)]]);
        $r = app(TripSharing::class)->invite($this->uid(), $id, $v['email'], $v['role']);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** POST /len-ke-hoach/{id}/thanh-vien/{userId} {role} : đổi quyền (chủ chuyến). */
    public function memberRole(Request $request, int $id, int $userId): JsonResponse
    {
        $v = $request->validate(['role' => ['required', 'in:' . implode(',', TripSharing::ROLES)]]);

        return app(TripSharing::class)->setRole($this->uid(), $id, $userId, $v['role'])
            ? response()->json(['ok' => true]) : response()->json(['message' => 'Không đổi được quyền.'], 404);
    }

    /** POST /len-ke-hoach/{id}/thanh-vien/{userId}/go : chủ chuyến gỡ bạn, hoặc tự rời chuyến. */
    public function memberRemove(Request $request, int $id, int $userId)
    {
        $ok = app(TripSharing::class)->remove($this->uid(), $id, $userId);
        if (!$request->expectsJson()) {
            // gửi từ form "Rời chuyến" ở danh sách
            return redirect()->route('planner')->with('planner_notice', $ok ? 'Đã rời khỏi chuyến đi.' : 'Không rời được chuyến đi.');
        }

        return $ok ? response()->json(['ok' => true]) : response()->json(['message' => 'Không gỡ được.'], 404);
    }

    /** POST /len-ke-hoach/{id}/xoa */
    public function destroy(int $id, TripPlanner $planner)
    {
        $planner->delete($this->uid(), $id) || abort(404);

        return redirect()->route('planner');
    }

    /** GET /chuyen-di/{token} : bản chỉ đọc, ai có liên kết đều xem được, không đưa vào chỉ mục. */
    public function shared(string $token, TripPlanner $planner)
    {
        $trip = $planner->bySharedToken($token) ?? abort(404);
        $payload = $planner->payload($trip);
        $show = ['notes' => (bool) ($trip->shareNotes ?? true), 'bookings' => (bool) ($trip->shareBookings ?? false), 'costs' => (bool) ($trip->shareCosts ?? false)];
        if (!$show['notes']) {
            // chủ chuyến ẩn ghi chú: bỏ phần ghi chú của từng mục (mục tự thêm — type 'note' — vẫn hiện tên và vị trí)
            foreach ($payload['days'] as &$d) {
                $d['items'] = array_map(fn($it) => ['note' => null] + $it, $d['items']);
            }
            unset($d);
            $payload['unassigned'] = array_map(fn($it) => ['note' => null] + $it, $payload['unassigned']);
        }
        if (!$show['bookings']) {
            // ẩn đặt chỗ thì ẩn luôn mục "Nhận phòng … / Bay …" trên lịch (khách sạn, số hiệu chuyến bay là thông tin riêng)
            $payload = \App\Services\Planner\TripLogistics::stripBookingItems($payload, (int) $trip->tripId);
        }
        // đặt chỗ: chỉ tên, loại, thời gian; KHÔNG mã xác nhận, tệp, giá
        $bookings = $show['bookings'] ? DB::table('tbl_trip_external')->where('tripId', $trip->tripId)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('tbl_trip_external', 'status'), fn($q) => $q->where('status', '!=', 'cancelled'))
            ->orderBy('startAt')->get(['kind', 'title', 'startAt'])->all() : [];
        $cover = $trip->destinationId ? DB::table('tbl_destinations')->where('destinationId', $trip->destinationId)->value('coverImage') : null;
        $nItems = array_sum(array_map(fn($d) => count($d['items']), $payload['days']));

        return view('clients.planner.shared', [
            'title' => $trip->title,
            'metaDescription' => mb_substr($trip->title . ': lịch trình ' . (int) $trip->days . ' ngày, ' . $nItems . ' điểm, lên kế hoạch trên Travela.', 0, 160),
            'metaImage' => $cover ?: null,
            'noindex' => true,
            'payload' => $payload,
            'show' => $show,
            'bookings' => $bookings,
            'token' => $token,
            'cover' => $cover,
        ]);
    }

    /**
     * POST /len-ke-hoach/{id}/khoa  action=acquire|release
     * Trình duyệt gọi khi mở trang, rồi mỗi 60 giây (gia hạn / hỏi xem đã tới lượt mình chưa), và khi rời trang (release).
     */
    public function lock(Request $request, int $id, \App\Services\Planner\TripLock $lock): JsonResponse
    {
        app(TripSharing::class)->editable($this->uid(), $id) ?? abort(404);
        if ($request->input('action') === 'release') {
            $lock->release($id, $this->uid());

            return response()->json(['released' => true]);
        }

        return response()->json($lock->acquire($id, $this->uid()) + ['ttl' => \App\Services\Planner\TripLock::TTL_SECONDS, 'renew' => \App\Services\Planner\TripLock::RENEW_SECONDS]);
    }

    /** GET /len-ke-hoach/{id}/do-mang-theo  (A-13) -> {source: ai|rules, groups:[{name, items:[{name, why}]}], note} */
    public function packing(Request $request, int $id, \App\Services\Planner\PackingList $packing): JsonResponse
    {
        $acc = app(TripSharing::class)->access($this->uid(), $id) ?? abort(404);
        $r = $packing->forTrip($acc['trip'], $this->uid(), $request->query('ai', '1') !== '0');

        return response()->json(['source' => $r['source'], 'groups' => $r['groups'], 'note' => $r['note']]);
    }

    /**
     * GET /len-ke-hoach/{id}/xuat-pdf (A-10d): dữ liệu để trình duyệt dựng PDF (pdfmake, font có dấu tiếng Việt).
     * Gồm lịch trình từng ngày, các đơn đặt tour của người xem có trong chuyến (chưa hủy) và danh sách đồ mang theo (theo luật, không gọi AI).
     */
    public function exportPdf(int $id, TripPlanner $planner, \App\Services\Planner\PackingList $packing): JsonResponse
    {
        $acc = app(TripSharing::class)->access($this->uid(), $id) ?? abort(404);
        $trip = $acc['trip'];
        $p = $planner->payload($trip);
        $hm = fn($m) => $m === null ? null : sprintf('%02d:%02d', intdiv((int) $m, 60), (int) $m % 60);
        $slot = ['morning' => 'Sáng', 'noon' => 'Trưa', 'afternoon' => 'Chiều', 'evening' => 'Tối'];
        $type = ['destination' => 'Điểm đến', 'place' => 'Địa điểm', 'food' => 'Món ăn', 'event' => 'Lễ hội', 'experience' => 'Trải nghiệm', 'tour' => 'Tour', 'note' => 'Ghi chú', 'transport' => 'Di chuyển'];
        $days = array_map(fn($d) => [
            'day' => $d['day'],
            'date' => $d['date'] ? \Carbon\Carbon::parse($d['date'])->format('d/m/Y') : null,
            'km' => $d['km'],
            'items' => array_map(fn($it) => [
                'time' => $hm($it['startMin'] ?? null) ?? ($slot[$it['slot'] ?? ''] ?? ''),
                'name' => $it['name'],
                'type' => $type[$it['type']] ?? '',
                'sub' => $it['sub'] ?? '',
                'note' => $it['note'] ?? '',
                'cost' => $it['cost'] ?? null,
            ], $d['items']),
        ], array_values($p['days']));

        // đơn đặt tour của chính người xem cho các tour có trong chuyến
        $tourIds = DB::table('tbl_trip_items')->where('tripId', $id)->where('refType', 'tour')->pluck('refId')->filter()->all();
        $bookings = $tourIds ? DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
            ->leftJoin('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
            ->where('b.userId', $this->uid())->whereIn('b.tourId', $tourIds)->where('b.bookingStatus', '!=', 'c')
            ->orderBy('b.bookingId')->get(['b.bookingCode', 't.title', 'b.departureDate', 't.startDate', 'b.numAdults', 'b.numChildren', 'b.totalPrice', 'b.bookingStatus', 'c.paymentStatus'])
            ->map(fn($b) => [
                'code' => $b->bookingCode,
                'title' => $b->title,
                'date' => \Carbon\Carbon::parse($b->departureDate ?: $b->startDate)->format('d/m/Y'),
                'people' => (int) $b->numAdults + (int) $b->numChildren,
                'total' => (float) $b->totalPrice,
                'status' => ['n' => 'Chờ xác nhận', 'y' => 'Đã xác nhận', 'f' => 'Hoàn tất'][$b->bookingStatus] ?? $b->bookingStatus,
                'paid' => $b->paymentStatus === 'y',
            ])->all() : [];
        $pack = $packing->forTrip($trip, $this->uid(), false);

        return response()->json([
            'title' => $trip->title,
            'meta' => trim(($trip->days ? $trip->days . ' ngày' : '') . ' · ' . $trip->groupSize . ' người'
                . ($trip->startDate ? ' · từ ' . \Carbon\Carbon::parse($trip->startDate)->format('d/m/Y') : ''), ' ·'),
            'budget' => $p['totals']['budget'],
            'cost' => $p['totals']['cost'],
            'days' => $days,
            'unassigned' => array_map(fn($it) => $it['name'], $p['unassigned']),
            'rain' => $p['rain']['plan'] ?? [],
            'bookings' => $bookings,
            'packing' => $pack['groups'],
            'generatedAt' => now()->format('H:i d/m/Y'),
            'site' => config('site.name', 'Travela'),
        ]);
    }

    /** URL công khai của một tệp trên đĩa "public" (gốc đĩa có thể đổi qua .env, nên không ghép tay "storage/..."). */
    private static function publicUrl(string $path): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk  Storage::disk() khai báo trả về interface không có url(), đối tượng thật là FilesystemAdapter */
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        return $disk->url($path);
    }
}
