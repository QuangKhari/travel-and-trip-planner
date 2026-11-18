<?php

namespace App\Services\Payments;

use App\Services\RefundService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Hoàn tiền tự động qua API cổng thanh toán (VNPay, MoMo). Chỉ chạy khi PAYMENT_REFUND_ENABLED=true; QR chuyển khoản luôn hoàn tay.
 *
 * An toàn tiền thật:
 *   - Mã hoàn của lần thử được LƯU và đánh dấu gatewayStatus = processing TRƯỚC khi gọi cổng, trong transaction có khóa dòng
 *     (bấm hai lần hoặc hai tiến trình không gửi hai lệnh).
 *   - Không biết lệnh đã tới cổng chưa (timeout, phản hồi hỏng) = vẫn "processing": KHÔNG cho gửi lại, chỉ cho hỏi trạng thái
 *     (sync) cho tới khi cổng trả về hoàn xong hoặc báo không có lệnh.
 *   - Admin không ghi nhận tay được khi lệnh còn processing (RefundService::markRefunded).
 *   - Hoàn xong mới đổi tbl_refunds.status = refunded và gửi email khách. Mọi lần gọi ghi vào tbl_payment_logs (kind refund / refund_query).
 *   - Chế độ tự động (PAYMENT_REFUND_AUTO) chỉ thử lần đầu; lần bị cổng từ chối phải có người xử lý.
 */
class GatewayRefundService
{
    public function __construct(private OnlinePaymentService $payments, private RefundService $refunds) {}

    public static function enabled(): bool
    {
        return (bool) config('payments.refund.enabled');
    }

    public static function auto(): bool
    {
        return self::enabled() && (bool) config('payments.refund.auto');
    }

    /** Hồ sơ này có hoàn qua cổng được không. @return array{ok:bool, message:string, gateway:?PaymentGateway, checkout:?object} */
    public function check(object $refund): array
    {
        $no = fn(string $m) => ['ok' => false, 'message' => $m, 'gateway' => null, 'checkout' => null];
        if (!self::enabled()) {
            return $no('Hoàn qua cổng đang tắt (PAYMENT_REFUND_ENABLED).');
        }
        if ($refund->status !== 'pending' || (int) $refund->amount <= 0) {
            return $no('Hồ sơ không có khoản nào cần hoàn.');
        }
        $c = DB::table('tbl_checkout')->where('bookingId', $refund->bookingId)->first();
        $g = $c ? $this->payments->gateway((string) $c->provider) : null;
        if (!$g || !$g->supportsRefund()) {
            return $no('Đơn thanh toán bằng ' . OnlinePaymentService::label($c->provider ?? null) . ' nên hoàn thủ công.');
        }
        if (!$g->enabled()) {
            return $no($g->label() . ' đang tắt, không gọi được API hoàn.');
        }
        $att = $this->payments->paidAttempt((int) $refund->bookingId);
        if (!$att || !$att->providerTxnNo) {
            return $no('Thiếu mã giao dịch của cổng (đơn được xác nhận tay): hoàn thủ công.');
        }
        if ($c->paymentStatus !== 'refund_pending') {
            return $no('Trạng thái thanh toán của đơn không còn chờ hoàn.');
        }
        if ((int) $refund->amount > (int) round((float) $c->amount)) {
            return $no('Số tiền hoàn lớn hơn số tiền đã thanh toán.');
        }
        $left = $att->amount - $this->refundedOnTxn((int) $refund->bookingId);
        if ((int) $refund->amount > $left) {
            return $no('Số hoàn ' . number_format((int) $refund->amount, 0, ',', '.') . ' đ vượt số còn hoàn được qua ' . $g->label() . ' (' . number_format(max(0, $left), 0, ',', '.')
                . ' đ): phần đã thu thêm ngoài cổng phải hoàn thủ công.');
        }

        return ['ok' => true, 'message' => '', 'gateway' => $g, 'checkout' => $c, 'attempt' => $att];
    }

    /** Gửi lệnh hoàn qua cổng cho hồ sơ $refundId. @return array{ok:bool, status:string, message:string} */
    public function process(int $refundId, ?int $adminId, ?string $ip = null): array
    {
        $prep = DB::transaction(function () use ($refundId) {
            $r = DB::table('tbl_refunds')->where('refundId', $refundId)->lockForUpdate()->first();
            if (!$r) {
                return ['ok' => false, 'message' => 'Không tìm thấy yêu cầu hoàn tiền.'];
            }
            if (($r->gatewayStatus ?? null) === 'processing') {
                return ['ok' => false, 'message' => 'Lệnh hoàn đang xử lý tại cổng: bấm “Kiểm tra trạng thái”, không gửi lệnh lần hai.'];
            }
            $chk = $this->check($r);
            if (!$chk['ok']) {
                return ['ok' => false, 'message' => $chk['message']];
            }
            $attempt = (int) ($r->gatewayAttempts ?? 0) + 1;
            if ($attempt > max(1, (int) config('payments.refund.max_attempts', 3))) {
                return ['ok' => false, 'message' => 'Đã thử hoàn qua cổng quá số lần cho phép: hãy hoàn thủ công rồi ghi nhận.'];
            }
            $g = $chk['gateway'];
            $ref = $g->newRefundRef($refundId, $attempt);
            DB::table('tbl_refunds')->where('refundId', $refundId)->update([
                'gateway' => $g->code(), 'gatewayRefundRef' => $ref, 'gatewayStatus' => 'processing', 'gatewayAttempts' => $attempt,
                'gatewayCode' => null, 'gatewayMessage' => null, 'gatewayAt' => now(),
            ]);

            return ['ok' => true, 'r' => $r, 'a' => $chk['attempt'], 'g' => $g, 'ref' => $ref];
        });
        if (!$prep['ok']) {
            return ['ok' => false, 'status' => 'blocked', 'message' => $prep['message']];
        }
        /** @var PaymentGateway $g */
        [$r, $att, $g, $ref] = [$prep['r'], $prep['a'], $prep['g'], $prep['ref']];
        $code = (string) DB::table('tbl_booking')->where('bookingId', $r->bookingId)->value('bookingCode');
        try {
            $res = $g->refund(['txnRef' => (string) $att->txnRef, 'providerTxnNo' => $att->providerTxnNo, 'amount' => (int) $r->amount,
                'refundRef' => $ref, 'reason' => 'Hoan tien don ' . $code, 'bookingCode' => $code] + $this->extra($att, (int) $r->bookingId, $ref, $adminId));
        } catch (\Throwable $e) {
            report($e);
            $res = ['ok' => false, 'status' => 'unknown', 'refundNo' => null, 'code' => 'exception', 'message' => 'Lỗi khi gọi cổng: ' . $e->getMessage(), 'raw' => []];
        }
        $this->log((int) $r->bookingId, $g, 'refund', $ref, $res, $ip);

        return $this->apply($refundId, $g, $res, $adminId, $ref);
    }

    /** Hỏi cổng trạng thái lệnh hoàn đang xử lý. @return array{ok:bool, status:string, message:string} */
    public function sync(int $refundId, ?int $adminId = null, ?string $ip = null): array
    {
        $r = DB::table('tbl_refunds')->where('refundId', $refundId)->first();
        if (!$r || $r->status !== 'pending' || ($r->gatewayStatus ?? null) !== 'processing' || !$r->gatewayRefundRef) {
            return ['ok' => false, 'status' => 'blocked', 'message' => 'Hồ sơ không có lệnh hoàn nào đang xử lý.'];
        }
        $g = $this->payments->gateway((string) $r->gateway);
        if (!$g || !$g->enabled()) {
            return ['ok' => false, 'status' => 'blocked', 'message' => 'Cổng thanh toán đang tắt, chưa hỏi được trạng thái.'];
        }
        try {
            $res = $g->queryRefund((string) $r->gatewayRefundRef);
        } catch (\Throwable $e) {
            report($e);
            $res = ['ok' => false, 'status' => 'unknown', 'refundNo' => null, 'code' => 'exception', 'message' => 'Lỗi khi gọi cổng: ' . $e->getMessage(), 'raw' => []];
        }
        $this->log((int) $r->bookingId, $g, 'refund_query', (string) $r->gatewayRefundRef, $res, $ip);

        return $this->apply($refundId, $g, $res, $adminId, (string) $r->gatewayRefundRef);
    }

    /** Lệnh payments:process-refunds: hỏi lại các lệnh đang xử lý (hoàn khi hủy + hoàn phần dư khi giảm khách). */
    public function syncAll(): int
    {
        $n = 0;
        foreach (DB::table('tbl_refunds')->where('status', 'pending')->where('gatewayStatus', 'processing')->orderBy('refundId')->limit(50)->pluck('refundId') as $id) {
            if ($this->sync((int) $id)['status'] === 'refunded') {
                $n++;
            }
        }
        if (self::adjustReady()) {
            foreach (DB::table('tbl_booking_adjustments')->where('moneyStatus', 'pending_refund')->where('gatewayStatus', 'processing')->orderBy('adjustId')->limit(50)->pluck('adjustId') as $id) {
                if ($this->syncAdjustment((int) $id)['status'] === 'refunded') {
                    $n++;
                }
            }
        }
        if (OnlinePaymentService::attemptsReady()) {
            foreach (DB::table('tbl_payment_attempts')->where('status', 'excess')->where('refundStatus', 'processing')->orderBy('attemptId')->limit(50)->pluck('attemptId') as $id) {
                if ($this->syncExcess((int) $id)['status'] === 'refunded') {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * Lệnh hoàn kẹt ở "đang xử lý" mà cổng không trả lời rõ (vd VNPay khi giao dịch có nhiều lệnh hoàn): nhân viên đối chiếu trên
     * trang quản trị merchant rồi chốt kết quả. 'refunded' cần mã hoàn của cổng; 'failed' mở lại để thử lại / hoàn tay.
     */
    public function resolve(int $refundId, int $adminId, string $outcome, ?string $reference, ?string $note): array
    {
        $r = DB::table('tbl_refunds')->where('refundId', $refundId)->first();
        if (!$r || $r->status !== 'pending' || ($r->gatewayStatus ?? null) !== 'processing') {
            return ['ok' => false, 'message' => 'Hồ sơ không có lệnh hoàn nào đang xử lý.'];
        }
        $label = OnlinePaymentService::label($r->gateway);
        if ($outcome === 'refunded') {
            if (!$reference) {
                return ['ok' => false, 'message' => 'Nhập mã giao dịch hoàn hiển thị trên trang merchant để đối soát.'];
            }
            DB::table('tbl_refunds')->where('refundId', $refundId)->update(['gatewayStatus' => 'succeeded', 'gatewayRefundNo' => mb_substr($reference, 0, 40),
                'gatewayMessage' => mb_substr('Admin đối chiếu trang merchant: đã hoàn. ' . (string) $note, 0, 255)]);
            $done = $this->refunds->markRefunded($refundId, $adminId, 'Hoàn qua ' . $label, $reference, $note ?: 'Đối chiếu trang merchant ' . $label, true);
            if (($done['before']->status ?? null) === 'pending') {
                AuditLog::record('refund.gateway.resolve', 'refund', $refundId, 'Chốt lệnh hoàn qua ' . $label . ' theo trang merchant: đã hoàn (' . $reference . ')');
                $this->refunds->mailRefunded((int) $r->bookingId, $done['after']);
                AdminBadges::forget();
            }

            return ['ok' => $done['ok'], 'message' => $done['ok'] ? 'Đã ghi nhận hoàn theo trang merchant.' : $done['message']];
        }
        DB::table('tbl_refunds')->where('refundId', $refundId)->update(['gatewayStatus' => 'failed',
            'gatewayMessage' => mb_substr('Admin đối chiếu trang merchant: cổng không có / từ chối lệnh. ' . (string) $note, 0, 255)]);
        AuditLog::record('refund.gateway.resolve', 'refund', $refundId, 'Chốt lệnh hoàn qua ' . $label . ' theo trang merchant: không thành công');
        AdminBadges::forget();

        return ['ok' => true, 'message' => 'Đã đánh dấu lệnh hoàn không thành công: có thể thử lại qua cổng hoặc hoàn thủ công.'];
    }

    /** Như resolve() nhưng cho khoản thu trùng. */
    public function resolveExcess(int $attemptId, int $adminId, string $outcome, ?string $reference, ?string $note): array
    {
        $att = DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->first();
        if (!$att || $att->refundStatus !== 'processing') {
            return ['ok' => false, 'message' => 'Khoản này không có lệnh hoàn nào đang xử lý.'];
        }
        if ($outcome === 'refunded') {
            if (!$reference) {
                return ['ok' => false, 'message' => 'Nhập mã giao dịch hoàn hiển thị trên trang merchant để đối soát.'];
            }

            return $this->markExcessRefunded($attemptId, $adminId, 'Hoàn qua ' . OnlinePaymentService::label($att->provider), $reference, true, mb_substr($reference, 0, 40));
        }
        DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->update(['refundStatus' => 'failed', 'updatedAt' => now(),
            'refundMessage' => mb_substr('Admin đối chiếu trang merchant: cổng không có / từ chối lệnh. ' . (string) $note, 0, 255)]);
        AdminBadges::forget();

        return ['ok' => true, 'message' => 'Đã đánh dấu lệnh hoàn không thành công.'];
    }

    /* ------------------------------------------------------------------ Hoàn KHOẢN THU TRÙNG (tbl_payment_attempts.status = excess) */

    /**
     * Khách trả ở hai lần thử (hoặc chuyển khoản QR hai lần): lần thứ hai được ghi là excess kèm yêu cầu hoàn (refundStatus = pending).
     * Không tự hoàn: nhân viên DUYỆT bằng nút "Hoàn qua cổng" (VNPay / MoMo, hoàn toàn phần đúng giao dịch thu trùng)
     * hoặc chuyển khoản tay rồi "Ghi nhận đã hoàn".
     * @return array{ok:bool, message:string, gateway:?PaymentGateway}
     */
    public function checkExcess(object $att): array
    {
        $no = fn(string $m) => ['ok' => false, 'message' => $m, 'gateway' => null];
        if ($att->status !== 'excess' || !in_array($att->refundStatus, ['pending', 'failed'], true)) {
            return $no('Khoản này không còn chờ hoàn.');
        }
        if (!self::enabled()) {
            return $no('Hoàn qua cổng đang tắt (PAYMENT_REFUND_ENABLED): chuyển khoản tay rồi ghi nhận.');
        }
        $g = $this->payments->gateway((string) $att->provider);
        if (!$g || !$g->supportsRefund()) {
            return $no(OnlinePaymentService::label($att->provider) . ' không có API hoàn: chuyển khoản tay rồi ghi nhận.');
        }
        if (!$g->enabled()) {
            return $no($g->label() . ' đang tắt, không gọi được API hoàn.');
        }
        if (!$att->providerTxnNo) {
            return $no('Thiếu mã giao dịch của cổng cho khoản thu trùng: hoàn thủ công.');
        }

        return ['ok' => true, 'message' => '', 'gateway' => $g];
    }

    public function processExcess(int $attemptId, ?int $adminId, ?string $ip = null): array
    {
        $prep = DB::transaction(function () use ($attemptId) {
            $att = DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->lockForUpdate()->first();
            if (!$att) {
                return ['ok' => false, 'message' => 'Không tìm thấy khoản thu trùng.'];
            }
            if ($att->refundStatus === 'processing') {
                return ['ok' => false, 'message' => 'Lệnh hoàn đang xử lý tại cổng: bấm “Kiểm tra trạng thái”, không gửi lệnh lần hai.'];
            }
            $chk = $this->checkExcess($att);
            if (!$chk['ok']) {
                return ['ok' => false, 'message' => $chk['message']];
            }
            $n = (int) $att->refundAttempts + 1;
            if ($n > max(1, (int) config('payments.refund.max_attempts', 3))) {
                return ['ok' => false, 'message' => 'Đã thử hoàn qua cổng quá số lần cho phép: hãy hoàn thủ công rồi ghi nhận.'];
            }
            $g = $chk['gateway'];
            // khác không gian mã với tbl_refunds (refundId) và điều chỉnh (900000000 + adjustId)
            $ref = $g->newRefundRef(800000000 + $attemptId, $n);
            DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->update(['refundStatus' => 'processing', 'refundRef' => $ref, 'refundAttempts' => $n,
                'refundCode' => null, 'refundMessage' => null, 'refundAt' => now(), 'updatedAt' => now()]);

            return ['ok' => true, 'att' => $att, 'g' => $g, 'ref' => $ref];
        });
        if (!$prep['ok']) {
            return ['ok' => false, 'status' => 'blocked', 'message' => $prep['message']];
        }
        [$att, $g, $ref] = [$prep['att'], $prep['g'], $prep['ref']];
        $code = (string) DB::table('tbl_booking')->where('bookingId', $att->bookingId)->value('bookingCode');
        try {
            $res = $g->refund(['txnRef' => (string) $att->txnRef, 'providerTxnNo' => $att->providerTxnNo, 'amount' => (int) $att->amount, 'refundRef' => $ref,
                'reason' => 'Hoan tien thu trung don ' . $code, 'bookingCode' => $code, 'transactionDate' => $att->createDate, 'paidAmount' => (int) $att->amount,
                'priorRefunded' => 0, 'createdBy' => $adminId ? 'admin' . $adminId : 'travela-auto']);
        } catch (\Throwable $e) {
            report($e);
            $res = ['ok' => false, 'status' => 'unknown', 'refundNo' => null, 'code' => 'exception', 'message' => 'Lỗi khi gọi cổng: ' . $e->getMessage(), 'raw' => []];
        }
        $this->log((int) $att->bookingId, $g, 'refund', $ref, $res, $ip);

        return $this->applyExcess($attemptId, $g, $res, $adminId, $ref);
    }

    public function syncExcess(int $attemptId, ?int $adminId = null, ?string $ip = null): array
    {
        $att = DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->first();
        if (!$att || $att->refundStatus !== 'processing' || !$att->refundRef) {
            return ['ok' => false, 'status' => 'blocked', 'message' => 'Khoản này không có lệnh hoàn nào đang xử lý.'];
        }
        $g = $this->payments->gateway((string) $att->provider);
        if (!$g || !$g->enabled()) {
            return ['ok' => false, 'status' => 'blocked', 'message' => 'Cổng thanh toán đang tắt, chưa hỏi được trạng thái.'];
        }
        try {
            $res = $g->queryRefund((string) $att->refundRef);
        } catch (\Throwable $e) {
            report($e);
            $res = ['ok' => false, 'status' => 'unknown', 'refundNo' => null, 'code' => 'exception', 'message' => 'Lỗi khi gọi cổng: ' . $e->getMessage(), 'raw' => []];
        }
        $this->log((int) $att->bookingId, $g, 'refund_query', (string) $att->refundRef, $res, $ip);

        return $this->applyExcess($attemptId, $g, $res, $adminId, (string) $att->refundRef);
    }

    /** Ghi nhận đã hoàn khoản thu trùng (tay, hoặc từ kết quả cổng khi $viaGateway). */
    public function markExcessRefunded(int $attemptId, ?int $adminId, string $method, ?string $reference, bool $viaGateway = false, ?string $refundNo = null): array
    {
        $res = DB::transaction(function () use ($attemptId, $adminId, $method, $reference, $viaGateway, $refundNo) {
            $att = DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->lockForUpdate()->first();
            if (!$att || $att->status !== 'excess') {
                return ['ok' => false, 'message' => 'Không tìm thấy khoản thu trùng.'];
            }
            if ($att->refundStatus === 'refunded') {
                return ['ok' => true, 'message' => 'Khoản này đã được ghi nhận hoàn trước đó.', 'new' => false, 'att' => $att];
            }
            if (!$viaGateway && $att->refundStatus === 'processing') {
                return ['ok' => false, 'message' => 'Lệnh hoàn qua cổng đang xử lý: bấm “Kiểm tra trạng thái”, không ghi nhận tay để tránh hoàn hai lần.'];
            }
            DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->update(['refundStatus' => 'refunded', 'refundMethod' => mb_substr($method, 0, 40),
                'refundReference' => $reference ? mb_substr($reference, 0, 80) : null, 'refundNo' => $refundNo, 'refundBy' => $adminId, 'refundAt' => now(), 'updatedAt' => now()]);

            return ['ok' => true, 'message' => 'Đã ghi nhận hoàn khoản thu trùng.', 'new' => true, 'att' => $att];
        });
        if (!empty($res['new'])) {
            $att = $res['att'];
            app(DoubleEntryLedger::class)->excessRefund((int) $att->bookingId, $attemptId, $viaGateway ? (string) $att->provider : $method, (int) $att->amount);
            $bk = (string) DB::table('tbl_booking')->where('bookingId', $att->bookingId)->value('bookingCode');
            AuditLog::record('payment.excess_refund', 'booking', (int) $att->bookingId, 'Đã hoàn khoản thu trùng ' . number_format((int) $att->amount, 0, ',', '.') . ' đ của đơn ' . $bk
                . ' (' . $method . ($reference ? ', ' . $reference : '') . ')' . ($viaGateway && !$adminId ? ' (tự động)' : ''));
            AdminBadges::forget();
        }

        return ['ok' => $res['ok'], 'message' => $res['message']];
    }

    private function applyExcess(int $attemptId, PaymentGateway $g, array $res, ?int $adminId, string $ref): array
    {
        $status = (string) $res['status'];
        $info = ['refundCode' => mb_substr((string) $res['code'], 0, 12), 'refundMessage' => mb_substr((string) $res['message'], 0, 255), 'updatedAt' => now()];
        if ($status === 'refunded') {
            $no = $res['refundNo'] ? mb_substr((string) $res['refundNo'], 0, 40) : null;
            DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->update($info);
            $this->markExcessRefunded($attemptId, $adminId, 'Hoàn qua ' . $g->label(), $no ?: $ref, true, $no);

            return ['ok' => true, 'status' => 'refunded', 'message' => 'Đã hoàn khoản thu trùng qua ' . $g->label() . ($no ? ' (mã ' . $no . ')' : '') . '.'];
        }
        if ($status === 'failed') {
            DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->update($info + ['refundStatus' => 'failed']);
            AdminBadges::forget();

            return ['ok' => false, 'status' => 'failed', 'message' => $res['message'] . ' Bạn có thể thử lại hoặc hoàn thủ công.'];
        }
        DB::table('tbl_payment_attempts')->where('attemptId', $attemptId)->update($info + ['refundStatus' => 'processing']);

        return ['ok' => true, 'status' => $status === 'unknown' ? 'unknown' : 'pending',
            'message' => $status === 'unknown' ? 'Chưa biết cổng đã nhận lệnh chưa (' . $res['message'] . '). Đừng hoàn tay; bấm “Kiểm tra trạng thái” sau ít phút.'
                : $g->label() . ' đang xử lý lệnh hoàn. Bấm “Kiểm tra trạng thái” sau ít phút.'];
    }

    /**
     * Thông tin thêm cổng cần cho lệnh hoàn: ngày tạo của CHÍNH lần thử đã trả (VNPay), số tiền của giao dịch đó và số đã hoàn
     * trước trên giao dịch đó (VNPay chọn hoàn toàn phần 02 chỉ khi chưa hoàn lần nào và hoàn đủ), người tạo.
     */
    private function extra(object $att, int $bookingId, string $ref, ?int $adminId): array
    {
        return ['transactionDate' => $att->createDate ?? null, 'paidAmount' => (int) $att->amount,
            'priorRefunded' => $this->refundedOnTxn($bookingId, $ref),
            'createdBy' => $adminId ? 'admin' . $adminId : 'travela-auto'];
    }

    /**
     * Tiền đã hoàn / đang hoàn QUA CỔNG trên giao dịch thanh toán của đơn (hoàn khi hủy + hoàn phần dư), trừ lệnh $exceptRef.
     * Dùng làm trần: tổng hoàn qua cổng không vượt số tiền của chính giao dịch đó.
     */
    public function refundedOnTxn(int $bookingId, ?string $exceptRef = null): int
    {
        $sum = (int) DB::table('tbl_refunds')->where('bookingId', $bookingId)->whereNotNull('gateway')->whereIn('gatewayStatus', ['processing', 'succeeded'])
            ->when($exceptRef, fn($q) => $q->where('gatewayRefundRef', '!=', $exceptRef))->sum('amount');
        if (self::adjustReady()) {
            $sum += (int) DB::table('tbl_booking_adjustments')->where('bookingId', $bookingId)->whereNotNull('gateway')->whereIn('gatewayStatus', ['processing', 'succeeded'])
                ->when($exceptRef, fn($q) => $q->where('gatewayRefundRef', '!=', $exceptRef))->sum('settleAmount');
        }

        return $sum;
    }

    public static function adjustReady(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('tbl_booking_adjustments', 'gatewayStatus');
    }

    /* ------------------------------------------------------------------ Hoàn phần dư khi giảm số khách (tbl_booking_adjustments) */

    /** @return array{ok:bool, message:string, gateway:?PaymentGateway, checkout:?object} */
    public function checkAdjustment(object $a): array
    {
        $no = fn(string $m) => ['ok' => false, 'message' => $m, 'gateway' => null, 'checkout' => null];
        if (!self::enabled() || !self::adjustReady()) {
            return $no('Hoàn qua cổng đang tắt (PAYMENT_REFUND_ENABLED) hoặc chưa chạy migrate.');
        }
        if ($a->moneyStatus !== 'pending_refund' || (int) $a->settleAmount <= 0) {
            return $no('Khoản này không còn chờ hoàn.');
        }
        $c = DB::table('tbl_checkout')->where('bookingId', $a->bookingId)->first();
        $g = $c ? $this->payments->gateway((string) $c->provider) : null;
        if (!$g || !$g->supportsRefund()) {
            return $no('Đơn thanh toán bằng ' . OnlinePaymentService::label($c->provider ?? null) . ' nên hoàn thủ công.');
        }
        if (!$g->enabled()) {
            return $no($g->label() . ' đang tắt, không gọi được API hoàn.');
        }
        $att = $this->payments->paidAttempt((int) $a->bookingId);
        if (!$att || !$att->providerTxnNo) {
            return $no('Thiếu mã giao dịch của cổng (đơn được xác nhận tay): hoàn thủ công.');
        }
        if ((int) $a->settleAmount > $att->amount - $this->refundedOnTxn((int) $a->bookingId)) {
            return $no('Số hoàn vượt số còn hoàn được qua ' . $g->label() . ': hoàn thủ công.');
        }
        if (!in_array($c->paymentStatus, ['y'], true)) {
            return $no('Đơn không còn ở trạng thái đã thanh toán.');
        }
        $led = app(PaymentLedger::class)->forBooking((int) $a->bookingId);
        // sổ chung (đã gồm khoản này): tổng hoàn hai sổ không được vượt số đã thu
        if ($led['adjustOut'] + $led['refundOut'] > $led['captured']) {
            return $no('Số tiền hoàn vượt số còn hoàn được của đơn.');
        }

        return ['ok' => true, 'message' => '', 'gateway' => $g, 'checkout' => $c, 'attempt' => $att];
    }

    /** Gửi lệnh hoàn phần dư cho khoản điều chỉnh $adjustId. */
    public function processAdjustment(int $adjustId, ?int $adminId, ?string $ip = null): array
    {
        $prep = DB::transaction(function () use ($adjustId) {
            $a = DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->lockForUpdate()->first();
            if (!$a) {
                return ['ok' => false, 'message' => 'Không tìm thấy khoản điều chỉnh.'];
            }
            if (($a->gatewayStatus ?? null) === 'processing') {
                return ['ok' => false, 'message' => 'Lệnh hoàn đang xử lý tại cổng: bấm “Kiểm tra trạng thái”, không gửi lệnh lần hai.'];
            }
            $chk = $this->checkAdjustment($a);
            if (!$chk['ok']) {
                return ['ok' => false, 'message' => $chk['message']];
            }
            $attempt = (int) ($a->gatewayAttempts ?? 0) + 1;
            if ($attempt > max(1, (int) config('payments.refund.max_attempts', 3))) {
                return ['ok' => false, 'message' => 'Đã thử hoàn qua cổng quá số lần cho phép: hãy hoàn thủ công rồi ghi nhận.'];
            }
            $g = $chk['gateway'];
            // khác không gian mã với tbl_refunds: 900000000 + adjustId
            $ref = $g->newRefundRef(900000000 + $adjustId, $attempt);
            DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->update([
                'gateway' => $g->code(), 'gatewayRefundRef' => $ref, 'gatewayStatus' => 'processing', 'gatewayAttempts' => $attempt,
                'gatewayCode' => null, 'gatewayMessage' => null, 'gatewayAt' => now(),
            ]);

            return ['ok' => true, 'a' => $a, 'att' => $chk['attempt'], 'g' => $g, 'ref' => $ref];
        });
        if (!$prep['ok']) {
            return ['ok' => false, 'status' => 'blocked', 'message' => $prep['message']];
        }
        [$a, $att, $g, $ref] = [$prep['a'], $prep['att'], $prep['g'], $prep['ref']];
        $code = (string) DB::table('tbl_booking')->where('bookingId', $a->bookingId)->value('bookingCode');
        try {
            $res = $g->refund(['txnRef' => (string) $att->txnRef, 'providerTxnNo' => $att->providerTxnNo, 'amount' => (int) $a->settleAmount,
                'refundRef' => $ref, 'reason' => 'Hoan phan du giam khach don ' . $code, 'bookingCode' => $code] + $this->extra($att, (int) $a->bookingId, $ref, $adminId));
        } catch (\Throwable $e) {
            report($e);
            $res = ['ok' => false, 'status' => 'unknown', 'refundNo' => null, 'code' => 'exception', 'message' => 'Lỗi khi gọi cổng: ' . $e->getMessage(), 'raw' => []];
        }
        $this->log((int) $a->bookingId, $g, 'refund', $ref, $res, $ip);

        return $this->applyAdjustment($adjustId, $g, $res, $adminId, $ref);
    }

    public function syncAdjustment(int $adjustId, ?int $adminId = null, ?string $ip = null): array
    {
        $a = self::adjustReady() ? DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->first() : null;
        if (!$a || $a->moneyStatus !== 'pending_refund' || ($a->gatewayStatus ?? null) !== 'processing' || !$a->gatewayRefundRef) {
            return ['ok' => false, 'status' => 'blocked', 'message' => 'Khoản này không có lệnh hoàn nào đang xử lý.'];
        }
        $g = $this->payments->gateway((string) $a->gateway);
        if (!$g || !$g->enabled()) {
            return ['ok' => false, 'status' => 'blocked', 'message' => 'Cổng thanh toán đang tắt, chưa hỏi được trạng thái.'];
        }
        try {
            $res = $g->queryRefund((string) $a->gatewayRefundRef);
        } catch (\Throwable $e) {
            report($e);
            $res = ['ok' => false, 'status' => 'unknown', 'refundNo' => null, 'code' => 'exception', 'message' => 'Lỗi khi gọi cổng: ' . $e->getMessage(), 'raw' => []];
        }
        $this->log((int) $a->bookingId, $g, 'refund_query', (string) $a->gatewayRefundRef, $res, $ip);

        return $this->applyAdjustment($adjustId, $g, $res, $adminId, (string) $a->gatewayRefundRef);
    }

    private function applyAdjustment(int $adjustId, PaymentGateway $g, array $res, ?int $adminId, string $ref): array
    {
        $status = (string) $res['status'];
        $info = ['gatewayCode' => mb_substr((string) $res['code'], 0, 12), 'gatewayMessage' => mb_substr((string) $res['message'], 0, 255)];
        if ($status === 'refunded') {
            $no = $res['refundNo'] ? mb_substr((string) $res['refundNo'], 0, 40) : null;
            DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->update($info + ['gatewayStatus' => 'succeeded', 'gatewayRefundNo' => $no]);
            $done = app(\App\Services\BookingAdjustmentService::class)->markRefunded($adjustId, (int) ($adminId ?? 0), 'Hoàn qua ' . $g->label(), $no ?: $ref);
            if ($done['ok']) {
                AuditLog::record('adjust.gateway', 'booking_adjustment', $adjustId, 'Hoàn phần dư qua API ' . $g->label() . ($adminId ? '' : ' (tự động)'), ['moneyStatus' => 'pending_refund'], ['moneyStatus' => 'refunded', 'refundNo' => $no]);
                AdminBadges::forget();
            }

            return ['ok' => true, 'status' => 'refunded', 'message' => 'Đã hoàn phần dư qua ' . $g->label() . ($no ? ' (mã ' . $no . ')' : '') . '.'];
        }
        if ($status === 'failed') {
            DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->update($info + ['gatewayStatus' => 'failed']);

            return ['ok' => false, 'status' => 'failed', 'message' => $res['message'] . ' Bạn có thể thử lại hoặc hoàn thủ công.'];
        }
        DB::table('tbl_booking_adjustments')->where('adjustId', $adjustId)->update($info + ['gatewayStatus' => 'processing']);

        return ['ok' => true, 'status' => $status === 'unknown' ? 'unknown' : 'pending',
            'message' => $status === 'unknown' ? 'Chưa biết cổng đã nhận lệnh chưa (' . $res['message'] . '). Đừng hoàn tay; bấm “Kiểm tra trạng thái” sau ít phút.'
                : $g->label() . ' đang xử lý lệnh hoàn. Bấm “Kiểm tra trạng thái” sau ít phút.'];
    }

    /** Chế độ tự động: gửi lệnh hoàn lần đầu cho hồ sơ chờ hoàn đủ điều kiện sau một khoảng chờ. */
    public function autoProcess(): int
    {
        if (!self::auto()) {
            return 0;
        }
        $delay = max(0, (int) config('payments.refund.auto_delay_minutes', 10));
        $n = 0;
        $ids = DB::table('tbl_refunds')->where('status', 'pending')->whereNull('gatewayStatus')->where('amount', '>', 0)
            ->where('createdAt', '<=', now()->subMinutes($delay))->orderBy('refundId')->limit(20)->pluck('refundId');
        foreach ($ids as $id) {
            if ($this->process((int) $id, null)['status'] === 'refunded') {
                $n++;
            }
        }
        if (self::adjustReady()) {
            $adj = DB::table('tbl_booking_adjustments')->where('moneyStatus', 'pending_refund')->whereNull('gatewayStatus')->where('settleAmount', '>', 0)
                ->where('createdAt', '<=', now()->subMinutes($delay))->orderBy('adjustId')->limit(20)->pluck('adjustId');
            foreach ($adj as $id) {
                if ($this->processAdjustment((int) $id, null)['status'] === 'refunded') {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Áp kết quả của cổng lên hồ sơ. */
    private function apply(int $refundId, PaymentGateway $g, array $res, ?int $adminId, string $ref): array
    {
        $status = (string) $res['status'];
        $info = ['gatewayCode' => mb_substr((string) $res['code'], 0, 12), 'gatewayMessage' => mb_substr((string) $res['message'], 0, 255)];
        if ($status === 'refunded') {
            $no = $res['refundNo'] ? mb_substr((string) $res['refundNo'], 0, 40) : null;
            DB::table('tbl_refunds')->where('refundId', $refundId)->update($info + ['gatewayStatus' => 'succeeded', 'gatewayRefundNo' => $no]);
            $done = $this->refunds->markRefunded($refundId, $adminId, 'Hoàn qua ' . $g->label(), $no ?: $ref, 'Hoàn tự động qua API ' . $g->label(), true);
            if (($done['before']->status ?? null) === 'pending') {
                $after = $done['after'];
                $bk = (string) DB::table('tbl_booking')->where('bookingId', $after->bookingId)->value('bookingCode');
                AuditLog::record(
                    'refund.gateway',
                    'refund',
                    $refundId,
                    'Đã hoàn ' . number_format((float) $after->amount, 0, ',', '.') . ' đ cho đơn ' . $bk . ' qua API ' . $g->label() . ($adminId ? '' : ' (tự động)'),
                    ['status' => 'pending'],
                    ['status' => 'refunded', 'gateway' => $g->code(), 'refundNo' => $no],
                );
                $this->refunds->mailRefunded((int) $after->bookingId, $after);
                AdminBadges::forget();
            }

            return ['ok' => true, 'status' => 'refunded', 'message' => 'Đã hoàn tiền qua ' . $g->label() . ($no ? ' (mã ' . $no . ')' : '') . '.'];
        }
        if ($status === 'failed') {
            DB::table('tbl_refunds')->where('refundId', $refundId)->update($info + ['gatewayStatus' => 'failed']);
            AdminBadges::forget();

            return ['ok' => false, 'status' => 'failed', 'message' => $res['message'] . ' Bạn có thể thử lại hoặc hoàn thủ công.'];
        }
        // pending hoặc unknown: giữ processing, chỉ cho hỏi trạng thái
        DB::table('tbl_refunds')->where('refundId', $refundId)->update($info + ['gatewayStatus' => 'processing']);

        return ['ok' => true, 'status' => $status === 'unknown' ? 'unknown' : 'pending',
            'message' => $status === 'unknown'
                ? 'Chưa biết cổng đã nhận lệnh chưa (' . $res['message'] . '). Đừng hoàn tay; bấm “Kiểm tra trạng thái” sau ít phút.'
                : $g->label() . ' đang xử lý lệnh hoàn. Bấm “Kiểm tra trạng thái” sau ít phút.'];
    }

    private function log(int $bookingId, PaymentGateway $g, string $kind, string $ref, array $res, ?string $ip): void
    {
        try {
            $payload = (array) ($res['raw'] ?? []);
            array_walk_recursive($payload, function (&$v, $k) {
                if (in_array($k, ['signature', 'mac', 'checksum', 'accessKey'], true)) {
                    $v = '***';
                }
            });
            DB::table('tbl_payment_logs')->insert([
                'bookingId' => $bookingId, 'provider' => mb_substr($g->code(), 0, 12), 'kind' => $kind, 'txnRef' => mb_substr($ref, 0, 60),
                'result' => mb_substr((string) $res['status'] . '/' . (string) $res['code'], 0, 40), 'validSignature' => null,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'ip' => $ip, 'createdAt' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
