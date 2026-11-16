<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Study\StudySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Đợt F: thanh nhiệm vụ buổi thử, phiếu đồng ý, phiếu SUS. Chỉ dành cho tài khoản người thử khi STUDY_ENABLED bật. */
class StudyController extends Controller
{
    private function p(): object
    {
        return StudySession::participant((int) session('userId')) ?? abort(404);
    }

    public function consentForm()
    {
        $p = $this->p();

        return view('clients.study.consent', ['title' => 'Tham gia buổi thử', 'p' => $p, 'lines' => (array) config('study.consent')]);
    }

    public function consent(Request $request)
    {
        $p = $this->p();
        $request->validate(['agree' => ['accepted'], 'device' => ['required', 'in:desktop,mobile']], ['agree.accepted' => 'Bạn cần đồng ý để bắt đầu.']);
        StudySession::consent($p, $request->input('device'));

        return redirect()->route('home')->with('success', 'Cảm ơn bạn. Nhiệm vụ 1 nằm ở thanh phía dưới màn hình.');
    }

    public function state(): JsonResponse
    {
        return response()->json(StudySession::state($this->p()));
    }

    public function start(Request $request): JsonResponse
    {
        $r = StudySession::start($this->p(), (string) $request->input('task'));

        return response()->json($r + ['state' => StudySession::state($this->p())], $r['ok'] ? 200 : 422);
    }

    public function finish(Request $request): JsonResponse
    {
        $v = $request->validate(['task' => ['required', 'string', 'max:32'], 'giveUp' => ['boolean'], 'clicks' => ['nullable', 'integer', 'min:0', 'max:60000'], 'pages' => ['nullable', 'integer', 'min:0', 'max:5000']]);
        $r = StudySession::finish($this->p(), $v['task'], (bool) ($v['giveUp'] ?? false), $v['clicks'] ?? null, $v['pages'] ?? null);

        return response()->json($r + ['state' => StudySession::state($this->p())], $r['ok'] ? 200 : 422);
    }

    public function susForm()
    {
        $p = $this->p();

        return view('clients.study.sus', ['title' => 'Phiếu đánh giá', 'p' => $p, 'questions' => (array) config('study.sus'),
            'done' => \Illuminate\Support\Facades\DB::table('tbl_study_sus')->where('participantId', $p->participantId)->value('score')]);
    }

    public function sus(Request $request)
    {
        $p = $this->p();
        $rules = ['comment' => ['nullable', 'string', 'max:2000']];
        foreach (range(1, 10) as $i) {
            $rules['q' . $i] = ['required', 'integer', 'between:1,5'];
        }
        $v = $request->validate($rules, ['required' => 'Bạn trả lời giúp đủ 10 câu.']);
        StudySession::saveSus($p, array_map(fn($i) => (int) $v['q' . $i], range(1, 10)), $v['comment'] ?? null);

        return redirect()->route('study.sus.form');
    }
}
