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
use App\Services\Planner\PlannerService;
use App\Services\Planner\RainPlan;
use App\Services\Planner\TourFit;
use App\Services\Planner\TripConflict;
use App\Services\Planner\TripSharing;
use App\Services\Planner\UnknownPlace;
use App\Services\Planner\TripPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        ]);
    }

    /** GET /len-ke-hoach/de-xuat : Travela đề xuất lịch trình từ dữ liệu đã duyệt (điền sẵn từ ?q= hoặc các tham số do trang tìm kiếm gắn vào). */
    public function suggest(Request $request, PlannerGate $gate, SearchIntent $intent)
    {
        $month = (int) $request->query('thang');
        $pre = ['destination' => null, 'month' => $month >= 1 && $month <= 12 ? $month : null, 'days' => null, 'groupSize' => null, 'budget' => null, 'interests' => [], 'dislikes' => []];
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $i = $intent->parse(mb_substr($q, 0, 200));
            $pre = ['destination' => $i['destination']['slug'] ?? null, 'month' => $i['month'], 'days' => $i['days'], 'groupSize' => $i['groupSize'],
                'budget' => $i['budget'], 'interests' => $i['interests'], 'dislikes' => $i['dislikes']];
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

        return view('clients.planner.suggest', [
            'unknown' => $unknown,
            'dests' => $gate->coreStatus(),
            'pre' => $pre,
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

        return view('clients.planner.discover', [
            'title' => 'Chưa biết đi đâu? Chọn tháng và cảm hứng',
            'metaDescription' => 'Chọn tháng đi và cảm hứng, Travela gợi ý điểm đến hợp nhất kèm lý do từ mùa, khí hậu, lễ hội và tour.',
            'noindex' => true,
            'inspirations' => $ins, 'picked' => $picked, 'month' => $month, 'days' => $days, 'group' => $group, 'budget' => $budget,
            'maxDays' => $max, 'result' => $result, 'now' => (int) date('n'),
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

    /** POST /len-ke-hoach/tao-tu-dong : Planner tự đề xuất lịch trình từ dữ liệu đã duyệt rồi lưu thành chuyến. Web không có cách bỏ qua cổng Planner-ready. */
    public function generate(Request $request, PlannerService $service)
    {
        $maxDays = (int) config('planner.max_days', 7);
        $data = $request->validate([
            'destinationId' => ['required', 'integer', 'min:1'],
            'month' => ['required', 'integer', 'between:1,12'],
            'days' => ['required', 'integer', 'between:1,' . $maxDays],
            'groupSize' => ['required', 'integer', 'between:1,50'],
            'budget' => ['nullable', 'numeric', 'between:0,100000000000'],
            'startDate' => ['nullable', 'date'],
            'pace' => ['nullable', 'in:' . implode(',', array_keys((array) config('planner.pace')))],
            'interests' => ['nullable', 'array', 'max:6'],
            'interests.*' => ['string', 'in:' . implode(',', array_keys((array) config('interests')))],
            'dislikes' => ['nullable', 'array', 'max:6'],
            'dislikes.*' => ['string', 'in:' . implode(',', array_keys((array) config('planner.dislikes')))],
        ]);

        $result = $service->generate($data);   // không truyền $ignoreGate: điểm đến thiếu dữ liệu bị chặn
        if (empty($result['ok'])) {
            $msg = $result['message'] ?? 'Không thể tạo lịch trình.';

            return redirect()->route('planner.suggest')->withInput()->withErrors(['generate' => $msg]);
        }
        if (!array_filter($result['plan']['days'], fn($d) => !empty($d['items']))) {
            // Không đủ ứng viên thỏa ràng buộc cứng: không lưu chuyến rỗng, nói rõ lý do (mục 12)
            $why = $result['plan']['warnings'] ?? [];

            return redirect()->route('planner.suggest')->withInput()->withErrors(['generate' => 'Chưa đủ nội dung phù hợp để xếp lịch với điều kiện này. ' . implode(' ', $why) . ' Hãy thử nới ngân sách, bỏ bớt điều không thích hoặc đổi tháng.']);
        }
        $id = $service->save($this->uid(), $result);

        return redirect()->route('planner.edit', ['id' => $id])
            ->with('planner_notice', 'Đã tạo lịch trình ' . $result['destination']['name'] . ' từ nội dung đã được duyệt. ' . ($result['plan']['budget']['message'] ?? ''));
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
        $id = $planner->create($this->uid(), $data, $request->boolean('fromTray'));

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
            'sharedBase' => url('/chuyen-di') . '/',
            'csrf' => csrf_token(),
            'osrmUrl' => config('planner.osrm_url'),
            'searchUrl' => route('planner.search', ['id' => $id]),
            'searchExtUrl' => $geo->enabled() ? route('planner.searchExternal', ['id' => $id]) : null,   // null = tắt tìm ngoài kho
            'dests' => DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->limit(300)->get(['destinationId', 'name', 'latitude', 'longitude'])
                ->map(fn($d) => ['id' => (int) $d->destinationId, 'name' => $d->name,
                    'lat' => $d->latitude !== null ? (float) $d->latitude : null, 'lng' => $d->longitude !== null ? (float) $d->longitude : null])->all(),
            'contentWarnings' => $payload['contentWarnings'],
            'advice' => $payload['advice'],
            'estimateNote' => $payload['estimateNote'],
            'costRef' => config('planner.costs'),
            'rain' => $payload['rain'],
            'rainSuggestUrl' => route('planner.rainSuggest', ['id' => $id]),
            'rainMax' => (int) config('planner.rain_plan.max_per_day', 4),
            'limits' => ['maxDays' => TripPlanner::MAX_DAYS, 'maxItems' => TripPlanner::MAX_ITEMS],
        ];

        $destIds = array_values(array_unique(array_filter(array_merge(
            [(int) ($trip->destinationId ?? 0)],
            array_map(fn($x) => (int) ($x['destinationId'] ?? 0), $all)
        ))));

        return view('clients.planner.edit', [
            'title' => $trip->title,
            'metaDescription' => 'Chuyến đi của bạn.',
            'noindex' => true,
            'tourFit' => $role === 'view' ? [] : app(TourFit::class)->forTrip($trip, $destIds),
            'role' => $role,
            'members' => app(TripSharing::class)->members($trip, $role === 'owner'),
            'payload' => $payload,
            'client' => $client,
        ]);
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
        $data = $request->validate(TripPlanner::tripRules() + TripPlanner::itemRules() + ['rainPlan' => ['nullable', 'array', 'max:' . TripPlanner::MAX_DAYS], 'rev' => ['nullable', 'integer', 'min:0']]);
        if (!$request->has('rainPlan')) {
            unset($data['rainPlan']);   // không gửi = giữ nguyên phương án dự phòng đã lưu
        }
        try {
            $ok = $planner->save($this->uid(), $id, $data, $data['items'] ?? []);
        } catch (TripConflict $e) {
            return response()->json(['message' => $e->getMessage(), 'conflict' => true, 'rev' => $e->currentRev, 'by' => $e->by], 409);
        }

        if (!$ok) {
            return response()->json(['message' => 'Bạn không có quyền sửa chuyến đi này hoặc chuyến đi không còn.'], 403);
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
        ]);
        $intent = PlaceQuery::parse((string) ($v['q'] ?? ''));
        $head = ['cat' => $intent['cat'], 'tags' => $intent['tags'], 'specific' => $intent['specific'], 'rest' => $intent['rest']];
        $pos = ['lat' => $v['lat'] ?? null, 'lng' => $v['lng'] ?? null, 'destId' => $v['dest'] ?? null];

        if (!$request->boolean('f')) {
            if ($intent['specific'] || !PlaceQuery::hasCriteria($intent)) {
                return response()->json(['ok' => true, 'intent' => $head, 'filters' => null, 'browse' => null]);
            }
            $c = PlaceFinder::criteria($pos + [
                'cat' => $intent['cat'], 'kinds' => $intent['kinds'], 'levels' => $intent['levels'], 'minPrice' => $intent['minPrice'], 'maxPrice' => $intent['maxPrice'],
                'free' => $intent['free'], 'veg' => $intent['veg'], 'cuisine' => $intent['cuisine'], 'indoor' => $intent['indoor'], 'stars' => $intent['stars'],
                'radiusKm' => $intent['radiusKm'], 'sort' => $intent['sort'],
            ]);
        } else {
            $lv = isset($v['levels']) ? array_map('intval', explode('-', $v['levels'])) : null;
            $c = PlaceFinder::criteria($pos + [
                'cat' => $v['cat'] ?? null, 'kinds' => isset($v['kinds']) && $v['kinds'] !== '' ? explode(',', $v['kinds']) : null, 'levels' => $lv,
                'minPrice' => $v['min'] ?? null, 'maxPrice' => $v['max'] ?? null, 'free' => $request->boolean('free'), 'veg' => $request->boolean('veg'),
                'cuisine' => ($v['cuisine'] ?? '') !== '' ? $v['cuisine'] : null, 'indoor' => $v['indoor'] ?? null, 'stars' => $v['stars'] ?? null,
                'radiusKm' => $v['km'] ?? null, 'sort' => $v['sort'] ?? null, 'includeUnknown' => $request->boolean('unknown'),
            ]);
        }

        return response()->json(['ok' => true, 'intent' => $head, 'filters' => $c + ['radiusKm' => round($c['radiusM'] / 1000, 1)], 'browse' => $finder->browse($trip, $c)]);
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

    /** GET /len-ke-hoach/{id}/phien-ban -> {rev, by, role}: giao diện hỏi định kỳ để biết có người khác vừa sửa. */
    public function revision(int $id): JsonResponse
    {
        $sh = app(TripSharing::class);
        $acc = $sh->access($this->uid(), $id) ?? abort(404);

        return response()->json($sh->revision($id) + ['role' => $acc['role']]);
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

        return view('clients.planner.shared', [
            'title' => $trip->title,
            'metaDescription' => 'Chuyến đi được chia sẻ.',
            'noindex' => true,
            'payload' => $payload,
        ]);
    }
}
