<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Api\V1\AskController;
use App\Http\Controllers\Controller;
use App\Services\Rag\AskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** G6: trang /hoi-dap và điểm cuối POST /hoi-dap/hoi cho giao diện web (xác thực bằng session như các trang khách khác). */
class AskPageController extends Controller
{
    public function index(Request $request, AskService $svc)
    {
        $title = 'Hỏi đáp du lịch';
        $metaDescription = 'Hỏi nhanh về cẩm nang, điểm đến, mùa đẹp và lễ hội. Mỗi câu trả lời kèm nguồn và ngày cập nhật.';
        $uid = $request->session()->has('userId') ? (int) $request->session()->get('userId') : null;
        $remaining = $uid ? $svc->remaining($uid) : null;

        return view('clients.ask', compact('title', 'metaDescription', 'uid', 'remaining'));
    }

    public function ask(Request $request, AskService $svc): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:' . (int) config('rag.limits.max_question_chars', 300)],
            'destination' => ['nullable', 'string', 'regex:/^[a-z0-9-]{1,80}$/'],
        ]);

        return AskController::respond($svc, $data['question'], (int) $request->session()->get('userId'), $data['destination'] ?? null);
    }
}
