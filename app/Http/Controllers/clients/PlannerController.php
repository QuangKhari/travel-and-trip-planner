<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\TripTray;
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
    public function index(Request $request, TripPlanner $planner, TripTray $tray)
    {
        $uid = $this->uid();
        $month = (int) $request->query('thang');
        $startDefault = null;
        if ($month >= 1 && $month <= 12) {
            $y = (int) date('Y') + ($month < (int) date('n') ? 1 : 0);
            $startDefault = sprintf('%04d-%02d-01', $y, $month);
        }

        return view('clients.planner.index', [
            'title' => 'Lên kế hoạch chuyến đi',
            'metaDescription' => 'Xếp các điểm đến, món ăn, lễ hội và tour của bạn vào từng ngày, xem quãng đường và chi phí dự kiến.',
            'noindex' => true,
            'trips' => $planner->trips($uid),
            'tray' => $tray->items($uid),
            'startDefault' => $startDefault,
        ]);
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
