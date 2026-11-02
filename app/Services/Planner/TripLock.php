<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;

/**
 * T-40 khóa mềm khi cộng tác (D2).
 *
 * Chuyến có bạn đồng hành: tại một thời điểm chỉ một người giữ quyền sửa. Trình duyệt người đang sửa gia hạn khóa mỗi 60 giây;
 * đóng tab / mất mạng thì khóa tự hết hạn sau TTL (5 phút) và người khác nhận được. Máy chủ chặn lưu (423) khi khóa thuộc người khác.
 * Chuyến một mình (không có thành viên) không cần khóa. Bổ sung cho cơ chế số phiên bản (rev, 409) đã có: khóa tránh hai người
 * cùng sửa, rev bắt trường hợp còn lọt (ví dụ khóa vừa hết hạn).
 * Không dùng WebSocket: trạng thái khóa được hỏi định kỳ (polling) cùng nhịp gia hạn.
 */
class TripLock
{
    public const TTL_SECONDS = 300;
    public const RENEW_SECONDS = 60;

    public function collaborative(int $tripId): bool
    {
        return DB::table('tbl_trip_members')->where('tripId', $tripId)->exists();
    }

    /** Lấy hoặc gia hạn khóa. Trả ['mine'=>bool, 'holder'=>?string, 'expiresAt'=>?string, 'solo'=>bool]. */
    public function acquire(int $tripId, int $userId): array
    {
        if (!$this->collaborative($tripId)) {
            return ['mine' => true, 'solo' => true, 'holder' => null, 'expiresAt' => null];
        }

        return DB::transaction(function () use ($tripId, $userId) {
            $row = DB::table('tbl_trip_locks')->where('tripId', $tripId)->lockForUpdate()->first();
            $exp = now()->addSeconds(self::TTL_SECONDS);
            if (!$row) {
                DB::table('tbl_trip_locks')->insert(['tripId' => $tripId, 'userId' => $userId, 'acquiredAt' => now(), 'expiresAt' => $exp]);

                return $this->state($tripId, $userId);
            }
            if ((int) $row->userId === $userId) {
                DB::table('tbl_trip_locks')->where('tripId', $tripId)->update(['expiresAt' => $exp]);
            } elseif (now()->greaterThanOrEqualTo($row->expiresAt)) {
                DB::table('tbl_trip_locks')->where('tripId', $tripId)->update(['userId' => $userId, 'acquiredAt' => now(), 'expiresAt' => $exp]);
            }

            return $this->state($tripId, $userId);
        });
    }

    public function release(int $tripId, int $userId): void
    {
        DB::table('tbl_trip_locks')->where(['tripId' => $tripId, 'userId' => $userId])->delete();
    }

    /** Trạng thái khóa nhìn từ phía $userId (không đổi gì). */
    public function state(int $tripId, int $userId): array
    {
        $row = DB::table('tbl_trip_locks as l')->join('tbl_users as u', 'u.userId', '=', 'l.userId')
            ->where('l.tripId', $tripId)->where('l.expiresAt', '>', now())->first(['l.userId', 'l.expiresAt', 'u.fullName', 'u.username']);
        if (!$row) {
            return ['mine' => false, 'solo' => false, 'holder' => null, 'expiresAt' => null];
        }

        return [
            'mine' => (int) $row->userId === $userId,
            'solo' => false,
            'holder' => $row->fullName ?: $row->username,
            'expiresAt' => \Carbon\Carbon::parse($row->expiresAt)->toIso8601String(),
        ];
    }

    /**
     * Trước khi lưu: chuyến cộng tác mà khóa đang thuộc người khác -> trả tên người giữ; ngược lại nhận / gia hạn khóa cho $userId, trả null.
     */
    public function blockerFor(int $tripId, int $userId): ?string
    {
        $s = $this->acquire($tripId, $userId);

        return $s['mine'] ? null : ($s['holder'] ?? 'Người khác');
    }
}
