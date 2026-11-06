<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * MoMo, cổng thanh toán API v2 (developers.momo.vn).
 *   create  POST {endpoint}/v2/gateway/api/create, chữ ký HMAC-SHA256 trên các cặp key=value xếp theo tên (accessKey, amount, extraData,
 *           ipnUrl, orderId, orderInfo, partnerCode, redirectUrl, requestId, requestType) → payUrl.
 *   IPN     MoMo POST JSON tới ipnUrl (gửi kèm mỗi lần tạo, không cần khai báo trước); chữ ký trên accessKey, amount, extraData, message,
 *           orderId, orderInfo, orderType, partnerCode, payType, requestId, responseTime, resultCode, transId. Trả HTTP 204.
 *   return  redirectUrl nhận cùng bộ tham số và chữ ký như IPN (chỉ để hiển thị).
 *   query   POST {endpoint}/v2/gateway/api/query, chữ ký accessKey, orderId, partnerCode, requestId.
 */
class MomoGateway implements PaymentGateway
{
    public const CODES = [
        '0' => 'Giao dịch thành công',
        '9000' => 'Giao dịch đã được cấp quyền (chờ ghi nhận)',
        '1000' => 'Giao dịch đã khởi tạo, đang chờ khách thanh toán',
        '7000' => 'Giao dịch đang được xử lý',
        '7002' => 'Giao dịch đang được nhà cung cấp xử lý',
        '1001' => 'Tài khoản không đủ số dư',
        '1002' => 'Giao dịch bị từ chối bởi nhà phát hành',
        '1003' => 'Giao dịch đã bị hủy',
        '1004' => 'Vượt hạn mức thanh toán',
        '1005' => 'Link / mã QR đã hết hạn',
        '1006' => 'Khách đã từ chối xác nhận thanh toán',
        '1007' => 'Tài khoản MoMo đang bị tạm khóa',
        '1017' => 'Giao dịch bị hủy bởi đối tác',
        '4100' => 'Khách đăng nhập không thành công',
        '11' => 'Truy cập bị từ chối',
        '12' => 'Phiên bản API không được hỗ trợ',
        '13' => 'Xác thực doanh nghiệp thất bại',
        '20' => 'Yêu cầu sai định dạng',
        '21' => 'Số tiền không hợp lệ',
        '22' => 'Số tiền ngoài hạn mức cho phép',
        '40' => 'requestId bị trùng',
        '41' => 'orderId bị trùng',
        '42' => 'orderId không hợp lệ hoặc không tìm thấy',
        '43' => 'Đơn đang được xử lý ở một giao dịch khác',
        '99' => 'Lỗi không xác định',
    ];

    private const IPN_FIELDS = ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'];

    private function cfg(string $k): string
    {
        return (string) config('payments.momo.' . $k, '');
    }

    public function code(): string
    {
        return 'momo';
    }

    public function label(): string
    {
        return 'Ví MoMo';
    }

    public function enabled(): bool
    {
        return (bool) config('payments.momo.enabled') && $this->cfg('partner_code') && $this->cfg('access_key') && $this->cfg('secret_key');
    }

    public function holdMinutes(): int
    {
        return max(5, (int) config('payments.momo.hold_minutes', 15));
    }

    /** Chuỗi ký: key=value nối bằng &, theo đúng thứ tự $keys (MoMo yêu cầu xếp theo tên a→z). */
    public static function raw(array $data, array $keys): string
    {
        return implode('&', array_map(fn($k) => $k . '=' . (string) ($data[$k] ?? ''), $keys));
    }

    public function sign(string $raw): string
    {
        return hash_hmac('sha256', $raw, $this->cfg('secret_key'));
    }

    public function create(array $o): array
    {
        $p = [
            'partnerCode' => $this->cfg('partner_code'),
            'accessKey' => $this->cfg('access_key'),
            'requestId' => $o['txnRef'] . '-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'amount' => (int) $o['amount'],
            'orderId' => $o['txnRef'],
            'orderInfo' => $o['info'],
            'redirectUrl' => $o['returnUrl'],
            'ipnUrl' => $o['notifyUrl'],
            'requestType' => $this->cfg('request_type') ?: 'captureWallet',
            'extraData' => '',
        ];
        $raw = self::raw($p, ['accessKey', 'amount', 'extraData', 'ipnUrl', 'orderId', 'orderInfo', 'partnerCode', 'redirectUrl', 'requestId', 'requestType']);
        $body = $p + ['lang' => 'vi', 'autoCapture' => true, 'signature' => $this->sign($raw)];
        unset($body['accessKey']);

        $res = Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asJson()
            ->post(rtrim($this->cfg('endpoint'), '/') . '/v2/gateway/api/create', $body);
        $j = (array) $res->json();
        $code = (string) ($j['resultCode'] ?? 'http_' . $res->status());
        if ($code !== '0' || empty($j['payUrl'])) {
            return ['ok' => false, 'message' => 'MoMo từ chối tạo giao dịch: ' . ($j['message'] ?? $this->describe($code)), 'raw' => ['request' => $body, 'response' => $j]];
        }

        return ['ok' => true, 'url' => (string) $j['payUrl'], 'raw' => ['request' => $body, 'response' => $j]];
    }

    private function verify(array $d): bool
    {
        $given = strtolower((string) ($d['signature'] ?? ''));
        if ($given === '' || !$this->cfg('secret_key')) {
            return false;
        }

        return hash_equals($this->sign(self::raw(['accessKey' => $this->cfg('access_key')] + $d, self::IPN_FIELDS)), $given);
    }

    private function normalize(array $d): array
    {
        $valid = $this->verify($d);
        $code = (string) ($d['resultCode'] ?? '99');

        return [
            'valid' => $valid,
            'txnRef' => isset($d['orderId']) ? (string) $d['orderId'] : null,
            'amount' => isset($d['amount']) ? (int) $d['amount'] : null,
            'success' => $valid && in_array($code, ['0', '9000'], true),
            'providerTxnNo' => isset($d['transId']) ? (string) $d['transId'] : null,
            'code' => $code,
            'bank' => isset($d['payType']) ? 'MOMO-' . strtoupper((string) $d['payType']) : 'MOMO',
            'payload' => $d,
        ];
    }

    public function parseNotify(Request $request): array
    {
        return $this->normalize($request->json()->all() ?: $request->all());
    }

    public function notifyReply(string $outcome): Response
    {
        // MoMo chỉ cần HTTP 204 để biết đối tác đã nhận; chữ ký sai / không tìm thấy đơn vẫn trả 204 để MoMo không gửi lại vô hạn.
        return response()->noContent();
    }

    public function parseReturn(array $query): array
    {
        $n = $this->normalize($query);

        return ['valid' => $n['valid'], 'txnRef' => $n['txnRef'], 'amount' => $n['amount'], 'success' => $n['success'], 'code' => $n['code']];
    }

    public function query(string $txnRef, ?string $createDate): array
    {
        $p = ['accessKey' => $this->cfg('access_key'), 'orderId' => $txnRef, 'partnerCode' => $this->cfg('partner_code'),
            'requestId' => $txnRef . '-q' . substr(bin2hex(random_bytes(3)), 0, 6)];
        $body = ['partnerCode' => $p['partnerCode'], 'requestId' => $p['requestId'], 'orderId' => $txnRef, 'lang' => 'vi',
            'signature' => $this->sign(self::raw($p, ['accessKey', 'orderId', 'partnerCode', 'requestId']))];
        try {
            $j = (array) Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asJson()
                ->post(rtrim($this->cfg('endpoint'), '/') . '/v2/gateway/api/query', $body)->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'amount' => null, 'providerTxnNo' => null, 'code' => 'http_error', 'message' => 'Không gọi được MoMo: ' . $e->getMessage(), 'raw' => ['request' => $body]];
        }
        $code = (string) ($j['resultCode'] ?? '99');
        $status = in_array($code, ['0', '9000'], true) ? 'paid' : (in_array($code, ['1000', '7000', '7002'], true) ? 'pending' : 'failed');
        if (($j['orderId'] ?? $txnRef) !== $txnRef) {
            return ['ok' => false, 'status' => null, 'amount' => null, 'providerTxnNo' => null, 'code' => 'mismatch', 'message' => 'MoMo trả về mã đơn khác.', 'raw' => ['request' => $body, 'response' => $j]];
        }

        return ['ok' => true, 'status' => $status, 'amount' => isset($j['amount']) ? (int) $j['amount'] : null,
            'providerTxnNo' => isset($j['transId']) ? (string) $j['transId'] : null, 'code' => $code,
            'message' => 'MoMo: ' . $this->describe($code), 'raw' => ['request' => $body, 'response' => $j]];
    }

    public function describe(string $code): string
    {
        return self::CODES[$code] ?? 'Mã ' . $code;
    }

    /* ------------------------------------------------------------------ Hoàn tiền (POST /v2/gateway/api/refund) */

    public function supportsRefund(): bool
    {
        return true;
    }

    public function newRefundRef(int $refundId, int $attempt): string
    {
        return 'RF' . $refundId . '-' . $attempt . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    private static function refundResult(string $status, ?string $no, string $code, string $message, array $raw): array
    {
        return ['ok' => $status !== 'unknown', 'status' => $status, 'refundNo' => $no, 'code' => $code, 'message' => $message, 'raw' => $raw];
    }

    /** resultCode của lệnh hoàn / hỏi hoàn → refunded | pending | failed. */
    private static function refundStatus(string $code): string
    {
        return $code === '0' ? 'refunded' : (in_array($code, ['7000', '7002', '9000'], true) ? 'pending' : 'failed');
    }

    /**
     * MoMo hoàn bằng transId của giao dịch gốc (providerTxnNo, lấy từ IPN/query lúc thanh toán). Chữ ký HMAC-SHA256 trên
     * accessKey, amount, description, orderId (mã hoàn của ta), partnerCode, requestId, transId.
     */
    public function refund(array $o): array
    {
        $transId = (string) ($o['providerTxnNo'] ?? '');
        if ($transId === '' || !ctype_digit($transId)) {
            return self::refundResult('failed', null, 'no_txn', 'Đơn không có mã giao dịch MoMo (transId) nên không hoàn tự động được.', []);
        }
        $p = [
            'accessKey' => $this->cfg('access_key'), 'amount' => (int) $o['amount'],
            'description' => mb_substr((string) ($o['reason'] ?: 'Hoan tien don dat tour'), 0, 200),
            'orderId' => $o['refundRef'], 'partnerCode' => $this->cfg('partner_code'),
            'requestId' => $o['refundRef'] . '-' . substr(bin2hex(random_bytes(3)), 0, 6), 'transId' => $transId,
        ];
        $body = ['partnerCode' => $p['partnerCode'], 'orderId' => $p['orderId'], 'requestId' => $p['requestId'], 'amount' => $p['amount'],
            'transId' => (int) $transId, 'lang' => 'vi', 'description' => $p['description'],
            'signature' => $this->sign(self::raw($p, ['accessKey', 'amount', 'description', 'orderId', 'partnerCode', 'requestId', 'transId']))];
        try {
            $res = Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asJson()
                ->post(rtrim($this->cfg('endpoint'), '/') . '/v2/gateway/api/refund', $body);
        } catch (\Throwable $e) {
            // Không biết lệnh đã tới MoMo chưa: không được coi là thất bại để gửi lại (có thể hoàn hai lần)
            return self::refundResult('unknown', null, 'http_error', 'Không nhận được phản hồi từ MoMo: ' . $e->getMessage(), ['request' => $body]);
        }
        $j = (array) $res->json();
        if (!isset($j['resultCode'])) {
            return self::refundResult('unknown', null, 'http_' . $res->status(), 'MoMo trả về phản hồi không đọc được (HTTP ' . $res->status() . ').', ['request' => $body, 'response' => $j]);
        }
        $code = (string) $j['resultCode'];
        $status = self::refundStatus($code);

        return self::refundResult($status, isset($j['transId']) ? (string) $j['transId'] : null, $code,
            'MoMo: ' . ($j['message'] ?? $this->describe($code)), ['request' => $body, 'response' => $j]);
    }

    public function queryRefund(string $refundRef): array
    {
        $p = ['accessKey' => $this->cfg('access_key'), 'orderId' => $refundRef, 'partnerCode' => $this->cfg('partner_code'),
            'requestId' => $refundRef . '-q' . substr(bin2hex(random_bytes(3)), 0, 6)];
        $body = ['partnerCode' => $p['partnerCode'], 'orderId' => $refundRef, 'requestId' => $p['requestId'], 'lang' => 'vi',
            'signature' => $this->sign(self::raw($p, ['accessKey', 'orderId', 'partnerCode', 'requestId']))];
        try {
            $j = (array) Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asJson()
                ->post(rtrim($this->cfg('endpoint'), '/') . '/v2/gateway/api/refund/query', $body)->json();
        } catch (\Throwable $e) {
            return self::refundResult('unknown', null, 'http_error', 'Không gọi được MoMo: ' . $e->getMessage(), ['request' => $body]);
        }
        if (!isset($j['resultCode'])) {
            return self::refundResult('unknown', null, 'empty', 'MoMo không trả kết quả hỏi hoàn.', ['request' => $body, 'response' => $j]);
        }
        $code = (string) $j['resultCode'];
        if ($code === '0') {
            // refundTrans: danh sách giao dịch hoàn của orderId này; có kết quả riêng từng dòng
            $trans = (array) ($j['refundTrans'] ?? []);
            $first = $trans[0] ?? null;
            if (is_array($first)) {
                $tc = (string) ($first['resultCode'] ?? '0');

                return self::refundResult(self::refundStatus($tc), isset($first['transId']) ? (string) $first['transId'] : null, $tc,
                    'MoMo: ' . $this->describe($tc), ['request' => $body, 'response' => $j]);
            }

            return self::refundResult('pending', null, $code, 'MoMo đã nhận yêu cầu hoàn, chưa có giao dịch hoàn.', ['request' => $body, 'response' => $j]);
        }

        return self::refundResult(self::refundStatus($code), null, $code, 'MoMo: ' . ($j['message'] ?? $this->describe($code)), ['request' => $body, 'response' => $j]);
    }
}
