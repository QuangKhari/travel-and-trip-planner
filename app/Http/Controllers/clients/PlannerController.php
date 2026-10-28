<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\TripTray;
use App\Services\Content\SearchIntent;
use App\Services\Planner\PlaceGeocoder;
use App\Services\Planner\PlannerGate;
use App\Services\Planner\PlannerService;
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

        return view('clients.planner.suggest', [
            'dests' => $gate->coreStatus(),
            'pre' => $pre,
            'title' => 'Để Travela đề xuất lịch trình',
            'metaDescription' => 'Travela xếp sẵn lịch trình từ nội dung đã được biên tập duyệt, bạn chỉnh lại theo ý mình.',
            'noindex' => true,
        ]);
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
        $trip = $planner->owned($uid, $id) ?? abort(404);
        $payload = $planner->payload($trip);
        $sugg = $planner->suggestions($trip, $payload, $uid);

        $all = array_merge($payload['unassigned'], ...array_map(fn($d) => $d['items'], $payload['days']));
        $client = [
            'trip' => $payload['trip'],
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
            'limits' => ['maxDays' => TripPlanner::MAX_DAYS, 'maxItems' => TripPlanner::MAX_ITEMS],
        ];

        return view('clients.planner.edit', [
            'title' => $trip->title,
            'metaDescription' => 'Chuyến đi của bạn.',
            'noindex' => true,
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
        $data = $request->validate(TripPlanner::tripRules() + TripPlanner::itemRules());
        $ok = $planner->save($this->uid(), $id, $data, $data['items'] ?? []);

        if (!$ok) {
            return response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
        }
        // Giao diện lưu không tải lại trang: trả phần máy chủ mới tính được (cảnh báo nội dung, thời điểm đi)
        $p = $planner->payload($planner->owned($this->uid(), $id));

        return response()->json(['ok' => true, 'contentWarnings' => $p['contentWarnings'], 'advice' => $p['advice'], 'savedAt' => now()->format('H:i')]);
    }

    /** GET /len-ke-hoach/{id}/tim?q=&type=&dest= -> {results:[...]} : nội dung đã xuất bản để thêm vào chuyến. */
    public function search(Request $request, int $id, TripPlanner $planner): JsonResponse
    {
        $trip = $planner->owned($this->uid(), $id) ?? abort(404);
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
        $planner->owned($this->uid(), $id) ?? abort(404);
        $v = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $hasPos = isset($v['lat'], $v['lng']);

        return response()->json($geo->search($v['q'], $hasPos ? (float) $v['lat'] : null, $hasPos ? (float) $v['lng'] : null));
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
