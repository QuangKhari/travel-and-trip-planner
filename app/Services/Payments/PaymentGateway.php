<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Một cổng thanh toán trực tuyến (MoMo, ZaloPay, QR chuyển khoản).
 * Cổng chỉ lo "nói chuyện" với nhà cung cấp: ký / kiểm chữ ký, dựng yêu cầu, đọc phản hồi về một dạng chung.
 * Mọi việc với đơn (giữ chỗ, xác nhận, hoàn tiền, nhật ký) nằm ở OnlinePaymentService, dùng chung cho cả ba cổng.
 */
interface PaymentGateway
{
    /** momo | zalopay | qr */
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
}
