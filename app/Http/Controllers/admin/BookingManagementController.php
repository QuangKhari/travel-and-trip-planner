<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplateMail;
use App\Models\admin\BookingModel;
use App\Services\BookingService;
use App\Services\RefundService;
use App\Services\UserMediaService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use App\Support\CancelPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Quản lý đơn đặt tour theo máy trạng thái (§6.4, D-03):
 *   n → y (xác nhận)   n/y → c (hủy; đã thanh toán thì tạo yêu cầu hoàn tiền)   y → f (hoàn tất khi chuyến đã về)
 *   thanh toán: n → y (đã thu)   y → refund_pending → refunded (xem RefundManagementController)
 * Mọi thao tác chạy trong transaction có khóa dòng, bấm hai lần không đổi kết quả, và được ghi nhật ký (D-08).
 */
class BookingManagementController extends Controller
{
    private BookingModel $booking;

    public const STATUS = ['n' => ['warn', 'Chờ xác nhận'], 'y' => ['info', 'Đã xác nhận'], 'f' => ['ok', 'Hoàn tất'], 'c' => ['bad', 'Đã hủy']];
    public const PAY = ['n' => ['bad', 'Chưa thanh toán'], 'y' => ['ok', 'Đã thanh toán'], 'refund_pending' => ['violet', 'Chờ hoàn tiền'], 'refunded' => ['mute', 'Đã hoàn tiền']];
    public const CANCEL_REASON = ['customer' => 'khách hủy', 'admin' => 'admin hủy', 'expired' => 'hết hạn giữ chỗ', 'shortfall' => 'Travela hủy (thiếu khách)'];
    public const METHOD = ['office-payment' => 'Tại văn phòng', 'momo' => 'Ví MoMo', 'zalopay' => 'ZaloPay', 'qr' => 'QR chuyển khoản', 'vnpay' => 'VNPay (cũ)', 'momo-payment' => 'MoMo', 'paypal-payment' => 'PayPal', 'bank-transfer' => 'Chuyển khoản'];

    public function __construct()
    {
        parent::__construct();
        $this->booking = new BookingModel();
    }

    public function transferProof($id, UserMediaService $media)
    {
        $path = DB::table('tbl_booking')->where('bookingId', (int) $id)->value('transferProofImage');
        if (!$path) {
            abort(404);
        }
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline'];

        if ($media->isProofPath($path)) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = $media->proofDisk();
            if (!$disk->exists($path)) {
                abort(404);
            }

            return $disk->response($path, null, $headers);
        }
        $legacy = public_path('clients/assets/images/transfer-proofs/' . basename($path));
        if (is_file($legacy)) {
            return response()->file($legacy, $headers);
        }
        abort(404);
    }

    public function index()
    {
        $title = 'Đơn đặt tour';
        $list_booking = $this->decorate($this->booking->getBooking());
        $counts = $this->counts($list_booking);

        return view('admin.booking', compact('title', 'list_booking', 'counts'));
    }

    /**
     * Xuất danh sách đơn ra CSV (mở được bằng Excel, có BOM để không lỗi tiếng Việt).
     * Lọc: ?status=n|y|f|c  ?from=YYYY-MM-DD  ?to=YYYY-MM-DD (theo ngày đặt). Chỉ admin, manager (file có PII).
     */
    public function export(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:n,y,f,c'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rows = $this->booking->getBooking()->filter(function ($b) use ($f) {
            if (!empty($f['status']) && $b->bookingStatus !== $f['status']) {
                return false;
            }
            $day = date('Y-m-d', strtotime((string) $b->bookingDate));

            return !((!empty($f['from']) && $day < $f['from']) || (!empty($f['to']) && $day > $f['to']));
        });

        // Chống "CSV injection": ô bắt đầu bằng = + - @ bị Excel hiểu là công thức
        $safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
        AuditLog::record('booking.export', 'booking', null, 'Xuất CSV ' . $rows->count() . ' đơn', null, $f);

        return response()->streamDownload(function () use ($rows, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Mã đơn', 'Mã booking', 'Tour', 'Ngày đi', 'Ngày về', 'Khách hàng', 'Email', 'Số điện thoại', 'Địa chỉ',
                'Ngày đặt', 'Người lớn', 'Trẻ em', 'Tổng tiền', 'Trạng thái đơn', 'Phương thức TT', 'Thanh toán', 'Hoàn tiền']);
            foreach ($rows as $b) {
                fputcsv($out, [
                    $b->bookingId, $b->bookingCode, $safe($b->title), $b->tripStart, $b->tripEnd,
                    $safe($b->fullName), $safe($b->email), $safe($b->phoneNumber), $safe($b->address),
                    $b->bookingDate, $b->numAdults, $b->numChildren, (int) $b->totalPrice,
                    self::STATUS[$b->bookingStatus][1] ?? $b->bookingStatus,
                    self::METHOD[$b->paymentMethod] ?? $b->paymentMethod,
                    self::PAY[$b->paymentStatus][1] ?? $b->paymentStatus,
                    isset($b->refundAmount) ? (int) $b->refundAmount : '',
                ]);
            }
            fclose($out);
        }, 'don-dat-tour-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------------
     * Đặt tour hộ khách tại văn phòng (cùng BookingService với phía khách: cùng luật chỗ, giá, mã giảm giá)
     * ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $title = 'Đặt tour hộ khách';
        $tours = DB::table('tbl_tours')->where('availability', 1)->where('startDate', '>', now()->toDateString())
            ->orderBy('title')->get(['tourId', 'title', 'priceAdult', 'priceChild', 'quantity', 'startDate']);
        $user = $request->query('user') ? DB::table('tbl_users')->where('userId', (int) $request->query('user'))->first(['userId', 'fullName', 'email', 'phoneNumber', 'address']) : null;
        $users = DB::table('tbl_users')->whereNull('status')->orderBy('fullName')->limit(500)->get(['userId', 'fullName', 'email', 'phoneNumber', 'address']);

        return view('admin.booking-create', compact('title', 'tours', 'user', 'users'));
    }

    /** JSON: các ngày khởi hành còn bán của một tour (cho form đặt hộ). */
    public function departures(Request $request, \App\Services\DepartureService $deps)
    {
        $id = (int) $request->query('tour');

        return response()->json(collect($deps->forTour($id, true))->filter(fn($d) => $d->remaining > 0)->map(fn($d) => [
            'id' => $d->availabilityId, 'date' => Carbon::parse($d->departureDate)->format('d/m/Y'), 'end' => Carbon::parse($d->endDate)->format('d/m/Y'), 'left' => $d->remaining,
        ])->values());
    }

    public function store(Request $request, BookingService $bookings)
    {
        $d = $request->validate([
            'userId' => 'required|integer|exists:tbl_users,userId',
            'tourId' => 'required|integer|exists:tbl_tours,tourId',
            'availabilityId' => 'nullable|integer',
            'numAdults' => 'required|integer|min:1|max:50',
            'numChildren' => 'required|integer|min:0|max:50',
            'fullName' => 'required|string|max:255',
            'email' => ['required', 'email:filter', 'max:50'],
            'tel' => ['required', 'regex:/^[0-9]{10,11}$/'],
            'address' => 'required|string|max:255',
            'couponCode' => 'nullable|string|max:50',
            'paidNow' => 'nullable|boolean',
            'requestToken' => 'required|uuid',
        ], ['tel.regex' => 'Số điện thoại phải có 10–11 chữ số.', 'userId.required' => 'Chọn tài khoản khách.']);
        $paidNow = (bool) ($d['paidNow'] ?? false) && in_array(session('adminRole'), ['admin', 'manager'], true);

        try {
            $r = $bookings->create((int) $d['userId'], $d + ['payment' => 'office-payment']);
        } catch (\App\Services\BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        if ($r['duplicate']) {
            return redirect()->route('admin.booking-detail', ['id' => $r['bookingId']])->with('success', 'Đơn này đã được tạo trước đó.');
        }
        AuditLog::record('booking.create', 'booking', $r['bookingId'], 'Đặt tour hộ khách, đơn ' . $r['bookingCode'] . ($paidNow ? ' (đã thu tiền tại quầy)' : ''), null,
            ['tourId' => (int) $d['tourId'], 'availabilityId' => $r['availabilityId'] ?? null, 'guests' => $d['numAdults'] + $d['numChildren'], 'total' => $r['totalPrice']]);

        if ($paidNow) {
            DB::transaction(function () use ($r, $bookings) {
                $b = DB::table('tbl_booking')->where('bookingId', $r['bookingId'])->lockForUpdate()->first();
                DB::table('tbl_checkout')->where('bookingId', $r['bookingId'])->update(['paymentStatus' => 'y', 'paymentDate' => now()]);
                $bookings->confirmLocked($b);
            });
            AuditLog::record('booking.paid', 'booking', $r['bookingId'], 'Thu tiền tại quầy khi đặt hộ, đơn ' . $r['bookingCode'], ['paymentStatus' => 'n'], ['paymentStatus' => 'y', 'bookingStatus' => 'y']);
            $this->notifyCustomer($r['bookingId'], 'paid');
        } else {
            $this->notifyCustomer($r['bookingId'], 'created');
        }
        AdminBadges::forget();

        return redirect()->route('admin.booking-detail', ['id' => $r['bookingId']])->with('success', 'Đã tạo đơn ' . $r['bookingCode'] . '.');
    }

    /* ------------------------------------------------------------------
     * Thao tác trên đơn (AJAX). Từ chối nghiệp vụ: HTTP 200 + success=false để JS hiện đúng thông báo.
     * ------------------------------------------------------------------ */

    public function confirmBooking(Request $request, BookingService $bookings)
    {
        if ($err = $this->idError($request)) {
            return $err;
        }
        $id = (int) $request->input('bookingId');

        $result = DB::transaction(function () use ($id, $bookings) {
            $booking = DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first();
            $checkout = DB::table('tbl_checkout')->where('bookingId', $id)->lockForUpdate()->first();
            if (!$booking || !$checkout) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn hoặc đơn chưa có thông tin thanh toán.'];
            }
            if ($booking->bookingStatus === 'y') {
                return ['ok' => true, 'message' => 'Đơn đã được xác nhận trước đó.', 'noop' => true];
            }
            if ($booking->bookingStatus !== 'n') {
                return ['ok' => false, 'message' => 'Chỉ xác nhận được đơn đang chờ xử lý.'];
            }
            if ($booking->holdExpiresAt !== null && Carbon::parse($booking->holdExpiresAt)->isPast() && $checkout->paymentStatus !== 'y') {
                $bookings->cancelLocked($booking, 'expired');

                return ['ok' => false, 'message' => 'Đơn đã hết thời gian giữ chỗ và đã được hủy, chỗ đã trả lại.'];
            }
            $bookings->confirmLocked($booking);

            return ['ok' => true, 'message' => 'Đã xác nhận đơn ' . $booking->bookingCode . '.', 'code' => $booking->bookingCode];
        });

        if ($result['ok'] && empty($result['noop'])) {
            AuditLog::record('booking.confirm', 'booking', $id, 'Xác nhận đơn ' . $result['code'], ['bookingStatus' => 'n'], ['bookingStatus' => 'y']);
            $this->notifyCustomer($id, 'confirmed');
        }

        return $this->reply($result, $request);
    }

    public function finishBooking(Request $request)
    {
        if ($err = $this->idError($request)) {
            return $err;
        }
        $id = (int) $request->input('bookingId');
        $today = now()->toDateString();

        $result = DB::transaction(function () use ($id, $today) {
            $row = DB::table('tbl_booking')
                ->join('tbl_tours', 'tbl_tours.tourId', '=', 'tbl_booking.tourId')
                ->join('tbl_checkout', 'tbl_checkout.bookingId', '=', 'tbl_booking.bookingId')
                ->where('tbl_booking.bookingId', $id)
                ->select('tbl_booking.bookingCode', 'tbl_booking.bookingStatus', 'tbl_booking.departureDate', 'tbl_tours.startDate', 'tbl_tours.endDate', 'tbl_tours.durationDays', 'tbl_checkout.paymentStatus')
                ->lockForUpdate()->first();
            if (!$row) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($row->bookingStatus === 'f') {
                return ['ok' => true, 'message' => 'Đơn đã hoàn tất trước đó.', 'noop' => true];
            }
            if ($row->bookingStatus !== 'y') {
                return ['ok' => false, 'message' => 'Chỉ hoàn tất được đơn đã xác nhận.'];
            }
            if (BookingService::tripEndDate($row) >= $today) {
                return ['ok' => false, 'message' => 'Chuyến đi chưa kết thúc (về ngày ' . Carbon::parse(BookingService::tripEndDate($row))->format('d/m/Y') . ') nên chưa hoàn tất được.'];
            }
            if ($row->paymentStatus !== 'y') {
                return ['ok' => false, 'message' => 'Đơn chưa được xác nhận thanh toán.'];
            }
            DB::table('tbl_booking')->where('bookingId', $id)->update(['bookingStatus' => 'f']);

            return ['ok' => true, 'message' => 'Đã hoàn tất đơn ' . $row->bookingCode . '.', 'code' => $row->bookingCode];
        });

        if ($result['ok'] && empty($result['noop'])) {
            AuditLog::record('booking.finish', 'booking', $id, 'Hoàn tất đơn ' . $result['code'], ['bookingStatus' => 'y'], ['bookingStatus' => 'f']);
        }

        return $this->reply($result, $request);
    }

    /**
     * Hủy đơn. Đơn đã thanh toán: tạo yêu cầu hoàn tiền (mặc định theo bậc §7.5, admin chỉnh được %).
     * Body: bookingId, reason?, refundPercent? (0–100)
     */
    public function cancelBooking(Request $request, BookingService $bookings, RefundService $refunds)
    {
        $v = Validator::make($request->all(), [
            'bookingId' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:255',
            'refundPercent' => 'nullable|integer|min:0|max:100',
        ], ['refundPercent.*' => 'Phần trăm hoàn tiền phải từ 0 đến 100.']);
        if ($v->fails()) {
            return $this->refuse($v->errors()->first());
        }
        $id = (int) $request->input('bookingId');
        $percent = $request->filled('refundPercent') ? (int) $request->input('refundPercent') : null;
        $reason = trim((string) $request->input('reason')) ?: null;
        $adminId = (int) session('adminId');

        $result = DB::transaction(function () use ($id, $bookings, $refunds, $percent, $reason, $adminId) {
            $booking = DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first();
            if (!$booking) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($booking->bookingStatus === 'c') {
                return ['ok' => true, 'message' => 'Đơn đã hủy trước đó.', 'noop' => true];
            }
            if (!in_array($booking->bookingStatus, ['n', 'y'], true)) {
                return ['ok' => false, 'message' => 'Chỉ hủy được đơn đang chờ hoặc đã xác nhận.'];
            }
            $paid = DB::table('tbl_checkout')->where('bookingId', $id)->value('paymentStatus') === 'y';
            $bookings->cancelLocked($booking, 'admin', !$paid);
            $refund = $paid ? $refunds->requestLocked($booking, 'admin', $adminId, $percent, $reason) : null;

            $msg = 'Đã hủy đơn ' . $booking->bookingCode . ' và trả lại chỗ.';
            if ($refund) {
                $msg .= $refund->amount > 0
                    ? ' Đã tạo yêu cầu hoàn ' . number_format((float) $refund->amount, 0, ',', '.') . ' đ (' . $refund->percent . '%), xử lý ở mục Hoàn tiền.'
                    : ' Đơn không được hoàn tiền (0%).';
            }

            return ['ok' => true, 'message' => $msg, 'booking' => $booking, 'refund' => $refund];
        });

        if ($result['ok'] && empty($result['noop'])) {
            $b = $result['booking'];
            AuditLog::record('booking.cancel', 'booking', $id, 'Hủy đơn ' . $b->bookingCode . ($reason ? ' – ' . $reason : ''),
                ['bookingStatus' => $b->bookingStatus], ['bookingStatus' => 'c', 'cancelReason' => 'admin', 'reason' => $reason]);
            if ($result['refund']) {
                $r = $result['refund'];
                AuditLog::record('refund.create', 'refund', $r->refundId, 'Yêu cầu hoàn ' . number_format((float) $r->amount, 0, ',', '.') . ' đ cho đơn ' . $b->bookingCode,
                    null, ['percent' => $r->percent, 'amount' => (int) $r->amount, 'status' => $r->status]);
            }
            $this->notifyCustomer($id, 'cancelled');
        }

        return $this->reply($result, $request);
    }

    /** Xem trước số tiền hoàn nếu hủy đơn (dùng cho hộp thoại Hủy). */
    public function cancelPreview(Request $request)
    {
        $id = (int) $request->query('bookingId');
        $b = $this->booking->getInvoiceBooking($id);
        if (!$b) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn.'], 404);
        }
        $days = CancelPolicy::daysUntil($b->tripStart);
        $paid = $b->paymentStatus === 'y';

        return response()->json([
            'success' => true,
            'code' => $b->bookingCode,
            'paid' => $paid,
            'amount' => (int) $b->amount,
            'days' => $days,
            'percent' => $paid ? CancelPolicy::refundPercent($days) : 0,
        ]);
    }

    public function receiviedMoney(Request $request)
    {
        return $this->confirmPayment($request);
    }

    public function confirmPayment(Request $request)
    {
        if ($err = $this->idError($request)) {
            return $err;
        }
        $id = (int) $request->input('bookingId');

        $result = DB::transaction(function () use ($id) {
            $booking = DB::table('tbl_booking')->where('bookingId', $id)->lockForUpdate()->first();
            $checkout = DB::table('tbl_checkout')->where('bookingId', $id)->lockForUpdate()->first();
            if (!$booking || !$checkout) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($booking->bookingStatus === 'c') {
                return ['ok' => false, 'message' => 'Đơn đã hủy, không thể xác nhận thanh toán.'];
            }
            if ($checkout->paymentStatus !== 'n') {
                return ['ok' => true, 'message' => 'Đơn này đã được xác nhận thanh toán trước đó.', 'noop' => true];
            }
            // Khách đã trả tiền thì đơn không còn bị tự hủy vì quá hạn giữ chỗ
            DB::table('tbl_booking')->where('bookingId', $id)->update(['holdExpiresAt' => null]);
            DB::table('tbl_checkout')->where('bookingId', $id)->update(['paymentStatus' => 'y', 'paymentDate' => now()]);

            return ['ok' => true, 'message' => 'Đã ghi nhận thanh toán cho đơn ' . $booking->bookingCode . '.', 'code' => $booking->bookingCode];
        });

        if ($result['ok'] && empty($result['noop'])) {
            AuditLog::record('booking.paid', 'booking', $id, 'Xác nhận đã thu tiền đơn ' . $result['code'], ['paymentStatus' => 'n'], ['paymentStatus' => 'y']);
            $this->notifyCustomer($id, 'paid');
        }

        return $this->reply($result, $request);
    }

    public function showDetail($bookingId)
    {
        $b = $bookingId ? $this->booking->getInvoiceBooking($bookingId) : null;
        if (!$b) {
            abort(404);
        }
        $b = $this->decorate(collect([$b]))->first();
        $title = 'Đơn ' . $b->bookingCode;
        $refund = Schema::hasTable('tbl_refunds') ? DB::table('tbl_refunds')->where('bookingId', $b->bookingId)->first() : null;
        $history = Schema::hasTable('tbl_audit_log')
            ? DB::table('tbl_audit_log')->where(function ($q) use ($b, $refund) {
                $q->where(fn($w) => $w->where('entity', 'booking')->where('entityId', (string) $b->bookingId));
                if ($refund) {
                    $q->orWhere(fn($w) => $w->where('entity', 'refund')->where('entityId', (string) $refund->refundId));
                }
            })->orderByDesc('createdAt')->limit(30)->get()
            : collect();
        $invoiceNo = $this->invoiceNumber($b, in_array($b->bookingStatus, ['y', 'f'], true)); // cấp số khi đơn đã xác nhận
        $canCancelPreview = in_array($b->bookingStatus, ['n', 'y'], true) ? [
            'days' => CancelPolicy::daysUntil($b->tripStart),
            'percent' => $b->paymentStatus === 'y' ? CancelPolicy::refundPercent(CancelPolicy::daysUntil($b->tripStart)) : 0,
        ] : null;

        $payLogs = Schema::hasTable('tbl_payment_logs')
            ? DB::table('tbl_payment_logs')->where('bookingId', $b->bookingId)->orderByDesc('logId')->limit(20)->get() : collect();

        return view('admin.booking-detail', compact('title', 'b', 'refund', 'history', 'invoiceNo', 'canCancelPreview', 'payLogs'));
    }

    /**
     * Gửi hóa đơn qua email (D-10). Trình duyệt dựng PDF bằng pdfmake (có font tiếng Việt) và gửi lên dạng base64;
     * server kiểm tra đúng là PDF, đính kèm và đưa thư vào hàng đợi. Không có PDF thì gửi bản HTML.
     */
    public function sendPdf(Request $request)
    {
        $v = Validator::make($request->all(), [
            'bookingId' => 'required|integer|min:1',
            'pdf' => 'nullable|string|max:4000000',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()]);
        }
        $id = (int) $request->input('bookingId');
        $invoice_booking = $this->booking->getInvoiceBooking($id);
        if (!$invoice_booking) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn.']);
        }
        if ($invoice_booking->bookingStatus === 'c') {
            return response()->json(['success' => false, 'message' => 'Đơn đã hủy, không gửi hóa đơn.']);
        }
        $invoice_booking->transactionId ??= 'Thanh toán tại công ty Travela';
        $invoice_booking->invoiceNo = $this->invoiceNumber($invoice_booking, true);

        $pdf = null;
        if ($request->filled('pdf')) {
            $raw = preg_replace('#^data:application/pdf;[^,]*,#', '', (string) $request->input('pdf'));
            $bytes = base64_decode($raw, true);
            if ($bytes === false || !str_starts_with($bytes, '%PDF-') || strlen($bytes) > 2_500_000) {
                return response()->json(['success' => false, 'message' => 'Tệp PDF không hợp lệ.']);
            }
            $pdf = base64_encode($bytes);
        }

        try {
            $name = str_replace(["\r", "\n"], ' ', (string) $invoice_booking->fullName);
            Mail::to($invoice_booking->email)->queue(new TemplateMail(
                'admin.emails.invoice',
                ['invoice_booking' => $invoice_booking],
                'Hóa đơn ' . $invoice_booking->invoiceNo . ' – đơn ' . $invoice_booking->bookingCode . ' – ' . $name,
                $pdf,
                $pdf ? 'hoa-don-' . $invoice_booking->bookingCode . '.pdf' : null,
            ));
        } catch (\Throwable $e) {
            Log::error('Gửi hóa đơn thất bại: ' . $e->getMessage(), ['bookingId' => $id]);

            return response()->json(['success' => false, 'message' => 'Không thể gửi email lúc này. Vui lòng thử lại sau.'], 500);
        }
        AuditLog::record('booking.invoice', 'booking', $id, 'Gửi hóa đơn ' . $invoice_booking->invoiceNo . ($pdf ? ' (PDF)' : '') . ' tới email khách');

        return response()->json(['success' => true, 'message' => 'Đã đưa hóa đơn ' . ($pdf ? 'PDF ' : '') . 'vào hàng gửi tới ' . $invoice_booking->email . '.']);
    }

    /* ------------------------------------------------------------------ */

    /** Số hóa đơn HD-năm-số (tbl_invoice), cấp một lần cho mỗi đơn. */
    private function invoiceNumber(object $b, bool $create): ?string
    {
        if (!Schema::hasTable('tbl_invoice')) {
            return null;
        }
        $row = DB::table('tbl_invoice')->where('bookingId', $b->bookingId)->first();
        if (!$row && $create) {
            $id = DB::table('tbl_invoice')->insertGetId([
                'bookingId' => $b->bookingId,
                'amount' => (int) $b->totalPrice,
                'dateIssued' => now()->toDateString(),
                'details' => mb_substr('Hóa đơn đơn ' . $b->bookingCode, 0, 255),
            ]);
            $row = DB::table('tbl_invoice')->where('invoiceId', $id)->first();
        }

        return $row ? 'HD-' . date('Y', strtotime($row->dateIssued)) . '-' . str_pad((string) $row->invoiceId, 5, '0', STR_PAD_LEFT) : null;
    }

    /** Email báo khách khi đơn đổi trạng thái (qua hàng đợi, lỗi không làm hỏng thao tác). */
    private function notifyCustomer(int $bookingId, string $event): void
    {
        app(\App\Services\BookingNotifier::class)->send($bookingId, $event);
    }

    private function decorate($rows)
    {
        $today = now()->toDateString();

        return collect($rows)->map(function ($b) use ($today) {
            $b->ended = $b->tripEnd < $today;
            $b->started = $b->tripStart <= $today;
            $b->canFinish = $b->bookingStatus === 'y' && $b->ended && $b->paymentStatus === 'y';
            $b->holdLeft = ($b->bookingStatus === 'n' && $b->holdExpiresAt) ? Carbon::parse($b->holdExpiresAt) : null;

            return $b;
        });
    }

    private function counts($rows): array
    {
        $rows = collect($rows);

        return [
            'all' => $rows->count(),
            'n' => $rows->where('bookingStatus', 'n')->count(),
            'y' => $rows->where('bookingStatus', 'y')->count(),
            'f' => $rows->where('bookingStatus', 'f')->count(),
            'c' => $rows->where('bookingStatus', 'c')->count(),
            'unpaid' => $rows->filter(fn($b) => $b->paymentStatus === 'n' && $b->bookingStatus !== 'c')->count(),
            'refund' => $rows->where('paymentStatus', 'refund_pending')->count(),
        ];
    }

    private function idError(Request $request)
    {
        $v = Validator::make($request->all(), ['bookingId' => 'required|integer|min:1']);

        return $v->fails() ? $this->refuse($v->errors()->first()) : null;
    }

    private function refuse(string $message)
    {
        return response()->json(['success' => false, 'message' => $message]);
    }

    /** Trả kết quả kèm bảng đơn mới (trang danh sách) hoặc chỉ thông báo (trang chi tiết: ?context=detail). */
    private function reply(array $result, Request $request)
    {
        if (!$result['ok']) {
            return $this->refuse($result['message']);
        }
        AdminBadges::forget();
        if ($request->input('context') === 'detail') {
            return response()->json(['success' => true, 'message' => $result['message']]);
        }
        $list_booking = $this->decorate($this->booking->getBooking());

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'counts' => $this->counts($list_booking),
            'data' => view('admin.partials.list-booking', compact('list_booking'))->render(),
        ]);
    }
}
