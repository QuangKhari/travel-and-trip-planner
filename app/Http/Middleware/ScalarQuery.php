<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Tham số trên URL bị sửa thành mảng (?q[]=x, ?status[a]=b) làm các trang lọc / tìm kiếm lỗi 500 ("Array to string conversion").
 * Chỉ một số khóa được phép là mảng (bộ lọc nhiều lựa chọn); các khóa khác mà là mảng thì bỏ đi, trang xử lý như khi không có tham số đó.
 */
class ScalarQuery
{
    /** Khóa được phép nhận mảng trên URL. */
    public const ARRAY_KEYS = ['interests', 'dislikes', 'so-thich', 'khong-thich', 'cam-hung', 'have', 'ids', 'adjustments', 'said', 'items', 'stops', 'changes'];

    public function handle(Request $request, Closure $next)
    {
        $q = $request->query->all();
        $bad = false;
        foreach ($q as $k => $v) {
            if (is_array($v) && !in_array((string) $k, self::ARRAY_KEYS, true)) {
                unset($q[$k]);
                $bad = true;
            }
        }
        if ($bad) {
            $request->query->replace($q);
        }

        return $next($request);
    }
}
