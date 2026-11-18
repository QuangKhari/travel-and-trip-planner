<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Ai\Eval\PeerCases;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Trang thu câu đánh giá do người khác viết (không cần tài khoản, vào bằng đường dẫn có chữ ký: php artisan ai:peer-link ten-nguoi).
 * Người viết chỉ thấy mô tả nhóm câu, không thấy lời nhắc hay kết quả của AI (để bộ câu không "hợp gu" lời nhắc).
 */
class PeerEvalController extends Controller
{
    public function show(Request $request, string $writer)
    {
        abort_unless(PeerCases::ready() && PeerCases::writer($writer), 404);
        $mine = DB::table('tbl_ai_peer_cases')->where('writer', $writer)->orderByDesc('caseId')->get();

        return view('clients.peer.index', [
            'writer' => $writer,
            'groups' => PeerCases::groups(),
            'hints' => PeerCases::HINTS,
            'target' => PeerCases::TARGET_PER_GROUP,
            'mine' => $mine,
            'count' => $mine->countBy('grp'),
            'dests' => DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['slug', 'name']),
            'interests' => array_map(fn($x) => $x['label'] ?? '', (array) config('interests')),
            'dislikes' => ['trekking' => 'Leo núi, đi bộ đường dài', 'crowds' => 'Chỗ đông người', 'outdoor' => 'Ở ngoài trời lâu', 'long_travel' => 'Di chuyển xa'],
            'paces' => ['relaxed' => 'Thong thả', 'normal' => 'Vừa phải', 'packed' => 'Dày, đi nhiều'],
            'postUrl' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, string $writer)
    {
        abort_unless(PeerCases::ready() && PeerCases::writer($writer), 404);
        if ($request->filled('delete')) {
            // xóa câu của chính mình (cùng đường dẫn có chữ ký)
            DB::table('tbl_ai_peer_cases')->where('writer', $writer)->where('caseId', (int) $request->input('delete'))->delete();

            return back()->with('peer_msg', 'Đã xóa câu.');
        }
        $data = $request->validate([
            'grp' => ['required', 'in:' . implode(',', array_keys(PeerCases::GROUP_MAP))],
            'q' => ['required', 'string', 'min:6', 'max:500'],
        ]);
        $q = trim(preg_replace('/\s+/u', ' ', (string) $data['q']) ?? '');
        if (DB::table('tbl_ai_peer_cases')->where('writer', $writer)->where('q', $q)->exists()) {
            return back()->with('peer_msg', 'Bạn đã gửi câu này rồi.');
        }
        DB::table('tbl_ai_peer_cases')->insert(['writer' => $writer, 'grp' => $data['grp'], 'q' => $q,
            'expect' => json_encode(PeerCases::expect($request->all(), $data['grp']), JSON_UNESCAPED_UNICODE), 'createdAt' => now()]);

        return back()->with('peer_msg', 'Đã lưu. Cảm ơn bạn! Viết tiếp câu khác nhé.');
    }
}
