<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\TripTray;
use App\Services\Content\SearchIntent;
use App\Services\Planner\PlannerGate;
use App\Services\Planner\PlannerService;
use App\Services\Planner\TripPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip Planner (web). Các route có đăng nhập nằm trong nhóm checkLoginClient; chỉ trang chia sẻ chỉ đọc là công khai.
 */
class PlannerController extends Controller
{
    private function uid(): int
    {
        return (int) session()->get('userId');
    }

    /** GET /len-ke-hoach?thang=N : danh sách chuyến + form tạo chuyến mới (thang = tháng muốn đi, từ bưu ảnh trang chủ). */
    public function index(Request $request, TripPlanner $planner, TripTray $tray, PlannerGate $gate, SearchIntent $intent)
    {
        $uid = $this->uid();
        $month = (int) $request->query('thang');
        $startDefault = null;
        if ($month >= 1 && $month <= 12) {
            $y = (int) date('Y') + ($month < (int) date('n') ? 1 : 0);
            $startDefault = sprintf('%04d-%02d-01', $y, $month);
        }

        // Điền sẵn form tạo tự động: từ ?q= (câu tự nhiên) hoặc từ tham số do trang tìm kiếm gắn vào
        $pre = ['destination' => null, 'month' => $month ?: null, 'days' => null, 'groupSize' => null, 'budget' => null, 'interests' => [], 'dislikes' => []];
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

        return view('clients.planner.index', [
            'dests' => $gate->coreStatus(),
            'pre' => $pre,
            'title' => 'Lên kế hoạch chuyến đi',
            'metaDescription' => 'Xếp các điểm đến, món ăn, lễ hội và tour của bạn vào từng ngày, xem quãng đường và chi phí dự kiến.',
            'noindex' => true,
            'trips' => $planner->trips($uid),
            'tray' => $tray->items($uid),
            'startDefault' => $startDefault,
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

            return redirect()->route('planner')->withInput()->withErrors(['generate' => $msg]);
        }
        if (!array_filter($result['plan']['days'], fn($d) => !empty($d['items']))) {
            // Không đủ ứng viên thỏa ràng buộc cứng: không lưu chuyến rỗng, nói rõ lý do (mục 12)
            $why = $result['plan']['warnings'] ?? [];

            return redirect()->route('planner')->withInput()->withErrors(['generate' => 'Chưa đủ nội dung phù hợp để xếp lịch với điều kiện này. ' . implode(' ', $why) . ' Hãy thử nới ngân sách, bỏ bớt điều không thích hoặc đổi tháng.']);
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
    public function edit(int $id, TripPlanner $planner)
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

        return $ok ? response()->json(['ok' => true]) : response()->json(['message' => 'Không tìm thấy chuyến đi.'], 404);
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
