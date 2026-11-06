<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * ZaloPay, cổng thanh toán API v2 (docs.zalopay.vn).
 *   create    POST {endpoint}/v2/create; app_trans_id bắt đầu bằng yymmdd giờ VN; mac = HMAC-SHA256(key1,
 *             app_id|app_trans_id|app_user|amount|app_time|embed_data|item) → order_url. callback_url gửi kèm mỗi đơn.
 *   callback  ZaloPay POST {data, mac, type}; mac = HMAC-SHA256(key2, data). Phản hồi {return_code: 1} = đã nhận,
 *             0 = lỗi tạm thời (ZaloPay gửi lại), -1 = sai chữ ký.
 *   redirect  embed_data.redirecturl nhận appid, apptransid, pmcid, bankcode, amount, discountamount, status, checksum
 *             (HMAC-SHA256 key2 trên các giá trị nối bằng |) — chỉ để hiển thị.
 *   query     POST {endpoint}/v2/query; mac = HMAC-SHA256(key1, app_id|app_trans_id|key1); return_code 1 đã trả, 2 thất bại, 3 đang xử lý.
 */
class ZaloPayGateway implements PaymentGateway
{
    public const CODES = [
        '1' => 'Giao dịch thành công',
        '2' => 'Giao dịch thất bại',
        '3' => 'Giao dịch đang xử lý hoặc khách chưa thanh toán',
        '-1' => 'Sai chữ ký',
        '-49' => 'Khách hủy giao dịch',
    ];

    private function cfg(string $k): string
    {
        return (string) config('payments.zalopay.' . $k, '');
    }

    public function code(): string
    {
        return 'zalopay';
    }

    public function label(): string
    {
        return 'ZaloPay';
    }

    public function enabled(): bool
    {
        return (bool) config('payments.zalopay.enabled') && $this->cfg('app_id') && $this->cfg('key1') && $this->cfg('key2');
    }

    public function holdMinutes(): int
    {
        return max(5, (int) config('payments.zalopay.hold_minutes', 15));
    }

    /** app_trans_id: yymmdd (giờ VN) + "_" + mã đơn; tối đa 40 ký tự. */
    public static function transId(string $txnRef): string
    {
        return mb_substr(now(config('payments.timezone', 'Asia/Ho_Chi_Minh'))->format('ymd') . '_' . $txnRef, 0, 40);
    }

    public function create(array $o): array
    {
        $appTransId = self::transId($o['txnRef']);
        $p = [
            'app_id' => (int) $this->cfg('app_id'),
            'app_user' => 'travela',
            'app_trans_id' => $appTransId,
            'app_time' => (int) round(microtime(true) * 1000),
            'amount' => (int) $o['amount'],
            'item' => '[]',
            'embed_data' => json_encode(['redirecturl' => $o['returnUrl']], JSON_UNESCAPED_SLASHES),
            'description' => mb_substr('Travela - ' . $o['info'], 0, 256),
            'bank_code' => '',
            'callback_url' => $o['notifyUrl'],
            // ZaloPay yêu cầu 300 giây … 30 ngày; khớp với hạn giữ chỗ
            'expire_duration_seconds' => max(300, (int) now()->diffInSeconds($o['expireAt'], false)),
        ];
        $p['mac'] = hash_hmac('sha256', implode('|', [$p['app_id'], $p['app_trans_id'], $p['app_user'], $p['amount'], $p['app_time'], $p['embed_data'], $p['item']]), $this->cfg('key1'));

        $res = Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asForm()->post(rtrim($this->cfg('endpoint'), '/') . '/v2/create', $p);
        $j = (array) $res->json();
        $code = (string) ($j['return_code'] ?? 'http_' . $res->status());
        if ($code !== '1' || empty($j['order_url'])) {
            return ['ok' => false, 'txnRef' => $appTransId, 'message' => 'ZaloPay từ chối tạo đơn: ' . ($j['sub_return_message'] ?? $j['return_message'] ?? $this->describe($code)),
                'raw' => ['request' => $p, 'response' => $j]];
        }

        return ['ok' => true, 'txnRef' => $appTransId, 'url' => (string) $j['order_url'], 'raw' => ['request' => $p, 'response' => $j]];
    }

    public function parseNotify(Request $request): array
    {
        $body = $request->json()->all() ?: $request->all();
        $data = (string) ($body['data'] ?? '');
        $mac = strtolower((string) ($body['mac'] ?? ''));
        $valid = $data !== '' && $mac !== '' && $this->cfg('key2') && hash_equals(hash_hmac('sha256', $data, $this->cfg('key2')), $mac);
        $d = (array) json_decode($data, true);

        return [
            'valid' => $valid,
            'txnRef' => isset($d['app_trans_id']) ? (string) $d['app_trans_id'] : null,
            'amount' => isset($d['amount']) ? (int) $d['amount'] : null,
            'success' => $valid,   // ZaloPay chỉ gọi callback khi đã trừ tiền thành công
            'providerTxnNo' => isset($d['zp_trans_id']) ? (string) $d['zp_trans_id'] : null,
            'code' => $valid ? '1' : '-1',
            'bank' => isset($d['channel']) ? 'ZALOPAY-' . $d['channel'] : 'ZALOPAY',
            'payload' => ['data' => $d, 'type' => $body['type'] ?? null],
        ];
    }

    public function notifyReply(string $outcome): Response
    {
        [$code, $msg] = match ($outcome) {
            'ok', 'duplicate' => [1, 'success'],
            'invalid_signature' => [-1, 'mac not equal'],
            'error' => [0, 'retry'],
            default => [2, $outcome],
        };

        return response()->json(['return_code' => $code, 'return_message' => $msg]);
    }

    public function parseReturn(array $q): array
    {
        $fields = ['appid', 'apptransid', 'pmcid', 'bankcode', 'amount', 'discountamount', 'status'];
        $given = strtolower((string) ($q['checksum'] ?? ''));
        $calc = hash_hmac('sha256', implode('|', array_map(fn($k) => (string) ($q[$k] ?? ''), $fields)), $this->cfg('key2'));
        $valid = $given !== '' && $this->cfg('key2') && hash_equals($calc, $given);
        $code = (string) ($q['status'] ?? '2');

        return ['valid' => $valid, 'txnRef' => isset($q['apptransid']) ? (string) $q['apptransid'] : null,
            'amount' => isset($q['amount']) ? (int) $q['amount'] : null, 'success' => $valid && $code === '1', 'code' => $code];
    }

    public function query(string $txnRef, ?string $createDate): array
    {
        $p = ['app_id' => $this->cfg('app_id'), 'app_trans_id' => $txnRef];
        $p['mac'] = hash_hmac('sha256', $p['app_id'] . '|' . $txnRef . '|' . $this->cfg('key1'), $this->cfg('key1'));
        try {
            $j = (array) Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asForm()
                ->post(rtrim($this->cfg('endpoint'), '/') . '/v2/query', $p)->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'amount' => null, 'providerTxnNo' => null, 'code' => 'http_error', 'message' => 'Không gọi được ZaloPay: ' . $e->getMessage(), 'raw' => ['request' => $p]];
        }
        $code = (string) ($j['return_code'] ?? '');
        if ($code === '') {
            return ['ok' => false, 'status' => null, 'amount' => null, 'providerTxnNo' => null, 'code' => 'empty', 'message' => 'ZaloPay không trả kết quả.', 'raw' => ['request' => $p, 'response' => $j]];
        }
        $status = $code === '1' ? 'paid' : ($code === '3' || !empty($j['is_processing']) ? 'pending' : 'failed');

        return ['ok' => true, 'status' => $status, 'amount' => isset($j['amount']) ? (int) $j['amount'] : null,
            'providerTxnNo' => isset($j['zp_trans_id']) ? (string) $j['zp_trans_id'] : null, 'code' => $code,
            'message' => 'ZaloPay: ' . ($j['sub_return_message'] ?? $j['return_message'] ?? $this->describe($code)), 'raw' => ['request' => $p, 'response' => $j]];
    }

    public function describe(string $code): string
    {
        return self::CODES[$code] ?? 'Mã ' . $code;
    }

    /* ------------------------------------------------------------------ Hoàn tiền (POST /v2/refund) */

    public function supportsRefund(): bool
    {
        return true;
    }

    /** m_refund_id theo định dạng của ZaloPay: yymmdd_appid_xxxxxx (ngày theo giờ VN, phần cuối duy nhất). */
    public function newRefundRef(int $refundId, int $attempt): string
    {
        return now(config('payments.timezone', 'Asia/Ho_Chi_Minh'))->format('ymd') . '_' . $this->cfg('app_id') . '_'
            . $refundId . str_pad((string) $attempt, 2, '0', STR_PAD_LEFT) . random_int(1000, 9999);
    }

    private static function refundResult(string $status, ?string $no, string $code, string $message, array $raw): array
    {
        return ['ok' => $status !== 'unknown', 'status' => $status, 'refundNo' => $no, 'code' => $code, 'message' => $message, 'raw' => $raw];
    }

    /** return_code của refund / query_refund: 1 thành công, 2 thất bại, 3 đang xử lý. */
    private static function refundStatus(string $code): string
    {
        return $code === '1' ? 'refunded' : ($code === '3' ? 'pending' : 'failed');
    }

    /** ZaloPay hoàn bằng zp_trans_id của giao dịch gốc; mac = HMAC-SHA256(key1, app_id|zp_trans_id|amount|description|timestamp). */
    public function refund(array $o): array
    {
        $zp = (string) ($o['providerTxnNo'] ?? '');
        if ($zp === '') {
            return self::refundResult('failed', null, 'no_txn', 'Đơn không có mã giao dịch ZaloPay (zp_trans_id) nên không hoàn tự động được.', []);
        }
        $p = ['app_id' => (int) $this->cfg('app_id'), 'm_refund_id' => $o['refundRef'], 'timestamp' => (int) round(microtime(true) * 1000),
            'zp_trans_id' => $zp, 'amount' => (int) $o['amount'], 'description' => mb_substr((string) ($o['reason'] ?: 'Hoan tien don dat tour'), 0, 200)];
        $p['mac'] = hash_hmac('sha256', implode('|', [$p['app_id'], $p['zp_trans_id'], $p['amount'], $p['description'], $p['timestamp']]), $this->cfg('key1'));
        try {
            $res = Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asForm()->post(rtrim($this->cfg('endpoint'), '/') . '/v2/refund', $p);
        } catch (\Throwable $e) {
            return self::refundResult('unknown', null, 'http_error', 'Không nhận được phản hồi từ ZaloPay: ' . $e->getMessage(), ['request' => $p]);
        }
        $j = (array) $res->json();
        if (!isset($j['return_code'])) {
            return self::refundResult('unknown', null, 'http_' . $res->status(), 'ZaloPay trả về phản hồi không đọc được (HTTP ' . $res->status() . ').', ['request' => $p, 'response' => $j]);
        }
        $code = (string) $j['return_code'];

        return self::refundResult(self::refundStatus($code), isset($j['refund_id']) ? (string) $j['refund_id'] : null, $code,
            'ZaloPay: ' . ($j['sub_return_message'] ?? $j['return_message'] ?? $this->describe($code)), ['request' => $p, 'response' => $j]);
    }

    /** mac = HMAC-SHA256(key1, app_id|m_refund_id|timestamp). */
    public function queryRefund(string $refundRef): array
    {
        $p = ['app_id' => $this->cfg('app_id'), 'm_refund_id' => $refundRef, 'timestamp' => (int) round(microtime(true) * 1000)];
        $p['mac'] = hash_hmac('sha256', $p['app_id'] . '|' . $refundRef . '|' . $p['timestamp'], $this->cfg('key1'));
        try {
            $j = (array) Http::timeout((int) config('payments.timeout', 15))->acceptJson()->asForm()
                ->post(rtrim($this->cfg('endpoint'), '/') . '/v2/query_refund', $p)->json();
        } catch (\Throwable $e) {
            return self::refundResult('unknown', null, 'http_error', 'Không gọi được ZaloPay: ' . $e->getMessage(), ['request' => $p]);
        }
        if (!isset($j['return_code'])) {
            return self::refundResult('unknown', null, 'empty', 'ZaloPay không trả kết quả hỏi hoàn.', ['request' => $p, 'response' => $j]);
        }
        $code = (string) $j['return_code'];

        return self::refundResult(self::refundStatus($code), isset($j['refund_id']) ? (string) $j['refund_id'] : null, $code,
            'ZaloPay: ' . ($j['sub_return_message'] ?? $j['return_message'] ?? $this->describe($code)), ['request' => $p, 'response' => $j]);
    }
}
