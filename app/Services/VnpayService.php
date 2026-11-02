<?php

namespace App\Services;

use App\Support\AdminBadges;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Thanh toán VNPay (đặc tả §7.1, §7.6; N-02).
 *
 *   createPaymentUrl()  dựng URL thanh toán (HMAC-SHA512, tham số sắp theo tên, mã hóa URL, số tiền × 100,
 *                       vnp_ExpireDate = hạn giữ chỗ theo giờ VN).
 *   handleIpn()         NGUỒN XÁC NHẬN DUY NHẤT: kiểm chữ ký (hash_equals) → tìm đơn → so số tiền → khóa dòng → cập nhật.
 *                       Gửi lặp bao nhiêu lần cũng chỉ xác nhận một lần (lần sau trả 02).
 *   handleReturn()      chỉ kiểm chữ ký để hiển thị kết quả; KHÔNG đổi trạng thái đơn. Nếu đơn còn chờ thì hỏi lại VNPay
 *                       bằng querydr (máy chủ → VNPay, không tin dữ liệu trình duyệt mang về).
 *   queryAndSettle()    đối soát querydr cho đơn sắp hết hạn giữ chỗ (lệnh payments:reconcile-vnpay, chạy trước expire-holds).
 *
 * Mọi payload create / return / ipn / querydr ghi vào tbl_payment_logs.
 */
class VnpayService
{
    public const RESPONSE = [
        '00' => 'Giao dịch thành công',
        '07' => 'Trừ tiền thành công, giao dịch bị nghi ngờ',
        '09' => 'Thẻ / tài khoản chưa đăng ký Internet Banking',
        '10' => 'Xác thực thông tin thẻ / tài khoản sai quá 3 lần',
        '11' => 'Hết hạn chờ thanh toán',
        '12' => 'Thẻ / tài khoản bị khóa',
        '13' => 'Nhập sai mật khẩu OTP',
        '24' => 'Khách hủy giao dịch',
        '51' => 'Tài khoản không đủ số dư',
        '65' => 'Vượt hạn mức giao dịch trong ngày',
        '75' => 'Ngân hàng đang bảo trì',
        '79' => 'Nhập sai mật khẩu thanh toán quá số lần quy định',
        '99' => 'Lỗi khác',
    ];

    public function __construct(private BookingService $bookings, private DepartureService $departures, private RefundService $refunds) {}

    public static function enabled(): bool
    {
        return (bool) config('vnpay.enabled') && config('vnpay.tmn_code') && config('vnpay.hash_secret');
    }

    public static function holdMinutes(): int
    {
        return max(5, (int) config('vnpay.hold_minutes', 15));
    }

    /* ------------------------------------------------------------------ Chữ ký */

    /** Chuỗi ký theo mẫu PHP của VNPay: sắp theo tên, urlencode khóa và giá trị, nối bằng &. */
    public static function hashData(array $params): string
    {
        $params = array_filter($params, fn($v, $k) => str_starts_with((string) $k, 'vnp_') && !in_array($k, ['vnp_SecureHash', 'vnp_SecureHashType'], true) && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = urlencode((string) $k) . '=' . urlencode((string) $v);
        }

        return implode('&', $parts);
    }

    public static function sign(array $params): string
    {
        return hash_hmac('sha512', self::hashData($params), (string) config('vnpay.hash_secret'));
    }

    public static function verify(array $params): bool
    {
        $given = strtolower((string) ($params['vnp_SecureHash'] ?? ''));

        return $given !== '' && config('vnpay.hash_secret') && hash_equals(self::sign($params), $given);
    }

    /* ------------------------------------------------------------------ Tạo link */

    /**
     * @return array{ok:bool, url?:string, message?:string}
     */
    public function createPaymentUrl(int $bookingId, ?string $ip): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => 'Thanh toán VNPay chưa được bật.'];
        }

        return DB::transaction(function () use ($bookingId, $ip) {
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->lockForUpdate()->first();
            if (!$b || !$c) {
                return ['ok' => false, 'message' => 'Không tìm thấy đơn.'];
            }
            if ($c->paymentStatus !== 'n') {
                return ['ok' => false, 'message' => 'Đơn này đã được thanh toán.'];
            }
            if ($b->bookingStatus !== 'n' && $b->bookingStatus !== 'y') {
                return ['ok' => false, 'message' => 'Đơn đã hủy hoặc đã hoàn tất, không thể thanh toán.'];
            }
            $tz = config('vnpay.timezone', 'Asia/Ho_Chi_Minh');
            $now = now($tz);
            $hold = $b->holdExpiresAt ? Carbon::parse($b->holdExpiresAt)->setTimezone($tz) : null;
            if ($b->bookingStatus === 'n' && $hold && $hold->lte($now->copy()->addMinute())) {
                return ['ok' => false, 'message' => 'Đơn đã hết thời gian giữ chỗ.'];
            }
            // VNPay cho tối đa 15 phút trên trang thanh toán; không vượt quá hạn giữ chỗ
            $expire = $now->copy()->addMinutes(15);
            if ($hold && $hold->lt($expire)) {
                $expire = $hold;
            }

            $attempt = (int) ($c->attempts ?? 0) + 1;
            $txnRef = $attempt === 1 ? $b->bookingCode : $b->bookingCode . '-' . $attempt;   // mỗi lần thử một mã riêng
            $amount = (int) round((float) $c->amount);
            $params = [
                'vnp_Version' => config('vnpay.version', '2.1.0'),
                'vnp_Command' => 'pay',
                'vnp_TmnCode' => config('vnpay.tmn_code'),
                'vnp_Amount' => $amount * 100,
                'vnp_CurrCode' => 'VND',
                'vnp_TxnRef' => $txnRef,
                'vnp_OrderInfo' => 'Thanh toan don dat tour ' . $b->bookingCode,
                'vnp_OrderType' => 'other',
                'vnp_Locale' => config('vnpay.locale', 'vn'),
                'vnp_ReturnUrl' => config('vnpay.return_url') ?: route('payment.vnpay.return'),
                'vnp_IpAddr' => $ip ?: '127.0.0.1',
                'vnp_CreateDate' => $now->format('YmdHis'),
                'vnp_ExpireDate' => $expire->format('YmdHis'),
            ];
            $url = rtrim((string) config('vnpay.pay_url'), '?') . '?' . self::hashData($params) . '&vnp_SecureHash=' . self::sign($params);

            DB::table('tbl_checkout')->where('bookingId', $bookingId)->update([
                'paymentMethod' => 'vnpay', 'provider' => 'vnpay', 'providerTxnRef' => $txnRef,
                'attempts' => $attempt, 'providerCreateDate' => $params['vnp_CreateDate'],
            ]);
            $this->log($bookingId, 'create', $txnRef, 'url', null, $params, $ip);

            return ['ok' => true, 'url' => $url];
        });
    }

    /* ------------------------------------------------------------------ IPN */

    /** @return array{RspCode:string, Message:string} */
    public function handleIpn(array $params, ?string $ip): array
    {
        $txnRef = (string) ($params['vnp_TxnRef'] ?? '');
        $valid = self::verify($params);
        if (!$valid) {
            $this->log(null, 'ipn', $txnRef, '97', false, $params, $ip);

            return ['RspCode' => '97', 'Message' => 'Invalid signature'];
        }
        $checkout = $this->findCheckout($txnRef);
        if (!$checkout) {
            $this->log(null, 'ipn', $txnRef, '01', true, $params, $ip);

            return ['RspCode' => '01', 'Message' => 'Order not found'];
        }
        $bookingId = (int) $checkout->bookingId;
        if ((int) ($params['vnp_Amount'] ?? -1) !== (int) round((float) $checkout->amount) * 100) {
            $this->log($bookingId, 'ipn', $txnRef, '04', true, $params, $ip);

            return ['RspCode' => '04', 'Message' => 'Invalid amount'];
        }

        try {
            $result = $this->settle($bookingId, $txnRef, $params, 'ipn');
        } catch (\Throwable $e) {
            report($e);
            $this->log($bookingId, 'ipn', $txnRef, '99', true, $params, $ip);

            return ['RspCode' => '99', 'Message' => 'Unknown error'];
        }
        $this->log($bookingId, 'ipn', $txnRef, $result['code'] === '02' ? '02' : ($result['paid'] ? 'paid' : 'failed:' . ($params['vnp_ResponseCode'] ?? '')), true, $params, $ip);

        return $result['code'] === '02'
            ? ['RspCode' => '02', 'Message' => 'Order already confirmed']
            : ['RspCode' => '00', 'Message' => 'Confirm Success'];
    }

    /* ------------------------------------------------------------------ Return */

    /**
     * Trang khách quay về: chỉ hiển thị. @return array{valid:bool, success:bool, bookingId:?int, code:string, message:string, checkoutId:?int}
     */
    public function handleReturn(array $params, ?string $ip): array
    {
        $txnRef = (string) ($params['vnp_TxnRef'] ?? '');
        $valid = self::verify($params);
        $checkout = $valid ? $this->findCheckout($txnRef) : null;
        $code = (string) ($params['vnp_ResponseCode'] ?? '99');
        $this->log($checkout->bookingId ?? null, 'return', $txnRef, $code, $valid, $params, $ip);

        // Khách báo thành công nhưng IPN chưa tới (localhost không có tunnel / IPN chậm): hỏi lại VNPay bằng querydr
        if ($valid && $checkout && $code === '00' && $checkout->paymentStatus === 'n') {
            $this->queryAndSettle((int) $checkout->bookingId, $ip);
            $checkout = $this->findCheckout($txnRef);
        }

        return [
            'valid' => $valid,
            'success' => $valid && $code === '00' && ($params['vnp_TransactionStatus'] ?? '00') === '00',
            'paid' => $checkout ? in_array($checkout->paymentStatus, ['y', 'refund_pending', 'refunded'], true) : false,
            'bookingId' => $checkout->bookingId ?? null,
            'checkoutId' => $checkout->checkoutId ?? null,
            'code' => $code,
            'message' => self::RESPONSE[$code] ?? 'Giao dịch không thành công',
            'amount' => isset($params['vnp_Amount']) ? (int) $params['vnp_Amount'] / 100 : null,
            'bankCode' => $params['vnp_BankCode'] ?? null,
        ];
    }

    /* ------------------------------------------------------------------ querydr */

    /** Hỏi VNPay trạng thái giao dịch của đơn; thành công thì ghi nhận như IPN. @return array{ok:bool, status:?string, message:string} */
    public function queryAndSettle(int $bookingId, ?string $ip = null): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'status' => null, 'message' => 'VNPay chưa bật.'];
        }
        $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->first();
        if (!$c || !$c->providerTxnRef || !$c->providerCreateDate) {
            return ['ok' => false, 'status' => null, 'message' => 'Đơn chưa tạo giao dịch VNPay.'];
        }
        $tz = config('vnpay.timezone', 'Asia/Ho_Chi_Minh');
        $req = [
            'vnp_RequestId' => substr(bin2hex(random_bytes(16)), 0, 32),
            'vnp_Version' => config('vnpay.version', '2.1.0'),
            'vnp_Command' => 'querydr',
            'vnp_TmnCode' => config('vnpay.tmn_code'),
            'vnp_TxnRef' => $c->providerTxnRef,
            'vnp_OrderInfo' => 'Truy van don ' . $c->providerTxnRef,
            'vnp_TransactionDate' => $c->providerCreateDate,
            'vnp_CreateDate' => now($tz)->format('YmdHis'),
            'vnp_IpAddr' => $ip ?: (request()?->server('SERVER_ADDR') ?: '127.0.0.1'),
        ];
        $req['vnp_SecureHash'] = hash_hmac('sha512', implode('|', [
            $req['vnp_RequestId'], $req['vnp_Version'], $req['vnp_Command'], $req['vnp_TmnCode'], $req['vnp_TxnRef'],
            $req['vnp_TransactionDate'], $req['vnp_CreateDate'], $req['vnp_IpAddr'], $req['vnp_OrderInfo'],
        ]), (string) config('vnpay.hash_secret'));

        try {
            $res = Http::timeout((int) config('vnpay.timeout', 8))->acceptJson()->post((string) config('vnpay.api_url'), $req)->json() ?? [];
        } catch (\Throwable $e) {
            $this->log($bookingId, 'querydr', $c->providerTxnRef, 'http_error', null, ['request' => $req, 'error' => $e->getMessage()], $ip);

            return ['ok' => false, 'status' => null, 'message' => 'Không gọi được VNPay: ' . $e->getMessage()];
        }

        $valid = $this->verifyQueryResponse($res);
        $code = (string) ($res['vnp_ResponseCode'] ?? '');
        $status = (string) ($res['vnp_TransactionStatus'] ?? '');
        $amountOk = (int) ($res['vnp_Amount'] ?? -1) === (int) round((float) $c->amount) * 100;
        $this->log($bookingId, 'querydr', $c->providerTxnRef, $code . '/' . $status . ($amountOk ? '' : '/amount'), $valid, ['request' => $req, 'response' => $res], $ip);

        if (!$valid || $code !== '00') {
            return ['ok' => false, 'status' => $status ?: null, 'message' => $valid ? 'VNPay trả mã ' . $code . ': ' . ($res['vnp_Message'] ?? '') : 'Chữ ký phản hồi querydr không hợp lệ.'];
        }
        if ($status === '00' && $amountOk) {
            $r = $this->settle($bookingId, (string) $c->providerTxnRef, [
                'vnp_ResponseCode' => '00', 'vnp_TransactionStatus' => '00',
                'vnp_TransactionNo' => $res['vnp_TransactionNo'] ?? null, 'vnp_BankCode' => $res['vnp_BankCode'] ?? null,
                'vnp_PayDate' => $res['vnp_PayDate'] ?? null,
            ], 'querydr');

            return ['ok' => true, 'status' => '00', 'message' => $r['code'] === '02' ? 'Đơn đã được ghi nhận thanh toán trước đó.' : 'VNPay xác nhận đã thanh toán, đơn đã được cập nhật.'];
        }

        return ['ok' => true, 'status' => $status, 'message' => 'VNPay: giao dịch chưa thành công (trạng thái ' . $status . ').'];
    }

    private function verifyQueryResponse(array $r): bool
    {
        if (empty($r['vnp_SecureHash'])) {
            return false;
        }
        $data = implode('|', array_map(fn($k) => (string) ($r[$k] ?? ''), [
            'vnp_ResponseId', 'vnp_Command', 'vnp_ResponseCode', 'vnp_Message', 'vnp_TmnCode', 'vnp_TxnRef', 'vnp_Amount', 'vnp_BankCode',
            'vnp_PayDate', 'vnp_TransactionNo', 'vnp_TransactionType', 'vnp_TransactionStatus', 'vnp_OrderInfo', 'vnp_PromotionCode', 'vnp_PromotionAmount',
        ]));

        return hash_equals(hash_hmac('sha512', $data, (string) config('vnpay.hash_secret')), strtolower((string) $r['vnp_SecureHash']));
    }

    /* ------------------------------------------------------------------ Ghi nhận kết quả */

    /**
     * Áp kết quả thanh toán lên đơn (trong transaction, có khóa dòng). Dùng chung cho IPN và querydr.
     * @return array{code:string, paid:bool}  code 02 = đã ghi nhận trước đó
     */
    private function settle(int $bookingId, string $txnRef, array $p, string $source): array
    {
        $out = DB::transaction(function () use ($bookingId, $txnRef, $p, $source) {
            $b = DB::table('tbl_booking')->where('bookingId', $bookingId)->lockForUpdate()->first();
            $c = DB::table('tbl_checkout')->where('bookingId', $bookingId)->lockForUpdate()->first();
            if (in_array($c->paymentStatus, ['y', 'refund_pending', 'refunded'], true)) {
                return ['code' => '02', 'paid' => true];
            }
            $ok = ($p['vnp_ResponseCode'] ?? '') === '00' && ($p['vnp_TransactionStatus'] ?? '00') === '00';
            if (!$ok) {
                // Thất bại / khách hủy: ghi mã lỗi, đơn vẫn chờ để khách thanh toán lại trong hạn giữ chỗ
                DB::table('tbl_checkout')->where('bookingId', $bookingId)->update(['responseCode' => substr((string) ($p['vnp_ResponseCode'] ?? '99'), 0, 4)]);

                return ['code' => '00', 'paid' => false];
            }

            $paidAt = !empty($p['vnp_PayDate']) ? Carbon::createFromFormat('YmdHis', $p['vnp_PayDate'], config('vnpay.timezone'))->setTimezone(config('app.timezone')) : now();
            DB::table('tbl_checkout')->where('bookingId', $bookingId)->update([
                'paymentStatus' => 'y', 'paymentDate' => $paidAt, 'paidAt' => $paidAt,
                'paymentMethod' => 'vnpay', 'provider' => 'vnpay', 'providerTxnRef' => $txnRef,
                'providerTxnNo' => $p['vnp_TransactionNo'] ?? null, 'transactionId' => $p['vnp_TransactionNo'] ?? $txnRef,
                'bankCode' => $p['vnp_BankCode'] ?? null, 'responseCode' => '00',
            ]);
            DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['holdExpiresAt' => null]);

            if ($b->bookingStatus === 'n') {
                $this->bookings->confirmLocked($b);   // §6.4: IPN thành công -> đơn đã xác nhận, chỗ giữ thành chỗ bán

                return ['code' => '00', 'paid' => true, 'event' => 'confirmed', 'booking' => $b];
            }
            if ($b->bookingStatus === 'c') {
                // Tiền về SAU khi đơn đã hủy / hết hạn: còn chỗ thì xác nhận lại, hết chỗ thì hoàn tiền 100%
                $people = (int) $b->numAdults + (int) $b->numChildren;
                if ($b->availabilityId && $this->departures->bookDirect((int) $b->availabilityId, $people)) {
                    DB::table('tbl_booking')->where('bookingId', $bookingId)->update(['bookingStatus' => 'y', 'cancelReason' => null]);
                    $this->departures->syncTour((int) $b->tourId);

                    return ['code' => '00', 'paid' => true, 'event' => 'revived', 'booking' => $b];
                }
                $this->refunds->requestLocked($b, 'system', null, 100, 'Thanh toán VNPay đến sau khi đơn đã hết hạn và không còn chỗ');

                return ['code' => '00', 'paid' => true, 'event' => 'late_refund', 'booking' => $b];
            }

            return ['code' => '00', 'paid' => true, 'event' => 'paid', 'booking' => $b];
        });

        if (!empty($out['event'])) {
            $b = $out['booking'];
            $label = ['confirmed' => 'VNPay xác nhận thanh toán, đơn chuyển sang Đã xác nhận', 'revived' => 'Tiền về sau khi đơn hết hạn: còn chỗ nên xác nhận lại',
                'late_refund' => 'Tiền về sau khi đơn hết hạn và hết chỗ: tạo yêu cầu hoàn 100%', 'paid' => 'VNPay xác nhận thanh toán'][$out['event']];
            AuditLog::record('payment.vnpay', 'booking', $bookingId, $label . ' (' . $source . ', ' . $b->bookingCode . ')',
                ['bookingStatus' => $b->bookingStatus, 'paymentStatus' => 'n'], ['paymentStatus' => $out['event'] === 'late_refund' ? 'refund_pending' : 'y', 'txnNo' => $p['vnp_TransactionNo'] ?? null]);
            AdminBadges::forget();
            if (in_array($out['event'], ['confirmed', 'revived', 'paid'], true)) {
                app(BookingNotifier::class)->send($bookingId, 'paid');
            }
        }

        return ['code' => $out['code'], 'paid' => $out['paid']];
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

    private function log(?int $bookingId, string $kind, ?string $txnRef, ?string $result, ?bool $valid, array $payload, ?string $ip): void
    {
        try {
            unset($payload['vnp_SecureHash']);
            if (isset($payload['request']['vnp_SecureHash'])) {
                unset($payload['request']['vnp_SecureHash']);
            }
            DB::table('tbl_payment_logs')->insert([
                'bookingId' => $bookingId, 'provider' => 'vnpay', 'kind' => $kind, 'txnRef' => $txnRef ? mb_substr($txnRef, 0, 60) : null,
                'result' => $result ? mb_substr($result, 0, 40) : null, 'validSignature' => $valid,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'ip' => $ip, 'createdAt' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
