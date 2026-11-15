<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Vé điện tử của đơn tour: mã "TV{bookingId}-{8 ký tự ký số}" in thành QR. Ký bằng HMAC với APP_KEY nên không đoán / sửa được,
 * không cần lưu thêm cột: hướng dẫn viên quét -> máy chủ kiểm chữ ký -> điểm danh đúng đơn của đúng ngày khởi hành.
 * Chỉ đơn đã xác nhận (bookingStatus y) hoặc đã thanh toán mới có vé; đơn hủy / quá hạn giữ chỗ thì vé hết hiệu lực.
 */
final class TourTicket
{
    public static function code(object $b): string
    {
        return 'TV' . (int) $b->bookingId . '-' . self::sig((int) $b->bookingId, (int) ($b->availabilityId ?? 0));
    }

    private static function sig(int $bookingId, int $availabilityId): string
    {
        $key = (string) config('app.key');

        return strtoupper(substr(hash_hmac('sha256', 'ticket|' . $bookingId . '|' . $availabilityId, $key), 0, 8));
    }

    /** Đơn có vé không (đã xác nhận / đã thanh toán, chưa hủy, chưa vắng mặt). */
    public static function eligible(object $b): bool
    {
        return empty($b->noShowAt ?? null) && ($b->bookingStatus === 'y' || ($b->bookingStatus === 'n' && ($b->paymentStatus ?? '') === 'y'));
    }

    /**
     * Mã quét được -> đơn (đã kiểm chữ ký và còn hiệu lực), hoặc lý do từ chối.
     * @return array{ok:bool,booking?:object,reason?:string}
     */
    public static function verify(string $raw): array
    {
        $raw = strtoupper(trim($raw));
        if (!preg_match('/^TV(\d{1,10})-([0-9A-F]{8})$/', $raw, $m)) {
            return ['ok' => false, 'reason' => 'Mã không đúng dạng vé Travela.'];
        }
        $b = DB::table('tbl_booking')->where('bookingId', (int) $m[1])->first();
        if (!$b || !hash_equals(self::sig((int) $b->bookingId, (int) ($b->availabilityId ?? 0)), $m[2])) {
            return ['ok' => false, 'reason' => 'Vé không hợp lệ (sai chữ ký).'];
        }
        if (!self::eligible($b)) {
            return ['ok' => false, 'reason' => $b->bookingStatus === 'c' ? 'Đơn đã hủy, vé không còn hiệu lực.' : 'Đơn chưa được xác nhận.'];
        }

        return ['ok' => true, 'booking' => $b];
    }
}
