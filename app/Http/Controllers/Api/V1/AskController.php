<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Rag\AskLimitException;
use App\Services\Rag\AskService;
use App\Services\Rag\RagUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * G5: POST /api/v1/ask  {question, destination?}  (cần đăng nhập JWT, có giới hạn lượt).
 * Trả: answer, sources[{n,type,title,url,updatedOn,cited}], refused, cited, notice, remaining.
 */
class AskController extends Controller
{
    public function ask(Request $request, AskService $svc): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:' . (int) config('rag.limits.max_question_chars', 300)],
            'destination' => ['nullable', 'string', 'regex:/^[a-z0-9-]{1,80}$/'],
        ]);

        return self::respond($svc, $data['question'], (int) auth('api')->id(), $data['destination'] ?? null);
    }

    /** Dùng chung cho cả API (JWT) và trang web (session). */
    public static function respond(AskService $svc, string $question, int $userId, ?string $destination): JsonResponse
    {
        try {
            $r = $svc->ask($question, $userId, ['destination' => $destination]);
        } catch (AskLimitException $e) {
            return response()->json(['message' => $e->getMessage(), 'remaining' => 0], 429);
        } catch (RagUnavailableException $e) {
            report($e);

            return response()->json(['message' => 'Hỏi đáp chưa sẵn sàng, vui lòng thử lại sau.'], 503);
        }

        return response()->json([
            'answer' => $r['answer'],
            'sources' => $r['sources'],
            'refused' => $r['refused'],
            'cited' => $r['cited'],
            'notice' => $r['notice'],
            'suggest' => $r['suggest'],
            'degraded' => $r['engine'] === 'extractive' && $r['error'] !== null,
            'remaining' => $r['remaining'],
        ]);
    }
}
