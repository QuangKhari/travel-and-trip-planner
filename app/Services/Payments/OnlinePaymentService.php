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
 * Thanh toán trực tuyến dùng chung cho VNPay, MoMo và QR chuyển khoản (đặc tả §7.1, §7.6; N-02).
 *
 *   start()          tạo giao dịch ở cổng khách chọn (mỗi lần thử một mã riêng, trừ QR) → link chuyển sang cổng hoặc dữ liệu QR.
 *   handleNotify()   NGUỒN XÁC NHẬN CHÍNH (IPN VNPay / MoMo, webhook SePay): kiểm chữ ký → tìm đơn → so số tiền → khóa dòng → ghi nhận.
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
        $this->gateways = $gateways ?? ['vnpay' => new VnpayGateway(), 'momo' => new MomoGateway(), 'qr' => new QrTransferGateway()];
    }

    public function gateway(string $code): ?PaymentGateway
    {
        return $this->gateways[$code] ?? null;
    }

    /** Cổng đang bật, theo thứ tự cấu hình. @return array<string, PaymentGateway> */
    public function enabledGateways(): array
    {
        $out = [];
        foreach ((array) config('payments.methods', ['vnpay', 'momo', 'qr']) as $code) {
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
        return in_array($method, ['vnpay', 'momo', 'qr'], true);
    }

    public static function holdMinutes(string $method): int
    {
        return app(self::class)->gateway($method)?->holdMinutes() ?? 15;
    }

    public static function label(?string $method): string
    {
        return ['momo' => 'Ví MoMo', 'zalopay' => 'ZaloPay (cũ)', 'qr' => 'QR chuyển khoản', 'vnpay' => 'VNPay', 'office-payment' => 'Tại văn phòng'][$method ?? ''] ?? (string) $method;
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
        // Ghi lần thử TRƯỚC khi gọi cổng (UNIQUE provider + txnRef): khách trả ở trang của lần thử nào, webhook / hoàn tiền
        // đều tìm đúng lần đó kể cả khi nhật ký lỗi. createDate gửi sang cổng chính là giá trị lưu ở đây (VNPay cần khi hỏi / hoàn).
        $createdAt = now(config('payments.timezone', 'Asia/Ho_Chi_Minh'));
        $attemptId = $this->recordAttempt($bookingId, $method, $txnRef, $amount, $createdAt->format('YmdHis'));
        try {
            $r = $g->create([
                'createdAt' => $createdAt,
                'txnRef' => $txnRef, 'amount' => $amount, 'info' => 'Thanh toan don dat tour ' . $b->bookingCode,
                'returnUrl' => self::publicRoute('payment.return', ['method' => $method]),
                'notifyUrl' => self::publicRoute('payment.notify', ['method' => $method]),
                'expireAt' => $prep['expireAt'], 'bookingCode' => $b->bookingCode, 'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $this->log($bookingId, $method, 'create', $txnRef, 'http_error', null, ['error' => $e->getMessage()], $ip);
            // không biết cổng đã tạo giao dịch chưa: giữ lần thử ở trạng thái created để webhook (nếu có) vẫn tìm được đơn

            return ['ok' => false, 'message' => 'Không kết nối được ' . $g->label() . '. Vui lòng thử lại hoặc chọn cách thanh toán khác.'];
        }
        if (($r['txnRef'] ?? $txnRef) !== $txnRef) {   // QR: nội dung chuyển khoản luôn là mã đơn gốc
            $attemptId = $this->renameAttempt($attemptId, $bookingId, $method, (string) $r['txnRef'], $amount, $createdAt->format('YmdHis'));
            $txnRef = (string) $r['txnRef'];
        }
        $this->log($bookingId, $method, 'create', $txnRef, $r['ok'] ? 'url' : 'rejected', null, $r['raw'] ?? [], $ip);
        if (!$r['ok']) {
            if ($attemptId && self::attemptsReady()) {
                DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->where('status', 'created')->update(['status' => 'failed', 'updatedAt' => now()]);
            }

            return ['ok' => false, 'message' => $r['message'] ?? 'Cổng thanh toán từ chối tạo giao dịch.'];
        }
        DB::table('tbl_checkout')->where('bookingId', $bookingId)->update([
            'providerTxnRef' => $txnRef, 'providerCreateDate' => $createdAt->format('YmdHis'), 'responseCode' => null,
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
        $checkout = $n['txnRef'] ? $this->findCheckout($n['txnRef'], $method) : null;
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
        $this->log($bookingId, $method, 'notify', $n['txnRef'], !empty($r['doublePaid']) ? 'double_paid' : ($r['duplicate'] ? 'duplicate' : 'paid'), true, $n['payload'], $ip);

        return $g->notifyReply($r['duplicate'] ? 'duplicate' : 'ok');
    }

    /* ------------------------------------------------------------------ Trang khách quay về */

    /** @return array{valid:bool, success:bool, paid:bool, bookingId:?int, checkoutId:?int, code:string, message:string, method:string, amount:?int} */
    public function handleReturn(string $method, array $query, ?string $ip): array
    {
        $g = $this->gateway($method) ?? abort(404);
        $r = $g->parseReturn($query);
        $checkout = $r['valid'] && $r['txnRef'] ? $this->findCheckout($r['txnRef'], $method) : null;
        $this->log($checkout->bookingId ?? null, $method, 'return', $r['txnRef'], $r['code'], $r['valid'], $query, $ip);

        // Cổng báo thành công nhưng thông báo máy chủ chưa tới (máy cá nhân không có đường hầm / chậm): hỏi lại cổng
        if ($r['success'] && $checkout && $checkout->paymentStatus === 'n') {
            $this->queryAndSettle((int) $checkout->bookingId, $ip);
            $checkout = $this->findCheckout((string) $r['txnRef'], $method);
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
        if (!$c || !$c->providerTxnRef) {
            return ['ok' => false, 'status' => null, 'message' => 'Đơn chưa tạo giao dịch thanh toán trực tuyến.'];
        }
        if (in_array($c->paymentStatus, self::PAID, true)) {
            return ['ok' => true, 'status' => 'paid', 'message' => 'Đơn đã được ghi nhận thanh toán.'];
        }
        // Hỏi MỌI lần thử (mới nhất trước), không chỉ mã hiện hành: khách có thể đã trả ở trang thanh toán của lần thử cũ.
        $last = null;
        $asked = 0;
        foreach ($this->attempts($bookingId, $c) as $a) {
            $g = $this->gateway($a['provider']);
            if (!$g || !$g->enabled() || $asked >= 5) {
                continue;
            }
            $asked++;
            $q = $g->query($a['txnRef'], $a['createDate']);
            $amountOk = $q['amount'] === null || $q['amount'] === (int) round((float) $c->amount);
            $this->log($bookingId, $g->code(), 'query', $a['txnRef'], ($q['status'] ?? $q['code']) . ($amountOk ? '' : '/amount'), $q['ok'] ? true : null, $q['raw'], $ip);
            if ($q['ok'] && $q['status'] === 'paid' && $amountOk) {
                $r = $this->settle($bookingId, $g->code(), $a['txnRef'], $q['providerTxnNo'], null, 'query');

                return ['ok' => true, 'status' => 'paid', 'message' => $r['duplicate'] ? 'Đơn đã được ghi nhận thanh toán trước đó.' : $g->label() . ' xác nhận đã thanh toán, đơn đã được cập nhật.'];
            }
            if ($q['ok'] && $q['status'] === 'paid') {
                return ['ok' => false, 'status' => 'paid', 'message' => 'Số tiền cổng báo không khớp đơn, cần admin kiểm tra.'];
            }
            $last ??= $q;   // kết quả của lần thử mới nhất là thông điệp hiển thị khi chưa lần nào thành công
        }
        if (!$last) {
            $g = $this->gateway((string) $c->provider);

            return ['ok' => false, 'status' => null, 'message' => $g ? $g->label() . ' đang tắt.' : 'Đơn chưa tạo giao dịch thanh toán trực tuyến.'];
        }
        if (!$last['ok']) {
            return ['ok' => false, 'status' => null, 'message' => $last['message']];
        }

        return ['ok' => true, 'status' => $last['status'], 'message' => $last['message']];
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
        $out = DB::transaction(function () use ($bookingId, $method, $txnRef, $providerTxnNo, $bank, $source) {
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->lockForUpdate()->first();
            if (in_array($c->paymentStatus, self::PAID, true)) {
                // Đã ghi nhận bằng một khoản khác mà cổng lại báo thêm một khoản đã trả: khách bị thu hai lần.
                //  (a) trả ở trang của HAI lần thử khác nhau (hai tab) — txnRef khác mã đã ghi nhận;
                //  (b) chuyển khoản QR hai lần cùng nội dung — cùng txnRef nhưng mã giao dịch ngân hàng (SePay id) khác.
                // Không ghi nhận thêm; ghi khoản thu trùng + tự tạo yêu cầu hoàn chờ duyệt.
                $twoAttempts = $source !== 'manual' && $c->providerTxnRef && $c->providerTxnRef !== $txnRef
                    && $this->isAttemptOf($bookingId, $txnRef) && $this->isAttemptOf($bookingId, (string) $c->providerTxnRef);
                $twoTransfers = $source !== 'manual' && $method === 'qr' && $c->providerTxnRef === $txnRef
                    && $providerTxnNo && $c->providerTxnNo && $providerTxnNo !== $c->providerTxnNo;
                $double = ($twoAttempts || $twoTransfers)
                    && $this->markExcess($bookingId, $method, $twoTransfers ? $txnRef . '~' . $providerTxnNo : $txnRef, $providerTxnNo, (int) round((float) $c->amount));

                return ['duplicate' => true, 'doublePaid' => $double];
            }
            DB::table('tbl_checkout')->where('bookingId', $bookingId)->update([
                'paymentStatus' => 'y', 'paymentDate' => now(), 'paidAt' => now(),
                'paymentMethod' => $method, 'provider' => $method, 'providerTxnRef' => $txnRef,
                'providerTxnNo' => $providerTxnNo, 'transactionId' => $providerTxnNo ?? $txnRef,
                'bankCode' => $bank ? mb_substr($bank, 0, 20) : null, 'responseCode' => '00',
            ]);
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['holdExpiresAt' => null]);
            $this->markPaidAttempt($bookingId, $method, $txnRef, $providerTxnNo, (int) round((float) $c->amount));
            app(DoubleEntryLedger::class)->capture($bookingId, $method, (int) round((float) $c->amount), $method . ':' . $txnRef);

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
            AuditLog::record(
                'payment.online',
                'booking',
                $bookingId,
                $label . ' (' . $source . ', ' . $b->bookingCode . ')',
                ['bookingStatus' => $b->bookingStatus, 'paymentStatus' => 'n'],
                ['paymentStatus' => $out['event'] === 'late_refund' ? 'refund_pending' : 'y', 'method' => $method, 'txnNo' => $providerTxnNo],
            );
            AdminBadges::forget();
            if ($out['event'] !== 'late_refund') {
                app(BookingNotifier::class)->send($bookingId, 'paid');
            }
        }

        if (!empty($out['doublePaid'])) {
            AuditLog::record('payment.double', 'booking', $bookingId, self::label($method) . ': cổng báo lần thử ' . $txnRef . ' cũng đã thanh toán trong khi đơn đã ghi nhận bằng lần thử khác — cần hoàn khoản thứ hai (' . $source . ')');
            AdminBadges::forget();
        }

        return ['duplicate' => $out['duplicate'], 'doublePaid' => !empty($out['doublePaid'])];
    }

    /**
     * Tìm đơn theo mã giao dịch cổng gửi về: mã hiện hành của checkout → mã gốc (= bookingCode) → mã của MỘT LẦN THỬ CŨ.
     * Mỗi lần bấm thanh toán lại, providerTxnRef bị thay bằng mã mới (CODE-2, CODE-3...), nhưng trang thanh toán của lần thử cũ
     * vẫn còn mở được tới hết hạn giữ chỗ: khách trả ở đó thì webhook mang mã cũ. Mọi mã đã tạo được ghi ở tbl_payment_logs (kind=create),
     * nên tra ở đó để không bỏ sót khoản tiền đã thu.
     */
    private function findCheckout(string $txnRef, ?string $method = null): ?object
    {
        if ($txnRef === '') {
            return null;
        }

        $c = DB::table('tbl_checkout')->where('providerTxnRef', $txnRef)->first()
            ?? DB::table('tbl_checkout as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')
                ->where('b.bookingCode', $txnRef)->select('c.*')->first();
        if ($c) {
            return $c;
        }
        if (self::attemptsReady()) {
            $bid = DB::table('tbl_payment_attempts')->where('txnRef', $txnRef)->when($method, fn($q) => $q->where('provider', $method))->value('bookingId');
            if ($bid) {
                return DB::table('tbl_checkout')->where('bookingId', (int) $bid)->first();
            }
        }
        $bookingId = DB::table('tbl_payment_logs')->where('kind', 'create')->where('result', 'url')->where('txnRef', $txnRef)
            ->when($method, fn($q) => $q->where('provider', $method))->whereNotNull('bookingId')->orderByDesc('logId')->value('bookingId');

        return $bookingId ? DB::table('tbl_checkout')->where('bookingId', (int) $bookingId)->first() : null;
    }

    /**
     * Các lần thử thanh toán trực tuyến của một đơn (mới nhất trước), lấy từ nhật ký tạo giao dịch + mã hiện hành.
     * @return list<array{provider:string, txnRef:string, createDate:?string}>
     */
    public function attempts(int $bookingId, ?object $checkout = null): array
    {
        $c = $checkout ?? DB::table('tbl_checkout')->where('bookingId', $bookingId)->first();
        $out = [];
        if ($c && $c->providerTxnRef && $c->provider) {
            $out[$c->provider . '|' . $c->providerTxnRef] = ['provider' => (string) $c->provider, 'txnRef' => (string) $c->providerTxnRef, 'createDate' => $c->providerCreateDate];
        }
        if (self::attemptsReady()) {
            foreach (DB::table('tbl_payment_attempts')->where('bookingId', $bookingId)->whereIn('status', ['created', 'paid', 'excess'])->orderByDesc('attemptId')->limit(20)
                ->get(['provider', 'txnRef', 'createDate']) as $a) {
                $out[$a->provider . '|' . $a->txnRef] ??= ['provider' => (string) $a->provider, 'txnRef' => (string) $a->txnRef, 'createDate' => $a->createDate];
            }
        }
        $rows = DB::table('tbl_payment_logs')->where('bookingId', $bookingId)->where('kind', 'create')->where('result', 'url')->whereNotNull('txnRef')
            ->orderByDesc('logId')->limit(20)->get(['provider', 'txnRef', 'payload', 'createdAt']);
        foreach ($rows as $r) {
            $k = $r->provider . '|' . $r->txnRef;
            if (isset($out[$k])) {
                continue;
            }
            $payload = json_decode((string) $r->payload, true) ?: [];
            $date = $payload['request']['vnp_CreateDate'] ?? null;   // VNPay hỏi trạng thái cần đúng ngày tạo của chính lần thử đó
            if (!$date && $r->createdAt) {
                $date = Carbon::parse($r->createdAt)->setTimezone(config('payments.timezone', 'Asia/Ho_Chi_Minh'))->format('YmdHis');
            }
            $out[$k] = ['provider' => (string) $r->provider, 'txnRef' => (string) $r->txnRef, 'createDate' => $date];
        }

        return array_values($out);
    }

    /* ------------------------------------------------------------------ Lần thử thanh toán (tbl_payment_attempts) */

    private static ?bool $attemptsReady = null;

    public static function attemptsReady(): bool
    {
        return self::$attemptsReady ??= \Illuminate\Support\Facades\Schema::hasTable('tbl_payment_attempts');
    }

    /** Ghi một lần thử trước khi gọi cổng. Trả attemptId (null khi chưa migrate). */
    private function recordAttempt(int $bookingId, string $provider, string $txnRef, int $amount, ?string $createDate): ?int
    {
        if (!self::attemptsReady()) {
            return null;
        }
        $row = DB::table('tbl_payment_attempts')->where(['provider' => $provider, 'txnRef' => $txnRef])->first(['attemptId', 'bookingId', 'status']);
        if ($row) {
            // QR dùng lại mã đơn gốc: cập nhật lần thử chưa trả (số tiền / thời điểm mới), không đụng lần đã trả
            if ((int) $row->bookingId === $bookingId && in_array($row->status, ['created', 'failed'], true)) {
                DB::table('tbl_payment_attempts')->where('attemptId', $row->attemptId)->update(['amount' => $amount, 'createDate' => $createDate, 'status' => 'created', 'updatedAt' => now()]);
            }

            return (int) $row->attemptId;
        }
        try {
            return (int) DB::table('tbl_payment_attempts')->insertGetId(['bookingId' => $bookingId, 'provider' => $provider, 'txnRef' => $txnRef, 'amount' => $amount,
                'createDate' => $createDate, 'status' => 'created', 'createdAt' => now(), 'updatedAt' => now()], 'attemptId');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return (int) DB::table('tbl_payment_attempts')->where(['provider' => $provider, 'txnRef' => $txnRef])->value('attemptId');
        }
    }

    /** Cổng trả về mã khác mã đã ghi (QR): chuyển lần thử sang mã thật. */
    private function renameAttempt(?int $attemptId, int $bookingId, string $provider, string $txnRef, int $amount, ?string $createDate): ?int
    {
        if (!$attemptId || !self::attemptsReady()) {
            return $attemptId;
        }
        if (DB::table('tbl_payment_attempts')->where(['provider' => $provider, 'txnRef' => $txnRef])->exists()) {
            DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->where('status', 'created')->delete();

            return $this->recordAttempt($bookingId, $provider, $txnRef, $amount, $createDate);
        }
        DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->update(['txnRef' => $txnRef, 'updatedAt' => now()]);

        return $attemptId;
    }

    /** Gọi trong transaction settle(): lần thử này là khoản thanh toán của đơn; ngày tạo giao dịch của nó vào checkout (để hỏi / hoàn). */
    private function markPaidAttempt(int $bookingId, string $provider, string $txnRef, ?string $providerTxnNo, int $amount): void
    {
        if (!self::attemptsReady()) {
            return;
        }
        $a = DB::table('tbl_payment_attempts')->where(['provider' => $provider, 'txnRef' => $txnRef])->first();
        if (!$a) {   // đơn tạo trước khi có bảng / admin xác nhận tay: ghi lại để mọi nghiệp vụ sau có một nguồn
            $a = (object) ['attemptId' => $this->recordAttempt($bookingId, $provider, $txnRef, $amount, null), 'createDate' => null];
        }
        DB::table('tbl_payment_attempts')->where('bookingId', $bookingId)->where('attemptId', '!=', $a->attemptId)->update(['settled' => false]);
        DB::table('tbl_payment_attempts')->where('attemptId', $a->attemptId)->update(['status' => 'paid', 'settled' => true, 'providerTxnNo' => $providerTxnNo,
            'paidAt' => now(), 'updatedAt' => now()]);
        if ($a->createDate) {
            DB::table('tbl_checkout')->where('bookingId', $bookingId)->update(['providerCreateDate' => $a->createDate]);
        }
    }

    /**
     * Khoản thu trùng: ghi lần thử là excess + tự tạo yêu cầu hoàn (refundStatus = pending) chờ nhân viên duyệt.
     * @return bool true khi vừa ghi lần đầu (gửi lặp cùng webhook thì false)
     */
    private function markExcess(int $bookingId, string $provider, string $txnRef, ?string $providerTxnNo, int $amount): bool
    {
        if (!self::attemptsReady()) {
            return true;   // chưa migrate: vẫn báo đối soát như trước
        }
        $a = DB::table('tbl_payment_attempts')->where(['provider' => $provider, 'txnRef' => $txnRef])->first();
        if ($a && $a->status === 'excess') {
            return false;
        }
        $data = ['status' => 'excess', 'settled' => false, 'providerTxnNo' => $providerTxnNo, 'paidAt' => now(), 'refundStatus' => 'pending', 'updatedAt' => now()];
        if ($a) {
            DB::table('tbl_payment_attempts')->where('attemptId', $a->attemptId)->update($data);
            $id = (int) $a->attemptId;
            $amount = (int) $a->amount ?: $amount;
        } else {
            $id = (int) DB::table('tbl_payment_attempts')->insertGetId($data + ['bookingId' => $bookingId, 'provider' => $provider, 'txnRef' => mb_substr($txnRef, 0, 60),
                'amount' => $amount, 'createdAt' => now()], 'attemptId');
        }
        app(DoubleEntryLedger::class)->excess($bookingId, $id, $provider, $amount);

        return true;
    }

    /**
     * Khoản thanh toán của đơn (lần thử đã ghi nhận) để hỏi trạng thái / hoàn qua cổng.
     * Dữ liệu cũ chưa có bảng lần thử: dựng từ tbl_checkout (số tiền = đã thu trừ phần thu thêm ngoài cổng).
     * @return ?object{provider:string, txnRef:string, providerTxnNo:?string, createDate:?string, amount:int}
     */
    public function paidAttempt(int $bookingId): ?object
    {
        if (self::attemptsReady()) {
            $a = DB::table('tbl_payment_attempts')->where('bookingId', $bookingId)->where('settled', true)->first(['attemptId', 'provider', 'txnRef', 'providerTxnNo', 'createDate', 'amount']);
            if ($a) {
                $a->amount = (int) $a->amount;

                return $a;
            }
        }
        $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->first();
        if (!$c || !$c->providerTxnRef) {
            return null;
        }
        $collected = \Illuminate\Support\Facades\Schema::hasTable('tbl_booking_adjustments')
            ? (int) DB::table('tbl_booking_adjustments')->where('bookingId', $bookingId)->where('moneyStatus', 'collected')->sum('settleAmount') : 0;

        return (object) ['attemptId' => null, 'provider' => (string) $c->provider, 'txnRef' => (string) $c->providerTxnRef, 'providerTxnNo' => $c->providerTxnNo,
            'createDate' => $c->providerCreateDate, 'amount' => max(0, (int) round((float) $c->amount) - $collected)];
    }

    /** Mã giao dịch có phải của một lần thử đã tạo cho đơn này không. */
    private function isAttemptOf(int $bookingId, ?string $txnRef): bool
    {
        return $txnRef !== null && $txnRef !== '' && in_array($txnRef, array_column($this->attempts($bookingId), 'txnRef'), true);
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
