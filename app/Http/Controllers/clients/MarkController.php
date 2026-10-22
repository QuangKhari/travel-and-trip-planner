<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\TripTray;
use App\Services\Content\UserMarks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** B6: Lưu / Đã đến / Đã thử / Đã dự và khay chuyến đi. Cả hai cần đăng nhập (middleware checkLoginClient trả 401 JSON cho fetch). */
class MarkController extends Controller
{
    /** POST /danh-dau  {type, refType, slug} -> {marked: bool, counts: {...}} */
    public function toggle(Request $request, UserMarks $marks): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:saved,visited,tried,attended'],
            'refType' => ['required', 'in:destination,food,event'],
            'slug' => ['required', 'string', 'max:120'],
        ]);
        if (!UserMarks::allowed($data['type'], $data['refType'])) {
            return response()->json(['message' => 'Loại đánh dấu không áp dụng cho mục này.'], 422);
        }
        $id = $marks->resolve($data['refType'], $data['slug']);
        if (!$id) {
            return response()->json(['message' => 'Không tìm thấy nội dung.'], 404);
        }
        $uid = (int) $request->session()->get('userId');

        return response()->json(['marked' => $marks->toggle($uid, $data['type'], $data['refType'], $id), 'counts' => $marks->counts($uid)]);
    }

    /** POST /khay-chuyen-di/dong-bo  {items:[{type,slug}], removed:[{type,slug}]} -> {items:[...]} */
    public function syncTray(Request $request, TripTray $tray): JsonResponse
    {
        $data = $request->validate([
            'items' => ['array', 'max:' . TripTray::MAX_ITEMS],
            'removed' => ['array', 'max:' . TripTray::MAX_ITEMS],
        ]);
        $uid = (int) $request->session()->get('userId');
        foreach ($data['removed'] ?? [] as $r) {
            if (is_array($r) && is_string($r['type'] ?? null) && is_string($r['slug'] ?? null)) {
                $tray->remove($uid, $r['type'], $r['slug']);
            }
        }

        return response()->json(['items' => $tray->sync($uid, $data['items'] ?? [])]);
    }
}
