<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * VNPay, cổng thanh toán phiên bản 2.1.0 (sandbox.vnpayment.vn/apis).
 *
 *   create    Chuyển khách tới {pay_url}?vnp_... ; tham số xếp theo tên a→z, giá trị urlencode, nối bằng &,
 *             vnp_SecureHash = HMAC-SHA512(hash_secret, chuỗi đó). vnp_Amount = số tiền × 100.
 *   IPN       VNPay gọi GET {ipn_url}?vnp_... (khai báo URL IPN trong trang quản trị merchant của VNPay). Kiểm chữ ký giống trên,
 *             thành công khi vnp_ResponseCode = 00 VÀ vnp_TransactionStatus = 00. Trả JSON {RspCode, Message}:
 *             00 đã ghi nhận · 01 không có đơn · 02 đơn đã xác nhận trước đó · 04 sai số tiền · 97 sai chữ ký · 99 lỗi khác.
 *   return    Trang khách quay về có cùng tham số + chữ ký: chỉ để hiển thị.
 *   querydr   POST JSON {api_url}; hash = HMAC-SHA512 của RequestId|Version|Command|TmnCode|TxnRef|TransactionDate|CreateDate|IpAddr|OrderInfo.
 *   refund    POST JSON {api_url}; vnp_TransactionType 02 hoàn toàn phần / 03 hoàn một phần;
 *             hash = RequestId|Version|Command|TmnCode|TransactionType|TxnRef|Amount|TransactionNo|TransactionDate|CreateBy|CreateDate|IpAddr|OrderInfo.
 *             VNPay không có lệnh "hỏi trạng thái hoàn" riêng: hỏi querydr của giao dịch gốc rồi đọc vnp_TransactionType (02/03) và
 *             vnp_TransactionStatus (00 đã hoàn, 05 đang xử lý, 06 đã gửi ngân hàng, 09 bị từ chối).
 */
class VnpayGateway implements PaymentGateway
{
    public const CODES = [
        '00' => 'Giao dịch thành công',
        '07' => 'Trừ tiền thành công, giao dịch bị nghi ngờ (gian lận, bất thường)',
        '09' => 'Thẻ / tài khoản chưa đăng ký Internet Banking',
        '10' => 'Xác thực thông tin thẻ / tài khoản sai quá 3 lần',
        '11' => 'Đã hết hạn chờ thanh toán',
        '12' => 'Thẻ / tài khoản bị khóa',
        '13' => 'Nhập sai mật khẩu xác thực giao dịch (OTP)',
        '24' => 'Khách hủy giao dịch',
        '51' => 'Tài khoản không đủ số dư',
        '65' => 'Tài khoản vượt hạn mức giao dịch trong ngày',
        '75' => 'Ngân hàng thanh toán đang bảo trì',
        '79' => 'Nhập sai mật khẩu thanh toán quá số lần quy định',
        '91' => 'Không tìm thấy giao dịch yêu cầu',
        '94' => 'Yêu cầu bị trùng lặp trong thời gian giới hạn',
        '95' => 'Giao dịch này không thành công bên VNPay, VNPay từ chối xử lý',
        '97' => 'Sai chữ ký',
        '99' => 'Lỗi không xác định',
    ];

    private function cfg(string $k): string
    {
        return (string) config('payments.vnpay.' . $k, '');
    }

    public function code(): string
    {
        return 'vnpay';
    }

    public function label(): string
    {
        return 'VNPay';
    }

    public function enabled(): bool
    {
        return (bool) config('payments.vnpay.enabled') && $this->cfg('tmn_code') && $this->cfg('hash_secret');
    }

    public function holdMinutes(): int
    {
        return max(5, (int) config('payments.vnpay.hold_minutes', 15));
    }

    private static function now(): \Carbon\Carbon
    {
        return now(config('payments.timezone', 'Asia/Ho_Chi_Minh'));
    }

    /** Chuỗi ký của URL thanh toán / IPN / return: key=urlencode(value) xếp theo key, nối &. */
    public static function hashData(array $p): string
    {
        ksort($p);
        $parts = [];
        foreach ($p as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $parts[] = urlencode((string) $k) . '=' . urlencode((string) $v);
        }

        return implode('&', $parts);
    }

    public function sign(string $data): string
    {
        return hash_hmac('sha512', $data, $this->cfg('hash_secret'));
    }

    public function create(array $o): array
    {
        $at = self::now();
        $expire = $o['expireAt'] instanceof \Carbon\Carbon ? $o['expireAt']->copy()->setTimezone(config('payments.timezone', 'Asia/Ho_Chi_Minh')) : $at->copy()->addMinutes(15);
        $p = [
            'vnp_Version' => '2.1.0', 'vnp_Command' => 'pay', 'vnp_TmnCode' => $this->cfg('tmn_code'),
            'vnp_Amount' => (int) $o['amount'] * 100, 'vnp_CurrCode' => 'VND', 'vnp_TxnRef' => $o['txnRef'],
            // VNPay chỉ nhận chữ không dấu, không ký tự đặc biệt trong OrderInfo
            'vnp_OrderInfo' => mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', \Illuminate\Support\Str::ascii((string) $o['info'])), 0, 255),
            'vnp_OrderType' => 'other', 'vnp_Locale' => 'vn', 'vnp_ReturnUrl' => $o['returnUrl'],
            'vnp_IpAddr' => $o['ip'] ?: '127.0.0.1', 'vnp_CreateDate' => $at->format('YmdHis'),
            'vnp_ExpireDate' => $expire->format('YmdHis'),
        ];
        if ($this->cfg('bank_code')) {
            $p['vnp_BankCode'] = $this->cfg('bank_code');
        }
        $data = self::hashData($p);
        $url = rtrim($this->cfg('pay_url'), '?') . '?' . $data . '&vnp_SecureHash=' . $this->sign($data);

        return ['ok' => true, 'url' => $url, 'raw' => ['request' => $p]];
    }

    /** Kiểm chữ ký một bộ tham số vnp_* (IPN / return). */
    public function verify(array $q): bool
    {
        $given = strtolower((string) ($q['vnp_SecureHash'] ?? ''));
        if ($given === '' || !$this->cfg('hash_secret')) {
            return false;
        }
        $p = array_filter($q, fn($k) => str_starts_with((string) $k, 'vnp_') && !in_array($k, ['vnp_SecureHash', 'vnp_SecureHashType'], true), ARRAY_FILTER_USE_KEY);

        return hash_equals($this->sign(self::hashData($p)), $given);
    }

    private function normalize(array $q): array
    {
        $valid = $this->verify($q);
        $code = (string) ($q['vnp_ResponseCode'] ?? '99');
        $status = (string) ($q['vnp_TransactionStatus'] ?? $code);

        return [
            'valid' => $valid,
            'txnRef' => isset($q['vnp_TxnRef']) ? (string) $q['vnp_TxnRef'] : null,
            'amount' => isset($q['vnp_Amount']) ? intdiv((int) $q['vnp_Amount'], 100) : null,
            'success' => $valid && $code === '00' && $status === '00',
            'providerTxnNo' => !empty($q['vnp_TransactionNo']) ? (string) $q['vnp_TransactionNo'] : null,
            'code' => $code,
            'bank' => isset($q['vnp_BankCode']) ? 'VNPAY-' . strtoupper((string) $q['vnp_BankCode']) : 'VNPAY',
            'payload' => $q,
        ];
    }

    public function parseNotify(Request $request): array
    {
        return $this->normalize($request->query() ?: $request->all());
    }

    public function notifyReply(string $outcome): Response
    {
        [$code, $msg] = match ($outcome) {
            'ok' => ['00', 'Confirm Success'],
            'duplicate' => ['02', 'Order already confirmed'],
            'not_found' => ['01', 'Order not found'],
            'bad_amount' => ['04', 'Invalid amount'],
            'invalid_signature' => ['97', 'Invalid signature'],
            default => ['99', 'Unknown error'],
        };

        return response()->json(['RspCode' => $code, 'Message' => $msg]);
    }

    public function parseReturn(array $query): array
    {
        $n = $this->normalize($query);

        return ['valid' => $n['valid'], 'txnRef' => $n['txnRef'], 'amount' => $n['amount'], 'success' => $n['success'], 'code' => $n['code']];
    }

    /** Gọi API giao dịch (querydr / refund). @return array{0:?array,1:?string} [phản hồi, lỗi] */
    private function api(array $body): array
    {
        try {
            $res = Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asJson()->post($this->cfg('api_url'), $body);
        } catch (\Throwable $e) {
            return [null, 'Không gọi được VNPay: ' . $e->getMessage()];
        }
        $j = $res->json();

        return is_array($j) && isset($j['vnp_ResponseCode']) ? [$j, null] : [null, 'VNPay trả về phản hồi không đọc được (HTTP ' . $res->status() . ').'];
    }

    /** Chữ ký phản hồi của querydr / refund (nếu VNPay có gửi). */
    private function verifyApi(array $j, array $keys): bool
    {
        if (empty($j['vnp_SecureHash'])) {
            return true;
        }
        $data = implode('|', array_map(fn($k) => (string) ($j[$k] ?? ''), $keys));

        return hash_equals($this->sign($data), strtolower((string) $j['vnp_SecureHash']));
    }

    private const QUERY_RESPONSE_KEYS = ['vnp_ResponseId', 'vnp_Command', 'vnp_ResponseCode', 'vnp_Message', 'vnp_TmnCode', 'vnp_TxnRef', 'vnp_Amount', 'vnp_BankCode',
        'vnp_PayDate', 'vnp_TransactionNo', 'vnp_TransactionType', 'vnp_TransactionStatus', 'vnp_OrderInfo', 'vnp_PromotionCode', 'vnp_PromotionAmount'];

    private function querydr(string $txnRef, ?string $createDate): array
    {
        $at = self::now();
        $p = ['vnp_RequestId' => substr(bin2hex(random_bytes(16)), 0, 32), 'vnp_Version' => '2.1.0', 'vnp_Command' => 'querydr', 'vnp_TmnCode' => $this->cfg('tmn_code'),
            'vnp_TxnRef' => $txnRef, 'vnp_OrderInfo' => 'Kiem tra giao dich ' . $txnRef, 'vnp_TransactionDate' => (string) ($createDate ?: $at->format('YmdHis')),
            'vnp_CreateDate' => $at->format('YmdHis'), 'vnp_IpAddr' => request()?->ip() ?: '127.0.0.1'];
        $p['vnp_SecureHash'] = $this->sign(implode('|', [$p['vnp_RequestId'], $p['vnp_Version'], $p['vnp_Command'], $p['vnp_TmnCode'], $p['vnp_TxnRef'],
            $p['vnp_TransactionDate'], $p['vnp_CreateDate'], $p['vnp_IpAddr'], $p['vnp_OrderInfo']]));
        [$j, $err] = $this->api($p);

        return [$p, $j, $err];
    }

    public function query(string $txnRef, ?string $createDate): array
    {
        [$p, $j, $err] = $this->querydr($txnRef, $createDate);
        if ($err) {
            return ['ok' => false, 'status' => null, 'amount' => null, 'providerTxnNo' => null, 'code' => 'http_error', 'message' => $err, 'raw' => ['request' => $p]];
        }
        $code = (string) $j['vnp_ResponseCode'];
        if (!$this->verifyApi($j, self::QUERY_RESPONSE_KEYS)) {
            return ['ok' => false, 'status' => null, 'amount' => null, 'providerTxnNo' => null, 'code' => '97', 'message' => 'Phản hồi VNPay sai chữ ký.', 'raw' => ['request' => $p, 'response' => $j]];
        }
        if ($code !== '00') {
            // 91: VNPay chưa có giao dịch (khách chưa thanh toán) → vẫn chờ
            return ['ok' => true, 'status' => $code === '91' ? 'pending' : 'failed', 'amount' => null, 'providerTxnNo' => null, 'code' => $code,
                'message' => 'VNPay: ' . ($j['vnp_Message'] ?? $this->describe($code)), 'raw' => ['request' => $p, 'response' => $j]];
        }
        $ts = (string) ($j['vnp_TransactionStatus'] ?? '');
        $status = $ts === '00' ? 'paid' : ($ts === '01' ? 'pending' : 'failed');

        return ['ok' => true, 'status' => $status, 'amount' => isset($j['vnp_Amount']) ? intdiv((int) $j['vnp_Amount'], 100) : null,
            'providerTxnNo' => !empty($j['vnp_TransactionNo']) ? (string) $j['vnp_TransactionNo'] : null, 'code' => $ts,
            'message' => 'VNPay: ' . $this->describe($ts === '' ? $code : $ts), 'raw' => ['request' => $p, 'response' => $j]];
    }

    public function describe(string $code): string
    {
        return self::CODES[$code] ?? 'Mã ' . $code;
    }

    /* ------------------------------------------------------------------ Hoàn tiền */

    public function supportsRefund(): bool
    {
        return true;
    }

    /** vnp_RequestId của lệnh hoàn: duy nhất cho mỗi lần thử, tối đa 32 ký tự. */
    public function newRefundRef(int $refundId, int $attempt): string
    {
        return mb_substr('RF' . $refundId . 'A' . $attempt . 'T' . self::now()->format('ymdHis') . random_int(10, 99), 0, 32);
    }

    private static function result(string $status, ?string $no, string $code, string $message, array $raw): array
    {
        return ['ok' => $status !== 'unknown', 'status' => $status, 'refundNo' => $no, 'code' => $code, 'message' => $message, 'raw' => $raw];
    }

    /**
     * @param array{txnRef:string, providerTxnNo:?string, amount:int, refundRef:string, reason:string, bookingCode:string, transactionDate?:?string, paidAmount?:int, createdBy?:string} $o
     */
    public function refund(array $o): array
    {
        $at = self::now();
        $full = isset($o['paidAmount']) && (int) $o['paidAmount'] === (int) $o['amount'];
        $p = ['vnp_RequestId' => $o['refundRef'], 'vnp_Version' => '2.1.0', 'vnp_Command' => 'refund', 'vnp_TmnCode' => $this->cfg('tmn_code'),
            'vnp_TransactionType' => $full ? '02' : '03', 'vnp_TxnRef' => $o['txnRef'], 'vnp_Amount' => (int) $o['amount'] * 100,
            'vnp_OrderInfo' => mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', \Illuminate\Support\Str::ascii((string) ($o['reason'] ?: 'Hoan tien'))), 0, 255),
            'vnp_TransactionNo' => (string) ($o['providerTxnNo'] ?? ''), 'vnp_TransactionDate' => (string) ($o['transactionDate'] ?? ''),
            'vnp_CreateBy' => mb_substr((string) ($o['createdBy'] ?? 'travela'), 0, 64), 'vnp_CreateDate' => $at->format('YmdHis'),
            'vnp_IpAddr' => request()?->ip() ?: '127.0.0.1'];
        if ($p['vnp_TransactionDate'] === '') {
            return self::result('failed', null, 'no_date', 'Thiếu ngày tạo giao dịch gốc (vnp_TransactionDate) nên không hoàn tự động được.', ['request' => $p]);
        }
        $p['vnp_SecureHash'] = $this->sign(implode('|', [$p['vnp_RequestId'], $p['vnp_Version'], $p['vnp_Command'], $p['vnp_TmnCode'], $p['vnp_TransactionType'],
            $p['vnp_TxnRef'], $p['vnp_Amount'], $p['vnp_TransactionNo'], $p['vnp_TransactionDate'], $p['vnp_CreateBy'], $p['vnp_CreateDate'], $p['vnp_IpAddr'], $p['vnp_OrderInfo']]));
        [$j, $err] = $this->api($p);
        if ($err) {
            return self::result('unknown', null, 'http_error', $err, ['request' => $p]);
        }
        $code = (string) $j['vnp_ResponseCode'];
        $raw = ['request' => $p, 'response' => $j];
        if (!$this->verifyApi($j, ['vnp_ResponseId', 'vnp_Command', 'vnp_ResponseCode', 'vnp_Message', 'vnp_TmnCode', 'vnp_TxnRef', 'vnp_Amount', 'vnp_BankCode',
            'vnp_PayDate', 'vnp_TransactionNo', 'vnp_TransactionType', 'vnp_TransactionStatus', 'vnp_OrderInfo'])) {
            return self::result('unknown', null, '97', 'Phản hồi hoàn tiền của VNPay sai chữ ký: không coi là đã hoàn, hỏi lại trạng thái.', $raw);
        }
        $msg = 'VNPay: ' . ($j['vnp_Message'] ?? $this->describe($code));
        if ($code === '00') {
            $ts = (string) ($j['vnp_TransactionStatus'] ?? '00');

            return self::result(in_array($ts, ['05', '06'], true) ? 'pending' : 'refunded', !empty($j['vnp_TransactionNo']) ? (string) $j['vnp_TransactionNo'] : null, $code, $msg, $raw);
        }
        // 94: lệnh trùng (đã gửi trước đó) → coi như đang xử lý, chỉ hỏi trạng thái; 99: không rõ
        if ($code === '94') {
            return self::result('pending', null, $code, $msg, $raw);
        }

        return self::result($code === '99' ? 'unknown' : 'failed', null, $code, $msg, $raw);
    }

    public function queryRefund(string $refundRef): array
    {
        $bookingId = DB::table('tbl_refunds')->where('gatewayRefundRef', $refundRef)->value('bookingId');
        if (!$bookingId && \Illuminate\Support\Facades\Schema::hasColumn('tbl_booking_adjustments', 'gatewayRefundRef')) {
            $bookingId = DB::table('tbl_booking_adjustments')->where('gatewayRefundRef', $refundRef)->value('bookingId');
        }
        $c = $bookingId ? DB::table('tbl_checkout')->where('bookingId', $bookingId)->first() : null;
        if (!$c || !$c->providerTxnRef) {
            return self::result('unknown', null, 'no_txn', 'Không tìm thấy giao dịch gốc của lệnh hoàn.', []);
        }
        [$p, $j, $err] = $this->querydr((string) $c->providerTxnRef, $c->providerCreateDate);
        if ($err) {
            return self::result('unknown', null, 'http_error', $err, ['request' => $p]);
        }
        $raw = ['request' => $p, 'response' => $j];
        if ((string) $j['vnp_ResponseCode'] !== '00') {
            return self::result('unknown', null, (string) $j['vnp_ResponseCode'], 'VNPay: ' . ($j['vnp_Message'] ?? 'chưa hỏi được trạng thái'), $raw);
        }
        $type = (string) ($j['vnp_TransactionType'] ?? '01');
        $ts = (string) ($j['vnp_TransactionStatus'] ?? '');
        if (!in_array($type, ['02', '03'], true)) {
            return self::result('unknown', null, $ts, 'VNPay chưa ghi nhận lệnh hoàn cho giao dịch này.', $raw);
        }
        $status = match ($ts) {
            '00' => 'refunded',
            '05', '06' => 'pending',
            '09' => 'failed',
            default => 'unknown',
        };

        return self::result($status, !empty($j['vnp_TransactionNo']) ? (string) $j['vnp_TransactionNo'] : null, $ts,
            'VNPay: ' . (['00' => 'đã hoàn tiền', '05' => 'đang xử lý hoàn', '06' => 'đã gửi yêu cầu hoàn sang ngân hàng', '09' => 'từ chối hoàn'][$ts] ?? ('trạng thái ' . $ts)), $raw);
    }
}
