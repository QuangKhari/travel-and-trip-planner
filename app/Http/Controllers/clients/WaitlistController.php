<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\BookingException;
use App\Services\BookingService;
use App\Services\Payments\OnlinePaymentService;
use App\Services\WaitlistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Danh sách chờ phía khách: đăng ký khi hết chỗ, xem / rút, xác nhận lời mời thành đơn thật. */
class WaitlistController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Danh sách chờ';
        $userId = (int) $this->getUserId();
        $ready = WaitlistService::ready();
        $rows = collect();
        $pick = null;
        if ($ready) {
            $rows = DB::table('tbl_waitlist as w')->join('tbl_tour_availability as a', 'a.availabilityId', '=', 'w.availabilityId')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
                ->where('w.userId', $userId)->orderByDesc('w.waitId')->limit(30)->get(['w.*', 'a.departureDate', 't.title', 't.tourId']);
            foreach ($rows as $r) {
                $r->position = $r->status === 'waiting'
                    ? (int) DB::table('tbl_waitlist')->where('availabilityId', $r->availabilityId)->where('status', 'waiting')->where(fn ($q) => $q->where('createdAt', '<', $r->createdAt)->orWhere(fn ($w) => $w->where('createdAt', $r->createdAt)->where('waitId', '<', $r->waitId)))->count() + 1
                    : null;
            }
            if ($request->query('availabilityId')) {
                $pick = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')->where('a.availabilityId', (int) $request->query('availabilityId'))
                    ->first(['a.availabilityId', 'a.departureDate', 'a.totalSlots', 'a.bookedSlots', 'a.heldSlots', 'a.status', 't.title', 't.tourId']);
            }
        }

        return view('clients.waitlist.index', compact('title', 'ready', 'rows', 'pick'));
    }

    public function join(Request $request, WaitlistService $svc)
    {
        $d = $request->validate(['availabilityId' => 'required|integer|min:1', 'numAdults' => 'required|integer|min:1|max:50', 'numChildren' => 'required|integer|min:0|max:50']);
        $r = $svc->join((int) $this->getUserId(), (int) $d['availabilityId'], (int) $d['numAdults'], (int) $d['numChildren']);

        return redirect()->route('waitlist.index')->with($r['ok'] ? 'success' : 'error', $r['message']);
    }

    public function cancel(int $id, WaitlistService $svc)
    {
        $ok = $svc->cancel($id, (int) $this->getUserId());

        return redirect()->route('waitlist.index')->with($ok ? 'success' : 'error', $ok ? 'Đã rút khỏi danh sách chờ.' : 'Không rút được (đã được xử lý trước đó).');
    }

    public function offer(string $token, WaitlistService $svc)
    {
        $userId = (int) $this->getUserId();
        $w = $svc->findOffer($token, $userId);
        if (!$w) {
            return redirect()->route('waitlist.index')->with('error', 'Lời mời không còn hiệu lực (đã hết hạn hoặc đã dùng).');
        }
        $u = DB::table('tbl_users')->where('userId', $userId)->first(['fullName', 'email', 'phoneNumber', 'address']);
        $title = 'Xác nhận lời mời';
        $methods = array_merge(['office-payment'], OnlinePaymentService::methods());
        $subtotal = (int) round((float) $w->priceAdult * (int) $w->numAdults + (float) $w->priceChild * (int) $w->numChildren);

        return view('clients.waitlist.offer', compact('title', 'w', 'u', 'methods', 'subtotal'));
    }

    public function accept(Request $request, string $token, WaitlistService $svc, BookingService $bookings)
    {
        $v = Validator::make($request->all(), [
            'fullName' => 'required|string|max:255',
            'email' => ['required', 'email:filter', 'max:50'],
            'tel' => ['required', 'regex:/^[0-9]{10,11}$/'],
            'address' => 'required|string|max:255',
            'payment' => 'required|in:' . implode(',', array_merge(['office-payment'], OnlinePaymentService::methods())),
            'couponCode' => 'nullable|string|max:50',
        ], ['tel.regex' => 'Số điện thoại phải có 10-11 chữ số.', 'payment.in' => 'Phương thức thanh toán không hợp lệ.']);
        if ($v->fails()) {
            return back()->withInput()->withErrors($v);
        }
        try {
            $result = $svc->accept($token, (int) $this->getUserId(), $v->validated(), $bookings);
        } catch (BookingException $e) {
            return redirect()->route('waitlist.offer', ['token' => $token])->withInput()->with('error', $e->getMessage());
        }
        $method = (string) $v->validated()['payment'];
        if (OnlinePaymentService::isOnline($method) && empty($result['duplicate'])) {
            return redirect()->route('payment.pay', ['bookingId' => $result['bookingId'], 'method' => $method]);
        }
        $checkoutId = (int) DB::table('tbl_checkout')->where('bookingId', $result['bookingId'])->value('checkoutId');

        return redirect()->route('tour-booked', ['bookingId' => $result['bookingId'], 'checkoutId' => $checkoutId])
            ->with('success', 'Đã đặt tour từ danh sách chờ. Mã đơn: ' . $result['bookingCode']);
    }
}
