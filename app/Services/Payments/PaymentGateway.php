<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Một cổng thanh toán trực tuyến (VNPay, MoMo, QR chuyển khoản).
 * Cổng chỉ lo "nói chuyện" với nhà cung cấp: ký / kiểm chữ ký, dựng yêu cầu, đọc phản hồi về một dạng chung.
 * Mọi việc với đơn (giữ chỗ, xác nhận, hoàn tiền, nhật ký) nằm ở OnlinePaymentService, dùng chung cho cả ba cổng.
 */
interface PaymentGateway
{
    /** vnpay | momo | qr */
    public function code(): string;

    public function label(): string;

    /** Đủ khóa cấu hình và được bật. */
    public function enabled(): bool;

    /** Số phút giữ chỗ cho đơn chọn cổng này. */
    public function holdMinutes(): int;

    /**
     * Tạo giao dịch.
     * @param array{txnRef:string, amount:int, info:string, returnUrl:string, notifyUrl:string, expireAt:\Carbon\Carbon, bookingCode:string, ip:?string} $o
     * @return array{ok:bool, url?:?string, qr?:?array, message?:string, raw?:array}
     */
    public function create(array $o): array;

    /**
     * Đọc thông báo máy chủ → máy chủ (IPN / callback / webhook).
     * @return array{valid:bool, txnRef:?string, amount:?int, success:bool, providerTxnNo:?string, code:string, bank:?string, payload:array}
     */
    public function parseNotify(Request $request): array;

    /** Phản hồi cho cổng. $outcome: ok | duplicate | invalid_signature | not_found | bad_amount | ignored | error */
    public function notifyReply(string $outcome): Response;

    /**
     * Đọc tham số trên trang khách quay về (chỉ để hiển thị; không đổi trạng thái đơn).
     * @return array{valid:bool, txnRef:?string, amount:?int, success:bool, code:string}
     */
    public function parseReturn(array $query): array;

    /**
     * Hỏi cổng trạng thái giao dịch $txnRef.
     * @return array{ok:bool, status:?string, amount:?int, providerTxnNo:?string, code:string, message:string, raw:array}  status: paid | pending | failed
     */
    public function query(string $txnRef, ?string $createDate): array;

    /** Diễn giải mã kết quả của cổng. */
    public function describe(string $code): string;

    /** Cổng có API hoàn tiền không (VNPay, MoMo có; QR chuyển khoản không: hoàn thủ công). */
    public function supportsRefund(): bool;

    /** Mã giao dịch hoàn riêng cho mỗi lần thử (cổng không nhận trùng). Được lưu vào tbl_refunds TRƯỚC khi gọi cổng. */
    public function newRefundRef(int $refundId, int $attempt): string;

    /**
     * Gửi lệnh hoàn tiền một phần hoặc toàn phần của giao dịch gốc.
     * @param array{txnRef:string, providerTxnNo:?string, amount:int, refundRef:string, reason:string, bookingCode:string, transactionDate:?string, paidAmount:int, createdBy:string} $o
     * @return array{ok:bool, status:string, refundNo:?string, code:string, message:string, raw:array}
     *   status: refunded (tiền đã hoàn) | pending (cổng đang xử lý) | failed (cổng từ chối, được thử lại) | unknown (không biết lệnh có tới cổng không: KHÔNG gửi lại, phải hỏi trạng thái)
     */
    public function refund(array $o): array;

    /**
     * Hỏi trạng thái một lệnh hoàn đã gửi.
     * @return array{ok:bool, status:string, refundNo:?string, code:string, message:string, raw:array}
     */
    public function queryRefund(string $refundRef): array;
}
