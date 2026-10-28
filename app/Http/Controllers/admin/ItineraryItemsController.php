<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\ItineraryValidator;
use App\Services\Content\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Trình soạn điểm dừng của lịch trình (tbl_itinerary_items): ngày, thứ tự, khung giờ, thời lượng, điểm đến / địa điểm / món / trải nghiệm / lễ hội,
 * mục di chuyển (transport, không gắn bản ghi), ghi chú.
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
        'experience' => ['tbl_experiences', 'experienceId'],
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
                'slot' => $r->slot ?? null,
                'durationMin' => isset($r->durationMin) ? (int) $r->durationMin : null,
            ];
        })->values();
        $check = ItineraryValidator::check($items->all(), (int) $it->days, array_keys(self::REFS));

        return view('admin.itinerary-items', [
            'title' => 'Điểm dừng lịch trình',
            'it' => $it,
            'items' => $items,
            'slots' => array_map(fn($s) => $s[0], (array) config('itinerary.slots')),
            'slotRank' => array_map(fn($s) => $s[1], (array) config('itinerary.slots')),
            'budget' => (int) config('planner.day_budget_min', 480),
            'warnings' => $check['warnings'],
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

        $slotKeys = array_keys((array) config('itinerary.slots'));
        $v = Validator::make(['items' => $payload], [
            'items.*.dayNo' => ['required', 'integer', 'between:1,' . (int) $it->days],
            'items.*.refType' => ['nullable', 'in:' . implode(',', array_merge(array_keys(self::REFS), ['transport']))],
            'items.*.refId' => ['nullable', 'integer', 'min:1'],
            'items.*.label' => ['nullable', 'string', 'max:160'],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
            'items.*.slot' => ['nullable', 'in:' . implode(',', $slotKeys)],
            'items.*.durationMin' => ['nullable', 'integer', 'between:' . (int) config('itinerary.min_minutes', 5) . ',' . (int) config('itinerary.max_minutes', 1440)],
        ], [
            'items.*.dayNo.between' => 'Có điểm dừng ở ngày nằm ngoài khoảng 1 đến ' . (int) $it->days . ' (số ngày của lịch trình). Sửa số ngày của lịch trình hoặc chuyển điểm dừng.',
            'items.*.slot.in' => 'Có điểm dừng với khung giờ không hợp lệ (chỉ sáng, trưa, chiều, tối).',
            'items.*.durationMin.between' => 'Thời lượng mỗi điểm dừng phải từ ' . (int) config('itinerary.min_minutes', 5) . ' đến ' . (int) config('itinerary.max_minutes', 1440) . ' phút.',
        ]);
        if ($v->fails()) {
            return back()->with('error', $v->errors()->first());
        }

        $rows = [];
        $order = [];
        $norm = [];
        foreach ($payload as $i => $p) {
            $type = !empty($p['refType']) ? $p['refType'] : null;
            $refId = $type && $type !== 'transport' && !empty($p['refId']) ? (int) $p['refId'] : null;
            $label = trim((string) ($p['label'] ?? ''));

            if ($type === 'transport') {
                // Di chuyển: không có bản ghi để trỏ tới; tên hiển thị là bắt buộc
                $refId = null;
            } elseif ($type && $refId) {
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

            $slot = !empty($p['slot']) ? (string) $p['slot'] : null;
            $dur = isset($p['durationMin']) && $p['durationMin'] !== '' && $p['durationMin'] !== null ? (int) $p['durationMin'] : null;
            $day = (int) $p['dayNo'];
            $order[$day] = ($order[$day] ?? 0) + 1;
            $rows[] = [
                'itineraryId' => $id,
                'dayNo' => $day,
                'orderNo' => $order[$day],
                'slot' => $slot,
                'durationMin' => $dur,
                'refType' => $type,
                'refId' => $refId,
                'label' => mb_substr($label, 0, 160),
                'note' => ($p['note'] ?? '') !== '' ? $p['note'] : null,
            ];
            $norm[] = ['dayNo' => $day, 'refType' => $type, 'refId' => $refId, 'label' => $label, 'slot' => $slot, 'durationMin' => $dur];
        }

        $check = ItineraryValidator::check($norm, (int) $it->days, array_keys(self::REFS));
        if ($check['errors']) {
            return back()->with('error', $check['errors'][0]);
        }

        DB::transaction(function () use ($id, $rows, $it) {
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

        return back()->with('success', 'Đã lưu ' . count($rows) . ' điểm dừng.')->with('warnings', $check['warnings']);
    }

    /** Gợi ý khi gõ tên: trả tối đa 15 mục khớp. GET ?type=destination|place|food|experience|event&q=... */
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
