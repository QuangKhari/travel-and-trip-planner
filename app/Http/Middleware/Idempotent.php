<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chống gửi lặp cho thao tác ghi (mục "Nền móng"): client gửi header Idempotency-Key (AJAX) hoặc ô ẩn _idem (biểu mẫu).
 *  - Lần đầu: giữ khóa ở trạng thái "processing" (UNIQUE scope + khóa), chạy thao tác, lưu phản hồi.
 *  - Gửi lại cùng khóa + cùng nội dung: trả đúng phản hồi đã lưu (header Idempotent-Replayed: true), KHÔNG làm lại.
 *  - Cùng khóa, khác nội dung: 422. Đang chạy dở (bấm hai lần liên tiếp): 409.
 *  - Phản hồi lỗi (4xx / 5xx): bỏ khóa để người dùng sửa rồi gửi lại được; chỉ phản hồi thành công / chuyển trang mới được phát lại.
 * Không có khóa thì đi qua như cũ (tương thích ngược). Phạm vi khóa = tên route + người dùng, nên khóa của người này không chặn người khác.
 */
class Idempotent
{
    public const TTL_HOURS = 24;

    private static ?bool $ready = null;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) ($request->header('Idempotency-Key') ?? $request->input('_idem', ''));
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $key) || !(self::$ready ??= Schema::hasTable('tbl_idempotency_keys'))) {
            return $next($request);
        }
        $who = $request->session()->get('userId') ?? $request->session()->get('adminId') ?? 'ip:' . $request->ip();
        $scope = mb_substr(($request->route()?->getName() ?? $request->path()) . '|' . $who, 0, 120);
        $body = $request->except(['_token', '_idem']);
        ksort($body);
        $hash = sha1($request->method() . ' ' . $request->path() . ' ' . json_encode($body, JSON_UNESCAPED_UNICODE));

        try {
            DB::table('tbl_idempotency_keys')->insert(['scope' => $scope, 'idemKey' => $key, 'requestHash' => $hash, 'status' => 'processing', 'createdAt' => now()]);
        } catch (UniqueConstraintViolationException) {
            $row = DB::table('tbl_idempotency_keys')->where(['scope' => $scope, 'idemKey' => $key])->first();
            if ($row && $row->createdAt < now()->subHours(self::TTL_HOURS)) {
                DB::table('tbl_idempotency_keys')->where('id', $row->id)->delete();   // khóa quá hạn: coi như mới

                return $this->handle($request, $next);
            }

            return $this->replay($request, $row, $hash);
        }

        $res = $next($request);
        if ($res->getStatusCode() >= 400) {
            // lỗi (dữ liệu sai, bản nháp hết hạn, máy chủ lỗi): không giữ khóa để người dùng sửa rồi gửi lại được
            DB::table('tbl_idempotency_keys')->where(['scope' => $scope, 'idemKey' => $key])->delete();

            return $res;
        }
        $isRedirect = $res->isRedirection();
        DB::table('tbl_idempotency_keys')->where(['scope' => $scope, 'idemKey' => $key])->update([
            'status' => 'done', 'responseCode' => $res->getStatusCode(),
            'contentType' => mb_substr((string) $res->headers->get('Content-Type'), 0, 80) ?: null,
            'location' => $isRedirect ? mb_substr((string) $res->headers->get('Location'), 0, 500) : null,
            // chỉ lưu thân phản hồi JSON (trang HTML / tệp không cần phát lại nguyên văn)
            'responseBody' => !$isRedirect && str_contains((string) $res->headers->get('Content-Type'), 'json') ? mb_substr((string) $res->getContent(), 0, 2_000_000) : null,
        ]);

        return $res;
    }

    private function replay(Request $request, ?object $row, string $hash): Response
    {
        $json = $request->expectsJson();
        if (!$row || $row->requestHash !== $hash) {
            $msg = 'Mã chống gửi lặp này đã dùng cho một yêu cầu khác.';

            return $json ? response()->json(['ok' => false, 'message' => $msg], 422) : back()->withErrors(['idem' => $msg]);
        }
        if ($row->status !== 'done') {
            $msg = 'Yêu cầu này đang được xử lý, vui lòng đợi trong giây lát.';

            return $json ? response()->json(['ok' => false, 'message' => $msg], 409) : back()->with('warning', $msg);
        }
        if ($row->location) {
            return redirect()->to($row->location)->withHeaders(['Idempotent-Replayed' => 'true']);
        }
        if ($row->responseBody !== null) {
            return response($row->responseBody, (int) $row->responseCode, ['Content-Type' => $row->contentType ?: 'application/json', 'Idempotent-Replayed' => 'true']);
        }

        return $json ? response()->json(['ok' => true, 'replayed' => true], (int) $row->responseCode) : back();
    }

    /** Dọn khóa quá hạn (gọi từ schedule). */
    public static function prune(): int
    {
        return Schema::hasTable('tbl_idempotency_keys') ? DB::table('tbl_idempotency_keys')->where('createdAt', '<', now()->subHours(self::TTL_HOURS))->delete() : 0;
    }
}
