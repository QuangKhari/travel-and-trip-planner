<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Planner\ContentRequests;
use Illuminate\Http\Request;

/**
 * "Nhu cầu chưa có dữ liệu": các nơi/điểm đến khách gõ mà Travela chưa có, xếp theo số lần.
 * Biên tập viên dựa vào đây để chọn nội dung cần viết trước; viết xong thì đánh dấu đã xử lý.
 * Không có thông tin người tìm.
 */
class ContentRequestAdminController extends Controller
{
    public function index(Request $request, ContentRequests $log)
    {
        $all = $request->boolean('all');

        return view('admin.content-requests.index', [
            'title' => 'Nhu cầu chưa có dữ liệu',
            'rows' => $log->top($all, 200),
            'all' => $all,
        ]);
    }

    public function resolve(Request $request, int $id, ContentRequests $log)
    {
        $on = $request->boolean('on', true);
        $ok = $log->resolve($id, $on);

        return back()->with($ok ? 'success' : 'error', $ok ? ($on ? 'Đã đánh dấu đã xử lý.' : 'Đã mở lại.') : 'Không tìm thấy yêu cầu.');
    }
}
