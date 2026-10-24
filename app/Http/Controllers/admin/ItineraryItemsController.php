<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Trình soạn điểm dừng của lịch trình (tbl_itinerary_items): ngày, thứ tự, điểm đến / địa điểm / món / lễ hội, ghi chú.
 * Lưu theo kiểu "thay cả bộ" trong một giao dịch: gửi lên toàn bộ danh sách, server xóa cũ rồi chèn mới.
 */
class ItineraryItemsController extends Controller
{
    /** refType => [bảng, khóa] */
    private const REFS = [
        'destination' => ['tbl_destinations', 'destinationId'],
        'place' => ['tbl_external_places', 'placeId'],
        'food' => ['tbl_foods', 'foodId'],
        'event' => ['tbl_events', 'eventId'],
    ];

    public function edit(int $id)
    {
        $it = DB::table('tbl_itineraries')->where('itineraryId', $id)->first() ?? abort(404);
        $rows = DB::table('tbl_itinerary_items')->where('itineraryId', $id)->orderBy('dayNo')->orderBy('orderNo')->get();

        $items = $rows->map(function ($r) {
            $name = null;
            if ($r->refType && $r->refId && isset(self::REFS[$r->refType])) {
                [$table, $key] = self::REFS[$r->refType];
                $name = DB::table($table)->where($key, $r->refId)->value('name');
            }

            return [
                'dayNo' => (int) $r->dayNo,
                'refType' => $r->refType,
                'refId' => $r->refId ? (int) $r->refId : null,
                'refName' => $name,
                'label' => $r->label,
                'note' => $r->note,
            ];
        })->values();

        return view('admin.itinerary-items', [
            'title' => 'Điểm dừng lịch trình',
            'it' => $it,
            'items' => $items,
        ]);
    }

    public function save(Request $request, int $id)
    {
        $it = DB::table('tbl_itineraries')->where('itineraryId', $id)->first() ?? abort(404);
        $payload = json_decode((string) $request->input('payload', '[]'), true);
        if (!is_array($payload)) {
            return back()->with('error', 'Dữ liệu gửi lên không hợp lệ.');
        }
        if (count($payload) > 300) {
            return back()->with('error', 'Tối đa 300 điểm dừng cho một lịch trình.');
        }

        $v = Validator::make(['items' => $payload], [
            'items.*.dayNo' => ['required', 'integer', 'between:1,' . (int) $it->days],
            'items.*.refType' => ['nullable', 'in:' . implode(',', array_keys(self::REFS))],
            'items.*.refId' => ['nullable', 'integer', 'min:1'],
            'items.*.label' => ['nullable', 'string', 'max:160'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
        ], [
            'items.*.dayNo.between' => 'Có điểm dừng ở ngày nằm ngoài khoảng 1 đến ' . (int) $it->days . ' (số ngày của lịch trình). Sửa số ngày của lịch trình hoặc chuyển điểm dừng.',
        ]);
        if ($v->fails()) {
            return back()->with('error', $v->errors()->first());
        }

        $rows = [];
        $order = [];
        foreach ($payload as $i => $p) {
            $type = !empty($p['refType']) ? $p['refType'] : null;
            $refId = $type && !empty($p['refId']) ? (int) $p['refId'] : null;
            $label = trim((string) ($p['label'] ?? ''));

            if ($type && $refId) {
                [$table, $key] = self::REFS[$type];
                $name = DB::table($table)->where($key, $refId)->value('name');
                if ($name === null) {
                    return back()->with('error', 'Dòng ' . ($i + 1) . ': mục đã chọn không còn tồn tại.');
                }
                $label = $label !== '' ? $label : (string) $name;
            } else {
                $type = $refId = null;
            }
            if ($label === '') {
                return back()->with('error', 'Dòng ' . ($i + 1) . ': nhập tên điểm dừng hoặc chọn một mục có sẵn.');
            }

            $day = (int) $p['dayNo'];
            $order[$day] = ($order[$day] ?? 0) + 1;
            $rows[] = [
                'itineraryId' => $id,
                'dayNo' => $day,
                'orderNo' => $order[$day],
                'refType' => $type,
                'refId' => $refId,
                'label' => mb_substr($label, 0, 160),
                'note' => ($p['note'] ?? '') !== '' ? $p['note'] : null,
            ];
        }

        DB::transaction(function () use ($id, $rows) {
            DB::table('tbl_itinerary_items')->where('itineraryId', $id)->delete();
            if ($rows) {
                DB::table('tbl_itinerary_items')->insert($rows);
            }
            DB::table('tbl_itineraries')->where('itineraryId', $id)->update(['updatedAt' => now()]);
            DB::table('tbl_content_review_log')->insert([
                'kind' => 'itineraries', 'refId' => $id, 'action' => 'update', 'fromStatus' => $it->reviewStatus, 'toStatus' => $it->reviewStatus,
                'adminId' => session('adminId'), 'note' => 'Sửa điểm dừng (' . count($rows) . ' điểm)', 'createdAt' => now(),
            ]);
        });
        PublicCache::flush();

        return back()->with('success', 'Đã lưu ' . count($rows) . ' điểm dừng.');
    }

    /** Gợi ý khi gõ tên: trả tối đa 15 mục khớp. GET ?type=destination|place|food|event&q=... */
    public function lookup(Request $request)
    {
        $type = (string) $request->query('type');
        if (!isset(self::REFS[$type])) {
            return response()->json([]);
        }
        [$table, $key] = self::REFS[$type];
        $q = trim((string) $request->query('q', ''));

        $rows = DB::table($table)
            ->when($q !== '', fn($w) => $w->where('name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'))
            ->orderBy('name')->limit(15)->get([$key . ' as id', 'name']);

        return response()->json($rows);
    }
}
