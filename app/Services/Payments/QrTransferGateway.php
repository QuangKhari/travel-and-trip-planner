<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * QR chuyển khoản ngân hàng (chuẩn VietQR / NAPAS 247).
 *   create   không gọi API: dựng ảnh QR có sẵn số tài khoản, số tiền và nội dung = mã đơn (img.vietqr.io). Khách quét bằng app ngân hàng bất kỳ.
 *   webhook  SePay (sepay.vn) theo dõi tài khoản nhận tiền và POST mỗi giao dịch tiền vào; xác thực bằng header "Authorization: Apikey <khóa>".
 *            Tìm mã đơn trong nội dung chuyển khoản, so số tiền, rồi xác nhận đơn. Phản hồi {"success": true} để SePay không gửi lại.
 *   Không có SePay: đơn chờ admin đối chiếu sao kê và bấm "Đã nhận tiền" ở trang chi tiết đơn.
 */
class QrTransferGateway implements PaymentGateway
{
    /** Mã đơn Travela trong nội dung chuyển khoản (ngân hàng có thể đổi chữ hoa / thêm chữ khác quanh nó). */
    public const REF_PATTERN = '/TOUR\d{18}/i';

    private function cfg(string $k): string
    {
        return (string) config('payments.qr.' . $k, '');
    }

    public function code(): string
    {
        return 'qr';
    }

    public function label(): string
    {
        return 'QR chuyển khoản ngân hàng';
    }

    public function enabled(): bool
    {
        return (bool) config('payments.qr.enabled') && $this->cfg('bank_id') && $this->cfg('account_no');
    }

    public function holdMinutes(): int
    {
        return max(10, (int) config('payments.qr.hold_minutes', 30));
    }

    /** Webhook có bật không: không thì đơn QR do admin xác nhận tay. */
    public function autoConfirm(): bool
    {
        return $this->cfg('sepay_api_key') !== '';
    }

    public function imageUrl(int $amount, string $content): string
    {
        return rtrim($this->cfg('image_url'), '/') . '/' . rawurlencode($this->cfg('bank_id')) . '-' . rawurlencode($this->cfg('account_no')) . '-' . ($this->cfg('template') ?: 'compact2') . '.png?'
            . http_build_query(['amount' => $amount, 'addInfo' => $content, 'accountName' => $this->cfg('account_name')], '', '&', PHP_QUERY_RFC3986);
    }

    public function create(array $o): array
    {
        // Nội dung chuyển khoản là mã đơn gốc (không thêm hậu tố lần thử) để khách quét lại QR cũ vẫn khớp
        $content = $o['bookingCode'];

        return ['ok' => true, 'txnRef' => $content, 'url' => null, 'qr' => [
            'image' => $this->imageUrl((int) $o['amount'], $content),
            'bank' => $this->cfg('bank_name') ?: $this->cfg('bank_id'),
            'accountNo' => $this->cfg('account_no'),
            'accountName' => $this->cfg('account_name'),
            'amount' => (int) $o['amount'],
            'content' => $content,
            'auto' => $this->autoConfirm(),
        ], 'raw' => ['content' => $content, 'amount' => (int) $o['amount']]];
    }

    public function parseNotify(Request $request): array
    {
        $key = $this->cfg('sepay_api_key');
        $auth = trim((string) $request->header('Authorization', ''));
        $valid = $key !== '' && hash_equals('Apikey ' . $key, $auth);
        $d = $request->json()->all() ?: $request->all();
        $text = (string) ($d['code'] ?? '') . ' ' . (string) ($d['content'] ?? '') . ' ' . (string) ($d['description'] ?? '');
        $ref = preg_match(self::REF_PATTERN, $text, $m) ? strtoupper($m[0]) : null;
        $in = ($d['transferType'] ?? '') === 'in';

        return [
            'valid' => $valid,
            'txnRef' => $ref,
            'amount' => isset($d['transferAmount']) ? (int) $d['transferAmount'] : null,
            'success' => $valid && $in && $ref !== null,
            'providerTxnNo' => isset($d['id']) ? 'SEPAY-' . $d['id'] : null,
            'code' => $in ? 'in' : 'out',
            'bank' => isset($d['gateway']) ? mb_substr((string) $d['gateway'], 0, 20) : null,
            'payload' => $d,
        ];
    }

    public function notifyReply(string $outcome): Response
    {
        return match ($outcome) {
            'invalid_signature' => response()->json(['success' => false, 'message' => 'Unauthorized'], 401),
            'error' => response()->json(['success' => false], 500),
            // đã nhận (kể cả tiền ra, không khớp đơn, sai số tiền: đã ghi nhật ký cho admin xử lý) -> báo SePay ngừng gửi lại
            default => response()->json(['success' => true]),
        };
    }

    public function parseReturn(array $query): array
    {
        return ['valid' => false, 'txnRef' => null, 'amount' => null, 'success' => false, 'code' => ''];
    }

    public function query(string $txnRef, ?string $createDate): array
    {
        return ['ok' => false, 'status' => null, 'amount' => null, 'providerTxnNo' => null, 'code' => 'manual',
            'message' => 'Chuyển khoản QR không có API hỏi trạng thái: hệ thống chờ thông báo từ ngân hàng (SePay) hoặc admin xác nhận.', 'raw' => []];
    }

    public function describe(string $code): string
    {
        return ['in' => 'Tiền vào tài khoản', 'out' => 'Tiền ra (bỏ qua)', 'manual' => 'Admin xác nhận đã nhận tiền'][$code] ?? 'Mã ' . $code;
    }
}
