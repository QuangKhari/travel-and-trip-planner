<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripCommunity;
use App\Services\Planner\TripPlanner;
use Illuminate\Http\Request;

/** Cộng đồng hành trình: /cong-dong (danh sách), /cong-dong/{id} (xem), thích, dùng lại, công khai chuyến của mình. */
class CommunityController extends Controller
{
    private function uid(): int
    {
        return (int) session('userId');
    }

    public function index(Request $request, TripCommunity $c)
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'dest' => ['nullable', 'integer', 'min:1'],
            'days' => ['nullable', 'in:' . implode(',', array_keys(TripCommunity::DAY_RANGES))],
            'budget' => ['nullable', 'in:' . implode(',', array_keys(TripCommunity::BUDGET_RANGES))],
            'interest' => ['nullable', 'in:' . implode(',', array_keys((array) config('interests', [])))],
            'tab' => ['nullable', 'in:' . implode(',', array_keys(TripCommunity::TABS))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $f['tab'] ??= 'noi-bat';

        return view('clients.community.index', [
            'title' => 'Cộng đồng hành trình',
            'metaDescription' => 'Lịch trình du lịch do thành viên Travela chia sẻ: xem, thích và dùng lại cho chuyến đi của bạn.',
            'feed' => $c->feed($f),
            'f' => $f,
            'destinations' => $c->destinations(),
            'interests' => (array) config('interests', []),
        ]);
    }

    public function show(int $id, TripCommunity $c, TripPlanner $planner)
    {
        $trip = $c->find($id) ?? abort(404);
        $c->countView($trip, $this->uid() ?: null);
        $uid = $this->uid();

        return view('clients.community.show', [
            'title' => $trip->title,
            'metaDescription' => $trip->summary ?: ('Lịch trình ' . $trip->days . ' ngày' . ($trip->destName ? ' ở ' . $trip->destName : '') . ' do thành viên Travela chia sẻ.'),
            'trip' => $trip,
            'payload' => $planner->payload($trip),
            'liked' => $uid ? $c->liked($uid, $id) : false,
            'isOwner' => $uid === (int) $trip->userId,
            'related' => $c->related($trip),
            'interests' => (array) config('interests', []),
        ]);
    }

    public function like(int $id, TripCommunity $c)
    {
        $r = $c->toggleLike($this->uid(), $id) ?? abort(404);

        return request()->expectsJson()
            ? response()->json(['liked' => $r[0], 'likes' => $r[1]])
            : back();
    }

    public function useTrip(Request $request, int $id, TripCommunity $c)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'startDate' => ['nullable', 'date', 'after_or_equal:today'],
            'groupSize' => ['required', 'integer', 'between:1,50'],
            'budget' => ['nullable', 'numeric', 'between:0,100000000000'],
        ], [
            'startDate.after_or_equal' => 'Ngày đi phải từ hôm nay trở đi.',
        ]);
        try {
            $newId = $c->cloneTrip($this->uid(), $id, $data);
        } catch (\DomainException $e) {
            return back()->withErrors(['use' => $e->getMessage()]);
        }
        if (!$newId) {
            return redirect()->route('community.index')->with('error', 'Hành trình này không còn được chia sẻ.');
        }

        return redirect()->route('planner.edit', $newId)->with('success', 'Đã tạo chuyến đi của bạn từ hành trình cộng đồng. Bạn có thể sửa thoải mái, bản gốc không bị ảnh hưởng.');
    }

    /** Chủ chuyến bật / tắt chia sẻ lên cộng đồng (từ trang sửa chuyến). */
    public function publish(Request $request, int $id, TripCommunity $c)
    {
        $data = $request->validate([
            'action' => ['required', 'in:publish,unpublish'],
            'summary' => ['nullable', 'string', 'max:300'],
        ]);
        if ($data['action'] === 'unpublish') {
            $ok = $c->unpublish($this->uid(), $id);
            $msg = $ok ? 'Đã thôi chia sẻ. Những người đã dùng lại hành trình vẫn giữ bản của họ.' : null;
            $err = $ok ? null : 'Chỉ chủ chuyến mới đổi được chế độ chia sẻ.';
        } else {
            $err = $c->publish($this->uid(), $id, $data['summary'] ?? null);
            $msg = $err ? null : 'Chuyến đi đã xuất hiện trên trang Cộng đồng.';
        }
        if ($request->expectsJson()) {
            return $err ? response()->json(['message' => $err], 422) : response()->json(['ok' => true, 'message' => $msg, 'url' => route('community.show', $id)]);
        }

        return back()->with($err ? 'error' : 'success', $err ?? $msg);
    }
}
