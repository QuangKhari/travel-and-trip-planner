<?php

namespace App\Services\Planner;

use App\Services\Content\TripTray;
use Illuminate\Support\Facades\DB;

/**
 * Chia sẻ chuyến đi theo tài khoản: chủ chuyến mời bạn bằng email, mỗi người một quyền (chỉ xem / chỉnh sửa).
 * Người được mời thấy chuyến trong "Chuyến đi của tôi". Chủ chuyến thu hồi hoặc đổi quyền bất cứ lúc nào;
 * thành viên tự rời được. Chỉ mời được người ĐÃ có tài khoản Travela (chưa có thì dùng liên kết xem).
 */
final class TripSharing
{
    public const ROLES = ['view', 'edit'];
    public const MAX_MEMBERS = 10;

    public static function canEdit(?string $role): bool
    {
        return $role === 'owner' || $role === 'edit';
    }

    /**
     * Quyền của người dùng với chuyến: owner | edit | view; null nếu không có quyền.
     *
     * @return array{trip:object,role:string}|null
     */
    public function access(int $userId, int $tripId): ?array
    {
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->where('title', '!=', TripTray::TITLE)->first();
        if (!$trip) {
            return null;
        }
        if ((int) $trip->userId === $userId) {
            return ['trip' => $trip, 'role' => 'owner'];
        }
        $role = DB::table('tbl_trip_members')->where(['tripId' => $tripId, 'userId' => $userId])->value('role');

        return $role ? ['trip' => $trip, 'role' => (string) $role] : null;
    }

    /** @return array{trip:object,role:string}|null  chỉ khi được phép sửa */
    public function editable(int $userId, int $tripId): ?array
    {
        $a = $this->access($userId, $tripId);

        return $a && self::canEdit($a['role']) ? $a : null;
    }

    /**
     * Danh sách người trong chuyến. Email chỉ hiện cho chủ chuyến.
     *
     * @return list<array{userId:int,name:string,email:?string,role:string,isOwner:bool}>
     */
    public function members(object $trip, bool $showEmail): array
    {
        $owner = DB::table('tbl_users')->where('userId', $trip->userId)->first(['userId', 'fullName', 'email']);
        $out = [];
        if ($owner) {
            $out[] = ['userId' => (int) $owner->userId, 'name' => (string) $owner->fullName, 'email' => $showEmail ? $owner->email : null, 'role' => 'owner', 'isOwner' => true];
        }
        foreach (DB::table('tbl_trip_members as m')->join('tbl_users as u', 'u.userId', '=', 'm.userId')->where('m.tripId', $trip->tripId)
            ->orderBy('m.memberId')->get(['u.userId', 'u.fullName', 'u.email', 'm.role']) as $m) {
            $out[] = ['userId' => (int) $m->userId, 'name' => (string) $m->fullName, 'email' => $showEmail ? $m->email : null, 'role' => (string) $m->role, 'isOwner' => false];
        }

        return $out;
    }

    /** @return array{ok:bool,message:string} */
    public function invite(int $ownerId, int $tripId, string $email, string $role): array
    {
        $trip = DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $ownerId])->where('title', '!=', TripTray::TITLE)->first();
        if (!$trip) {
            return ['ok' => false, 'message' => 'Chỉ chủ chuyến đi mới mời được bạn đồng hành.'];
        }
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, self::ROLES, true)) {
            return ['ok' => false, 'message' => 'Email hoặc quyền không hợp lệ.'];
        }
        $user = DB::table('tbl_users')->whereRaw('LOWER(email) = ?', [$email])->first(['userId', 'fullName', 'isActive']);
        if (!$user || ($user->isActive ?? 'y') === 'n') {
            return ['ok' => false, 'message' => 'Chưa có tài khoản Travela dùng email này. Bạn có thể gửi liên kết xem chuyến đi cho họ.'];
        }
        if ((int) $user->userId === $ownerId) {
            return ['ok' => false, 'message' => 'Bạn là chủ chuyến đi này rồi.'];
        }
        if (DB::table('tbl_trip_members')->where('tripId', $tripId)->count() >= self::MAX_MEMBERS && !DB::table('tbl_trip_members')->where(['tripId' => $tripId, 'userId' => $user->userId])->exists()) {
            return ['ok' => false, 'message' => 'Mỗi chuyến đi mời tối đa ' . self::MAX_MEMBERS . ' người.'];
        }
        DB::table('tbl_trip_members')->updateOrInsert(['tripId' => $tripId, 'userId' => (int) $user->userId], ['role' => $role, 'invitedBy' => $ownerId, 'createdAt' => now()]);

        return ['ok' => true, 'message' => 'Đã mời ' . $user->fullName . ' với quyền ' . ($role === 'edit' ? 'chỉnh sửa' : 'chỉ xem') . '.'];
    }

    public function setRole(int $ownerId, int $tripId, int $userId, string $role): bool
    {
        if (!in_array($role, self::ROLES, true) || !DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $ownerId])->exists()) {
            return false;
        }

        return DB::table('tbl_trip_members')->where(['tripId' => $tripId, 'userId' => $userId])->update(['role' => $role]) > 0;
    }

    /** Chủ chuyến gỡ bất kỳ ai; thành viên chỉ tự rời. */
    public function remove(int $actorId, int $tripId, int $userId): bool
    {
        $isOwner = DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $actorId])->exists();
        if (!$isOwner && $actorId !== $userId) {
            return false;
        }

        return DB::table('tbl_trip_members')->where(['tripId' => $tripId, 'userId' => $userId])->delete() > 0;
    }

    /** Chuyến người khác mời tôi vào, kèm quyền và tên chủ chuyến. */
    public function sharedWithMe(int $userId): array
    {
        return DB::table('tbl_trip_members as m')->join('tbl_trips as t', 't.tripId', '=', 'm.tripId')->join('tbl_users as o', 'o.userId', '=', 't.userId')
            ->where('m.userId', $userId)->where('t.title', '!=', TripTray::TITLE)
            ->selectRaw('t.*, m.role as myRole, o.fullName as ownerName, (SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId) AS itemCount')
            ->orderByDesc(DB::raw('COALESCE(t.updatedAt, t.createdAt)'))->get()->all();
    }

    /** @return array{rev:int,by:?string} */
    public function revision(int $tripId): array
    {
        $t = DB::table('tbl_trips')->where('tripId', $tripId)->first(['rev', 'lastEditorId']);
        $by = $t && $t->lastEditorId ? DB::table('tbl_users')->where('userId', $t->lastEditorId)->value('fullName') : null;

        return ['rev' => (int) ($t->rev ?? 0), 'by' => $by ? (string) $by : null];
    }
}
