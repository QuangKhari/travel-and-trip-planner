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
            // T-29: cá nhân hóa theo người dùng mới
            'interests' => ['nullable', 'array', 'max:6'],
            'interests.*' => ['string', 'in:' . implode(',', array_keys((array) config('interests')))],
            'dislikes' => ['nullable', 'array', 'max:4'],
            'dislikes.*' => ['string', 'in:' . implode(',', array_keys((array) config('planner.dislikes')))],
        ], [
            'startDate.after_or_equal' => 'Ngày đi phải từ hôm nay trở đi.',
        ]);
        try {
            $newId = $c->cloneTrip($this->uid(), $id, array_diff_key($data, ['interests' => 1, 'dislikes' => 1]));
        } catch (\DomainException $e) {
            return back()->withErrors(['use' => $e->getMessage()]);
        }
        if (!$newId) {
            return redirect()->route('community.index')->with('error', 'Hành trình này không còn được chia sẻ.');
        }

        $changes = [];
        if (!empty($data['interests']) || !empty($data['dislikes'])) {
            $changes = app(\App\Services\Planner\TripPersonalizer::class)->apply($newId, (array) ($data['interests'] ?? []), (array) ($data['dislikes'] ?? []));
        }

        return redirect()->route('planner.edit', $newId)->with('personalized', $changes ?: null)
            ->with('success', 'Đã tạo chuyến đi của bạn từ hành trình cộng đồng' . ($changes ? ', La bàn đã điều chỉnh ' . count($changes) . ' mục theo sở thích của bạn' : '') . '. Bạn có thể sửa thoải mái, bản gốc không bị ảnh hưởng.');
    }

    /**
     * POST /cong-dong/{id}/lam-cua-toi {text}: "Làm thành chuyến của bạn" bằng một câu ("2 người, đầu tháng 12, 4 ngày, 5 triệu, thích ăn uống").
     * Đọc câu bằng CÙNG BriefService của trợ lý (luật trước, LLM nếu bật), rồi chép chuyến + chỉnh ngày đi, số người, ngân sách, số ngày,
     * sở thích. Không bịa: phần câu không nói thì giữ như bản gốc; tháng mà không có ngày thì tạm lấy thứ bảy đầu tiên và nói rõ.
     */
    public function remix(Request $request, int $id, TripCommunity $c, \App\Services\Ai\Brief\BriefService $brief)
    {
        $v = $request->validate(['text' => ['required', 'string', 'min:3', 'max:500']], ['text.required' => 'Kể vài ý cho chuyến của bạn, ví dụ: 2 người, đầu tháng 12, 4 ngày.']);
        $src = $c->find($id) ?? abort(404);
        $r = $brief->understand($v['text'], $this->uid() ?: null);
        if (!$r['ok']) {
            return back()->withErrors(['remix' => $r['refused'] ?? 'Chưa hiểu yêu cầu. Thử kể số người, thời gian đi, số ngày.'])->withInput();
        }
        $p = $r['prefill'];
        $said = [];
        $opts = ['groupSize' => (int) $src->groupSize ?: 1];
        if (!empty($p['adults']) || !empty($p['children'])) {
            $opts['groupSize'] = max(1, min(50, (int) ($p['adults'] ?? 0) + (int) ($p['children'] ?? 0)));
            $said[] = $opts['groupSize'] . ' người';
        }
        $start = null;
        if (!empty($p['startDate']) && \Carbon\Carbon::parse($p['startDate'])->gte(today())) {
            $start = \Carbon\Carbon::parse($p['startDate']);
            $said[] = 'từ ' . $start->format('d/m/Y');
        } elseif (!empty($p['month'])) {
            $y = (int) now()->year + ((int) $p['month'] < (int) now()->month ? 1 : 0);
            $start = \Carbon\Carbon::create($y, (int) $p['month'], 1)->startOfDay();
            while ($start->dayOfWeek !== \Carbon\Carbon::SATURDAY || $start->lt(today())) {
                $start->addDay();
            }
            $said[] = 'tạm đi từ ' . $start->format('d/m/Y') . ((int) $start->month === (int) $p['month']
                ? ' (thứ bảy đầu tiên còn kịp của tháng ' . (int) $p['month'] . ', đổi được)'
                : ' (tháng ' . (int) $p['month'] . ' không còn thứ bảy nào phía trước nên lấy thứ bảy gần nhất, đổi được)');
        }
        $opts['startDate'] = $start?->toDateString();
        if (!empty($p['budget'])) {
            $opts['budget'] = (int) $p['budget'];
            $said[] = 'ngân sách ' . number_format((int) $p['budget'], 0, ',', '.') . 'đ';
        }
        try {
            $newId = $c->cloneTrip($this->uid(), $id, $opts);
        } catch (\DomainException $e) {
            return back()->withErrors(['remix' => $e->getMessage()])->withInput();
        }
        if (!$newId) {
            return redirect()->route('community.index')->with('error', 'Hành trình này không còn được chia sẻ.');
        }
        if (!empty($p['days']) && (int) $p['days'] !== (int) $src->days) {
            $moved = app(TripPlanner::class)->resizeDays($newId, (int) $p['days']);
            $said[] = (int) $p['days'] . ' ngày' . ($moved ? ' (' . $moved . ' mục của các ngày bị bớt chuyển vào “Đã lưu”)' : '');
        }
        // câu nhắc tới điểm đến khác: không tự đổi (lịch trình gắn với nơi này), chỉ nói rõ
        $other = $p['destinationSlug'] ?? null;
        $srcSlug = $src->destSlug ?? null;
        $otherNote = $other && $srcSlug && $other !== $srcSlug ? ' Lưu ý: lịch trình này ở ' . ($src->destName ?? 'điểm đến gốc') . '; muốn đi nơi khác hãy tạo chuyến mới.' : '';
        $changes = [];
        if (!empty($p['interests']) || !empty($p['dislikes'])) {
            $changes = app(\App\Services\Planner\TripPersonalizer::class)->apply($newId, (array) ($p['interests'] ?? []), (array) ($p['dislikes'] ?? []));
            $said[] = 'theo sở thích của bạn';
        }

        return redirect()->route('planner.edit', $newId)->with('personalized', $changes ?: null)
            ->with('planner_notice', 'Đã làm chuyến của bạn từ "' . $src->title . '"' . ($said ? ': ' . implode(', ', $said) : '') . '. Bản gốc không bị ảnh hưởng. Hỏi La bàn nếu muốn chỉnh thêm.' . $otherNote);
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
