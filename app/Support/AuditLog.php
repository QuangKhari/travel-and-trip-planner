<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký thao tác quản trị (D-08). Gọi sau khi thao tác THÀNH CÔNG:
 *   AuditLog::record('booking.confirm', 'booking', $id, 'Xác nhận đơn TOUR...', $before, $after);
 * Người thao tác lấy từ phiên admin (adminId). Ghi log lỗi không bao giờ làm hỏng thao tác chính.
 * before/after chỉ nên chứa các cột liên quan; trường nhạy cảm (mật khẩu, PII đã mã hóa) bị lọc bỏ.
 */
final class AuditLog
{
    private const SECRET = ['password', 'passWord', 'requestToken', 'remember_token', 'token'];

    public const ACTIONS = [
        'booking.confirm' => 'Xác nhận đơn',
        'booking.paid' => 'Xác nhận đã thanh toán',
        'booking.finish' => 'Hoàn tất đơn',
        'booking.cancel' => 'Hủy đơn',
        'booking.invoice' => 'Gửi hóa đơn',
        'booking.export' => 'Xuất danh sách đơn',
        'payment.online' => 'Thanh toán trực tuyến',
        'payment.vnpay' => 'Thanh toán VNPay (cũ)',
        'payment.reconcile' => 'Đối soát thanh toán',
        'booking.create' => 'Tạo đơn hộ khách',
        'refund.create' => 'Tạo yêu cầu hoàn tiền',
        'refund.done' => 'Đã hoàn tiền',
        'refund.gateway' => 'Đã hoàn tiền qua API cổng thanh toán',
        'refund.gateway.request' => 'Gửi lệnh hoàn qua cổng thanh toán',
        'tour.create' => 'Tạo tour',
        'tour.publish' => 'Đăng tour',
        'tour.update' => 'Sửa tour',
        'tour.toggle' => 'Ẩn / hiện tour',
        'tour.delete' => 'Xóa / ngừng bán tour',
        'departure.create' => 'Thêm ngày khởi hành',
        'departure.update' => 'Sửa ngày khởi hành',
        'departure.close' => 'Đóng / mở bán ngày khởi hành',
        'departure.delete' => 'Xóa ngày khởi hành',
        'promotion.create' => 'Tạo mã giảm giá',
        'promotion.update' => 'Sửa mã giảm giá',
        'promotion.delete' => 'Xóa mã giảm giá',
        'user.block' => 'Khóa người dùng',
        'user.unblock' => 'Mở khóa người dùng',
        'user.activate' => 'Kích hoạt tài khoản',
        'review.hide' => 'Ẩn đánh giá',
        'review.show' => 'Hiện đánh giá',
        'review.delete' => 'Xóa đánh giá',
        'admin.create' => 'Thêm tài khoản quản trị',
        'admin.delete' => 'Xóa tài khoản quản trị',
        'admin.update' => 'Sửa hồ sơ quản trị',
        'ticket.reply' => 'Trả lời yêu cầu tư vấn',
        'ticket.status' => 'Đổi trạng thái yêu cầu tư vấn',
        'ticket.assign' => 'Nhận xử lý yêu cầu tư vấn',
        'trip.delete' => 'Xóa chuyến đi',
        'system.maintain' => 'Bảo trì hệ thống',
    ];

    public const ENTITIES = [
        'booking' => 'Đơn đặt', 'refund' => 'Hoàn tiền', 'tour' => 'Tour', 'departure' => 'Ngày khởi hành',
        'promotion' => 'Khuyến mãi', 'user' => 'Người dùng', 'review' => 'Đánh giá', 'admin' => 'Quản trị',
        'ticket' => 'Yêu cầu tư vấn', 'trip' => 'Chuyến đi', 'system' => 'Hệ thống',
    ];

    private static ?bool $ready = null;

    public static function record(string $action, string $entity, $entityId = null, ?string $summary = null, $before = null, $after = null): void
    {
        try {
            if (!(self::$ready ??= Schema::hasTable('tbl_audit_log'))) {
                return;
            }
            $adminId = session('adminId');
            $admin = $adminId ? DB::table('tbl_admin')->where('adminId', $adminId)->first(['userName', 'role']) : null;
            $req = request();

            DB::table('tbl_audit_log')->insert([
                'actorType' => $adminId ? 'admin' : 'system',
                'actorId' => $adminId ?: null,
                'actorName' => $admin->userName ?? ($adminId ? null : 'Hệ thống'),
                'actorRole' => $admin->role ?? null,
                'action' => mb_substr($action, 0, 60),
                'entity' => mb_substr($entity, 0, 40),
                'entityId' => $entityId === null ? null : mb_substr((string) $entityId, 0, 40),
                'summary' => $summary === null ? null : mb_substr($summary, 0, 255),
                'before' => self::json($before),
                'after' => self::json($after),
                'ip' => $req?->ip(),
                'userAgent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
                'createdAt' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Chỉ giữ các khóa thay đổi giữa before và after (để nhật ký gọn). */
    public static function diff(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $b = $a = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
            $x = $before[$k] ?? null;
            $y = $after[$k] ?? null;
            if ((string) json_encode($x) !== (string) json_encode($y)) {
                $b[$k] = $x;
                $a[$k] = $y;
            }
        }

        return [$b, $a];
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    private static function json($data): ?string
    {
        if ($data === null) {
            return null;
        }
        $arr = json_decode(json_encode($data), true);
        if (is_array($arr)) {
            foreach (self::SECRET as $k) {
                unset($arr[$k]);
            }
        }

        return json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
