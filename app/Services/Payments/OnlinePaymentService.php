<?php

namespace App\Services\Payments;

use App\Services\BookingNotifier;
use App\Services\BookingService;
use App\Services\DepartureService;
use App\Services\RefundService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thanh toán trực tuyến dùng chung cho MoMo, ZaloPay và QR chuyển khoản (đặc tả §7.1, §7.6; N-02).
 *
 *   start()          tạo giao dịch ở cổng khách chọn (mỗi lần thử một mã riêng, trừ QR) → link chuyển sang cổng hoặc dữ liệu QR.
 *   handleNotify()   NGUỒN XÁC NHẬN CHÍNH (IPN MoMo, callback ZaloPay, webhook SePay): kiểm chữ ký → tìm đơn → so số tiền → khóa dòng → ghi nhận.
 *                    Gửi lặp bao nhiêu lần cũng chỉ ghi nhận một lần.
 *   handleReturn()   trang khách quay về: chỉ hiển thị; nếu cổng báo thành công mà đơn còn chờ thì HỎI LẠI cổng (query), không tin trình duyệt.
 *   queryAndSettle() đối soát chủ động: khách bấm "Kiểm tra lại", admin bấm đối soát, lệnh payments:reconcile mỗi phút.
 *   confirmManual()  admin xác nhận đã nhận tiền chuyển khoản (QR khi chưa nối SePay).
 * Mọi payload create / return / notify / query / manual ghi vào tbl_payment_logs.
 */
class OnlinePaymentService
{
    public const PAID = ['y', 'refund_pending', 'refunded'];

    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(private BookingService $bookings, private DepartureService $departures, private RefundService $refunds, ?array $gateways = null)
    {
        $this->gateways = $gateways ?? ['momo' => new MomoGateway(), 'zalopay' => new ZaloPayGateway(), 'qr' => new QrTransferGateway()];
    }

    public function gateway(string $code): ?PaymentGateway
    {
        return $this->gateways[$code] ?? null;
    }

    /** Cổng đang bật, theo thứ tự cấu hình. @return array<string, PaymentGateway> */
    public function enabledGateways(): array
    {
        $out = [];
        foreach ((array) config('payments.methods', ['momo', 'zalopay', 'qr']) as $code) {
            if (($g = $this->gateway($code)) && $g->enabled()) {
                $out[$code] = $g;
            }
        }

        return $out;
    }

    public static function methods(): array
    {
        return array_keys(app(self::class)->enabledGateways());
    }

    public static function isOnline(?string $method): bool
    {
        return in_array($method, ['momo', 'zalopay', 'qr'], true);
    }

    public static function holdMinutes(string $method): int
    {
        return app(self::class)->gateway($method)?->holdMinutes() ?? 15;
    }

    public static function label(?string $method): string
    {
        return ['momo' => 'Ví MoMo', 'zalopay' => 'ZaloPay', 'qr' => 'QR chuyển khoản', 'vnpay' => 'VNPay', 'office-payment' => 'Tại văn phòng'][$method ?? ''] ?? (string) $method;
    }

    /** Địa chỉ tuyệt đối cổng gọi về; PAYMENT_PUBLIC_URL thay cho APP_URL khi chạy sau đường hầm. */
    public static function publicRoute(string $name, array $params = []): string
    {
        $path = route($name, $params, false);
        $base = rtrim((string) (config('payments.public_url') ?: config('app.url')), '/');

        return $base . $path;
    }

    /* ------------------------------------------------------------------ Tạo giao dịch */

    /** @return array{ok:bool, url?:?string, qr?:?array, message?:string} */
    public function start(int $bookingId, string $method, ?string $ip): array
    {
        $g = $this->gateway($method);
        if (!$g || !$g->enabled()) {
            return ['ok' => false, 'message' => 'Phương thức thanh toán này chưa được bật.'];
        }

        // Bước 1 (khóa dòng, ngắn): kiểm đơn, cấp mã giao dịch mới. Gọi cổng nằm NGOÀI transaction để không giữ khóa khi mạng chậm.
        $prep = DB::transaction(function () use ($bookingId, $method) {
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->lockForUpdate()->first();
            if (!$b || !$c) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($c->paymentStatus !== 'n') {
                return ['ok' => false, 'message' => 'Đơn này đã được thanh toán.'];
            }
            if (!in_array($b->bookingStatus, ['n', 'y'], true)) {
                return ['ok' => false, 'message' => 'Đơn đã hủy hoặc đã hoàn tất, không thể thanh toán.'];
            }
            $hold = $b->holdExpiresAt ? Carbon::parse($b->holdExpiresAt) : null;
            if ($b->bookingStatus === 'n' && $hold && $hold->lte(now()->addMinute())) {
                return ['ok' => false, 'message' => 'Đơn đã hết thời gian giữ chỗ.'];
            }
            $attempt = (int) ($c->attempts ?? 0) + 1;
            DB::table('tbl_checkout')->where('bookingId', $bookingId)->update(['attempts' => $attempt, 'paymentMethod' => $method, 'provider' => $method]);

            // trang thanh toán của cổng không mở lâu hơn hạn giữ chỗ; đơn đã xác nhận (trả sau) thì cho 15 phút
            return ['ok' => true, 'b' => $b, 'c' => $c, 'attempt' => $attempt,
                'expireAt' => $b->bookingStatus === 'n' && $hold ? $hold : now()->addMinutes(15)];
        });
        if (!$prep['ok']) {
            return $prep;
        }
        $b = $prep['b'];
        $txnRef = $prep['attempt'] === 1 ? $b->bookingCode : $b->bookingCode . '-' . $prep['attempt'];   // mỗi lần thử một mã riêng (cổng không nhận trùng)
        $amount = (int) round((float) $prep['c']->amount);
        try {
            $r = $g->create([
                'txnRef' => $txnRef, 'amount' => $amount, 'info' => 'Thanh toan don dat tour ' . $b->bookingCode,
                'returnUrl' => self::publicRoute('payment.return', ['method' => $method]),
                'notifyUrl' => self::publicRoute('payment.notify', ['method' => $method]),
                'expireAt' => $prep['expireAt'], 'bookingCode' => $b->bookingCode, 'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $this->log($bookingId, $method, 'create', $txnRef, 'http_error', null, ['error' => $e->getMessage()], $ip);

            return ['ok' => false, 'message' => 'Không kết nối được ' . $g->label() . '. Vui lòng thử lại hoặc chọn cách thanh toán khác.'];
        }
        $txnRef = $r['txnRef'] ?? $txnRef;
        $this->log($bookingId, $method, 'create', $txnRef, $r['ok'] ? 'url' : 'rejected', null, $r['raw'] ?? [], $ip);
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message'] ?? 'Cổng thanh toán từ chối tạo giao dịch.'];
        }
        DB::table('tbl_checkout')->where('bookingId', $bookingId)->update([
            'providerTxnRef' => $txnRef, 'providerCreateDate' => now(config('payments.timezone'))->format('YmdHis'), 'responseCode' => null,
        ]);

        return ['ok' => true, 'url' => $r['url'] ?? null, 'qr' => $r['qr'] ?? null];
    }

    /* ------------------------------------------------------------------ Thông báo máy chủ → máy chủ */

    public function handleNotify(string $method, Request $request): Response
    {
        $g = $this->gateway($method) ?? abort(404);
        $ip = $request->ip();
        try {
            $n = $g->parseNotify($request);
        } catch (\Throwable $e) {
            report($e);

            return $g->notifyReply('error');
        }
        if (!$n['valid']) {
            $this->log(null, $method, 'notify', $n['txnRef'], 'bad_signature', false, $n['payload'], $ip);

            return $g->notifyReply('invalid_signature');
        }
        $checkout = $n['txnRef'] ? $this->findCheckout($n['txnRef']) : null;
        if (!$checkout) {
            $this->log(null, $method, 'notify', $n['txnRef'], $method === 'qr' ? 'unmatched' : 'not_found', true, $n['payload'], $ip);

            return $g->notifyReply($method === 'qr' ? 'ignored' : 'not_found');
        }
        $bookingId = (int) $checkout->bookingId;
        if (!$n['success']) {
            // MoMo báo thất bại / khách hủy; SePay báo tiền ra: ghi mã, đơn vẫn chờ để khách thử lại trong hạn
            if ($method !== 'qr' && $checkout->paymentStatus === 'n') {
                DB::table('tbl_checkout')->where('bookingId', $bookingId)->update(['responseCode' => mb_substr($n['code'], 0, 4)]);
            }
            $this->log($bookingId, $method, 'notify', $n['txnRef'], 'failed:' . $n['code'], true, $n['payload'], $ip);

            return $g->notifyReply('ok');
        }
        if ($n['amount'] !== (int) round((float) $checkout->amount)) {
            $this->log($bookingId, $method, 'notify', $n['txnRef'], 'bad_amount', true, $n['payload'], $ip);

            return $g->notifyReply('bad_amount');
        }
        try {
            $r = $this->settle($bookingId, $method, (string) $n['txnRef'], $n['providerTxnNo'], $n['bank'], 'notify');
        } catch (\Throwable $e) {
            report($e);
            $this->log($bookingId, $method, 'notify', $n['txnRef'], 'error', true, $n['payload'], $ip);

            return $g->notifyReply('error');
        }
        $this->log($bookingId, $method, 'notify', $n['txnRef'], $r['duplicate'] ? 'duplicate' : 'paid', true, $n['payload'], $ip);

        return $g->notifyReply($r['duplicate'] ? 'duplicate' : 'ok');
    }

    /* ------------------------------------------------------------------ Trang khách quay về */

    /** @return array{valid:bool, success:bool, paid:bool, bookingId:?int, checkoutId:?int, code:string, message:string, method:string, amount:?int} */
    public function handleReturn(string $method, array $query, ?string $ip): array
    {
        $g = $this->gateway($method) ?? abort(404);
        $r = $g->parseReturn($query);
        $checkout = $r['valid'] && $r['txnRef'] ? $this->findCheckout($r['txnRef']) : null;
        $this->log($checkout->bookingId ?? null, $method, 'return', $r['txnRef'], $r['code'], $r['valid'], $query, $ip);

        // Cổng báo thành công nhưng thông báo máy chủ chưa tới (máy cá nhân không có đường hầm / chậm): hỏi lại cổng
        if ($r['success'] && $checkout && $checkout->paymentStatus === 'n') {
            $this->queryAndSettle((int) $checkout->bookingId, $ip);
            $checkout = $this->findCheckout((string) $r['txnRef']);
        }

        return [
            'valid' => $r['valid'], 'success' => $r['success'], 'method' => $method,
            'paid' => $checkout ? in_array($checkout->paymentStatus, self::PAID, true) : false,
            'bookingId' => $checkout ? (int) $checkout->bookingId : null,
            'checkoutId' => $checkout ? (int) $checkout->checkoutId : null,
            'code' => $r['code'], 'message' => $g->describe($r['code']), 'amount' => $r['amount'],
        ];
    }

    /* ------------------------------------------------------------------ Hỏi lại cổng */

    /** @return array{ok:bool, status:?string, message:string} */
    public function queryAndSettle(int $bookingId, ?string $ip = null): array
    {
        $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->first();
        $g = $c ? $this->gateway((string) $c->provider) : null;
        if (!$c || !$g || !$c->providerTxnRef) {
            return ['ok' => false, 'status' => null, 'message' => 'Đơn chưa tạo giao dịch thanh toán trực tuyến.'];
        }
        if (in_array($c->paymentStatus, self::PAID, true)) {
            return ['ok' => true, 'status' => 'paid', 'message' => 'Đơn đã được ghi nhận thanh toán.'];
        }
        if (!$g->enabled()) {
            return ['ok' => false, 'status' => null, 'message' => $g->label() . ' đang tắt.'];
        }
        $q = $g->query((string) $c->providerTxnRef, $c->providerCreateDate);
        $amountOk = $q['amount'] === null || $q['amount'] === (int) round((float) $c->amount);
        $this->log($bookingId, $g->code(), 'query', $c->providerTxnRef, ($q['status'] ?? $q['code']) . ($amountOk ? '' : '/amount'), $q['ok'] ? true : null, $q['raw'], $ip);
        if (!$q['ok']) {
            return ['ok' => false, 'status' => null, 'message' => $q['message']];
        }
        if ($q['status'] === 'paid' && $amountOk) {
            $r = $this->settle($bookingId, $g->code(), (string) $c->providerTxnRef, $q['providerTxnNo'], null, 'query');

            return ['ok' => true, 'status' => 'paid', 'message' => $r['duplicate'] ? 'Đơn đã được ghi nhận thanh toán trước đó.' : $g->label() . ' xác nhận đã thanh toán, đơn đã được cập nhật.'];
        }
        if ($q['status'] === 'paid') {
            return ['ok' => false, 'status' => 'paid', 'message' => 'Số tiền cổng báo không khớp đơn, cần admin kiểm tra.'];
        }

        return ['ok' => true, 'status' => $q['status'], 'message' => $q['message']];
    }

    /* ------------------------------------------------------------------ Admin xác nhận tay */

    /** Admin đã đối chiếu sao kê: ghi nhận đơn QR / chuyển khoản là đã thanh toán. */
    public function confirmManual(int $bookingId, string $reference, ?string $ip): array
    {
        $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->first();
        if (!$c) {
            return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
        }
        if (in_array($c->paymentStatus, self::PAID, true)) {
            return ['ok' => false, 'message' => 'Đơn đã được ghi nhận thanh toán.'];
        }
        $r = $this->settle($bookingId, (string) ($c->provider ?: 'qr'), (string) ($c->providerTxnRef ?: $reference), $reference ?: null, null, 'manual');
        $this->log($bookingId, (string) ($c->provider ?: 'qr'), 'manual', $c->providerTxnRef, 'paid', null, ['reference' => $reference, 'adminId' => session('adminId')], $ip);

        return ['ok' => true, 'message' => $r['duplicate'] ? 'Đơn đã được ghi nhận trước đó.' : 'Đã ghi nhận thanh toán, đơn được xác nhận.'];
    }

    /* ------------------------------------------------------------------ Ghi nhận kết quả */

    /**
     * Áp kết quả "đã trả tiền" lên đơn (trong transaction, có khóa dòng). Dùng chung cho thông báo máy chủ, query và xác nhận tay.
     * @return array{duplicate:bool}
     */
    private function settle(int $bookingId, string $method, string $txnRef, ?string $providerTxnNo, ?string $bank, string $source): array
    {
        $out = DB::transaction(function () use ($bookingId, $method, $txnRef, $providerTxnNo, $bank) {
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->lockForUpdate()->first();
            if (in_array($c->paymentStatus, self::PAID, true)) {
                return ['duplicate' => true];
            }
            DB::table('tbl_checkout')->where('bookingId', $bookingId)->update([
                'paymentStatus' => 'y', 'paymentDate' => now(), 'paidAt' => now(),
                'paymentMethod' => $method, 'provider' => $method, 'providerTxnRef' => $txnRef,
                'providerTxnNo' => $providerTxnNo, 'transactionId' => $providerTxnNo ?? $txnRef,
                'bankCode' => $bank ? mb_substr($bank, 0, 20) : null, 'responseCode' => '00',
            ]);
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['holdExpiresAt' => null]);

            if ($b->bookingStatus === 'n') {
                $this->bookings->confirmLocked($b);   // §6.4: thanh toán thành công -> đơn xác nhận, chỗ giữ thành chỗ bán

                return ['duplicate' => false, 'event' => 'confirmed', 'booking' => $b];
            }
            if ($b->bookingStatus === 'c') {
                // Tiền về SAU khi đơn đã hủy / hết hạn: còn chỗ thì xác nhận lại, hết chỗ thì hoàn tiền 100%
                $people = (int) $b->numAdults + (int) $b->numChildren;
                if ($b->availabilityId && $this->departures->bookDirect((int) $b->availabilityId, $people)) {
                    DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['bookingStatus' => 'y', 'cancelReason' => null]);
                    $this->departures->syncTour((int) $b->tourId);

                    return ['duplicate' => false, 'event' => 'revived', 'booking' => $b];
                }
                $this->refunds->requestLocked($b, 'system', null, 100, 'Tiền thanh toán về sau khi đơn đã hết hạn và không còn chỗ');

                return ['duplicate' => false, 'event' => 'late_refund', 'booking' => $b];
            }

            return ['duplicate' => false, 'event' => 'paid', 'booking' => $b];
        });

        if (!$out['duplicate']) {
            $b = $out['booking'];
            $label = self::label($method) . ': ' . ['confirmed' => 'đã thanh toán, đơn chuyển sang Đã xác nhận', 'revived' => 'tiền về sau khi đơn hết hạn, còn chỗ nên xác nhận lại',
                'late_refund' => 'tiền về sau khi đơn hết hạn và hết chỗ, tạo yêu cầu hoàn 100%', 'paid' => 'đã thanh toán'][$out['event']];
            AuditLog::record('payment.online', 'booking', $bookingId, $label . ' (' . $source . ', ' . $b->bookingCode . ')',
                ['bookingStatus' => $b->bookingStatus, 'paymentStatus' => 'n'], ['paymentStatus' => $out['event'] === 'late_refund' ? 'refund_pending' : 'y', 'method' => $method, 'txnNo' => $providerTxnNo]);
            AdminBadges::forget();
            if ($out['event'] !== 'late_refund') {
                app(BookingNotifier::class)->send($bookingId, 'paid');
            }
        }

        return ['duplicate' => $out['duplicate']];
    }

    private function findCheckout(string $txnRef): ?object
    {
        if ($txnRef === '') {
            return null;
        }

        return DB::table('tbl_checkout')->where('providerTxnRef', $txnRef)->first()
            ?? DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')
                ->where('b.bookingCode', $txnRef)->select('c.*')->first();
    }

    private function log(?int $bookingId, string $provider, string $kind, ?string $txnRef, ?string $result, ?bool $valid, array $payload, ?string $ip): void
    {
        try {
            array_walk_recursive($payload, function (&$v, $k) {
                if (in_array($k, ['signature', 'mac', 'checksum', 'accessKey'], true)) {
                    $v = '***';
                }
            });
            DB::table('tbl_payment_logs')->insert([
                'bookingId' => $bookingId, 'provider' => mb_substr($provider, 0, 12), 'kind' => mb_substr($kind, 0, 12),
                'txnRef' => $txnRef ? mb_substr($txnRef, 0, 60) : null, 'result' => $result ? mb_substr($result, 0, 40) : null, 'validSignature' => $valid,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'ip' => $ip, 'createdAt' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
