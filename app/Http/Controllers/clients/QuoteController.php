<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\DepartureService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A4 / C-04 / C-12: báo giá do MÁY CHỦ tính và số chỗ còn lại theo ngày khởi hành.
 *   GET /dat-tour/bao-gia?tourId&availabilityId&adults&children&coupon  -> {subtotal, discount, total, remaining, couponError}
 *   GET /tour/{id}/cho-trong                                           -> [{id, date, remaining, bookable}]  (trang chi tiết hỏi lại mỗi 20 giây)
 * Chỉ để hiển thị: khi đặt, BookingService tính lại toàn bộ trong transaction.
 */
class QuoteController extends Controller
{
    public function quote(Request $request, PricingService $pricing, DepartureService $deps)
    {
        $v = Validator::make($request->query(), [
            'tourId' => 'required|integer|min:1',
            'availabilityId' => 'nullable|integer|min:1',
            'adults' => 'required|integer|min:1|max:50',
            'children' => 'nullable|integer|min:0|max:50',
            'coupon' => 'nullable|string|max:50',
            'addons' => 'nullable|array|max:20',
            'addons.*' => 'integer|min:1',
        ]);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'message' => $v->errors()->first()], 422);
        }
        $d = $v->validated();
        $tour = DB::table('tbl_tours')->where('tourId', $d['tourId'])->where('availability', 1)->first();
        if (!$tour) {
            return response()->json(['ok' => false, 'message' => 'Tour không tồn tại hoặc đã ngừng nhận đặt.'], 404);
        }
        $list = collect($deps->forTour((int) $tour->tourId, true));
        $dep = !empty($d['availabilityId']) ? $list->firstWhere('availabilityId', (int) $d['availabilityId']) : ($list->firstWhere('departureDate', $tour->startDate) ?? $list->first());
        $people = (int) $d['adults'] + (int) ($d['children'] ?? 0);
        $q = $pricing->quote($tour, (int) $d['adults'], (int) ($d['children'] ?? 0), $d['coupon'] ?? null, (int) (session('userId') ?? 0), $d['addons'] ?? []);

        return response()->json([
            'ok' => true,
            'subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'total' => $q['total'],
            'percent' => $q['percent'], 'couponError' => $q['couponError'], 'addons' => $q['addons'],
            'availabilityId' => $dep->availabilityId ?? null,
            'departureDate' => $dep->departureDate ?? $tour->startDate,
            'remaining' => $dep ? $dep->remaining : (int) $tour->quantity,
            'enough' => ($dep ? $dep->remaining : (int) $tour->quantity) >= $people,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function seats(int $id, DepartureService $deps)
    {
        // cache 15 giây (B1): nhiều người cùng xem một tour chỉ tốn một truy vấn; giữ / trả chỗ xóa cache ngay
        $data = \Illuminate\Support\Facades\Cache::remember(DepartureService::seatsKey($id), DepartureService::SEATS_TTL, fn() => [
            'at' => now()->toIso8601String(),
            'departures' => collect($deps->forTour($id, true))->map(fn($d) => [
                'id' => $d->availabilityId, 'date' => $d->departureDate, 'remaining' => $d->remaining, 'bookable' => $d->bookable,
            ])->values()->all(),
        ]);

        return response()->json(['tourId' => $id] + $data, 200, ['Cache-Control' => 'no-store']);
    }
}
