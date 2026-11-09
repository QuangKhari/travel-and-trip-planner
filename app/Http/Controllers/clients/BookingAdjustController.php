<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\BookingAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Khách tự điều chỉnh số người của đơn: bước 1 xem trước giá, bước 2 xác nhận. */
class BookingAdjustController extends Controller
{
    public function adjust(Request $request, int $bookingId, BookingAdjustmentService $svc)
    {
        $userId = (int) $this->getUserId();
        $d = $request->validate(['numAdults' => 'required|integer|min:1|max:50', 'numChildren' => 'required|integer|min:0|max:50']);
        $co = (int) DB::table('tbl_checkout')->where('bookingId', $bookingId)->value('checkoutId');
        $back = redirect()->route('tour-booked', ['bookingId' => $bookingId, 'checkoutId' => $co]);
        $quote = $svc->adjust($bookingId, (int) $d['numAdults'], (int) $d['numChildren'], 'customer', $userId, $userId, null, true);
        if (!$quote['ok']) {
            return $back->with('error', $quote['message']);
        }
        if (!$request->boolean('confirm')) {
            return $back->withInput()->with('adjustQuote', $quote);       // hiện bản xem trước, chờ khách xác nhận
        }
        $res = $svc->adjust($bookingId, (int) $d['numAdults'], (int) $d['numChildren'], 'customer', $userId, $userId);

        return $back->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Đã cập nhật số khách của đơn.' : $res['message']);
    }
}
