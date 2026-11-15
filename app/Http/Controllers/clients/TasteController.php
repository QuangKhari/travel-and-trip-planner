<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\PlaceMedia;
use App\Services\Planner\PlaceSignals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /len-ke-hoach/so-thich: "Travela nhớ gì về bạn". Hồ sơ sở thích học từ hành vi (thêm, ghé, bỏ, bỏ qua đề xuất) được hiện rõ:
 * nhóm nào, vì sao (số lần, nơi ví dụ). Người dùng sửa (thích / bình thường / không thích / để Travela tự học), xóa phần đã học,
 * hoặc tạm ngừng học. Bộ xếp lịch dùng đúng hồ sơ đã sửa (PlaceSignals::profile).
 */
class TasteController extends Controller
{
    private function uid(): int
    {
        return (int) session('userId');
    }

    public function index(PlaceSignals $sig)
    {
        $labels = [];
        foreach (PlaceMedia::TILES as $g => [$label, $icon]) {
            $labels[$g] = $label;
        }

        return view('clients.planner.taste', ['title' => 'Travela nhớ gì về bạn', 'rows' => $sig->explain($this->uid()), 'paused' => $sig->paused($this->uid()),
            'labels' => $labels, 'ready' => Schema::hasTable('tbl_user_taste'), 'total' => PlaceSignals::ready() ? DB::table('tbl_place_signals')->where('userId', $this->uid())->count() : 0]);
    }

    /** POST {grp, value: like|neutral|dislike|auto} */
    public function set(Request $request)
    {
        abort_unless(Schema::hasTable('tbl_user_taste'), 404);
        $groups = array_merge(array_keys((array) config('planner.kind_groups')), ['experience', 'food']);
        $v = $request->validate(['grp' => 'required|in:' . implode(',', $groups), 'value' => 'required|in:like,neutral,dislike,auto']);
        $key = ['userId' => $this->uid(), 'grp' => $v['grp']];
        if ($v['value'] === 'auto') {
            DB::table('tbl_user_taste')->where($key)->delete();
        } else {
            DB::table('tbl_user_taste')->updateOrInsert($key, ['value' => ['like' => 1, 'neutral' => 0, 'dislike' => -1][$v['value']], 'updatedAt' => now()]);
        }

        return back()->with('success', 'Đã cập nhật. Lần xếp lịch sau Travela dùng lựa chọn này.');
    }

    /** POST {grp?}: xóa phần đã học (một nhóm hoặc tất cả) + lựa chọn tự sửa của nhóm đó. */
    public function forget(Request $request)
    {
        $grp = $request->input('grp');
        $grp = is_string($grp) && preg_match('/^[a-z_]{2,20}$/', $grp) ? $grp : null;
        if (PlaceSignals::ready()) {
            DB::table('tbl_place_signals')->where('userId', $this->uid())->when($grp, fn($q) => $q->where('grp', $grp))->delete();
        }
        if (Schema::hasTable('tbl_user_taste')) {
            DB::table('tbl_user_taste')->where('userId', $this->uid())->when($grp, fn($q) => $q->where('grp', $grp), fn($q) => $q->where('grp', '<>', '_pause'))->delete();
        }

        return back()->with('success', $grp ? 'Đã xóa những gì Travela học về nhóm này.' : 'Đã xóa toàn bộ sở thích Travela học được.');
    }

    public function pause(Request $request)
    {
        abort_unless(Schema::hasTable('tbl_user_taste'), 404);
        $on = $request->boolean('on');
        DB::table('tbl_user_taste')->updateOrInsert(['userId' => $this->uid(), 'grp' => '_pause'], ['value' => $on ? 1 : 0, 'updatedAt' => now()]);

        return back()->with('success', $on ? 'Đã tạm ngừng học từ thao tác của bạn.' : 'Travela học tiếp từ thao tác của bạn.');
    }
}
