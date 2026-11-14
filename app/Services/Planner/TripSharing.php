<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
        $trip = DB::table('tbl_trips')->where('tripId', $tripId)->where('kind', 'trip')->first();
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
        $trip = DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $ownerId])->where('kind', 'trip')->first();
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

        return DB::transaction(function () use ($tripId, $userId, $actorId, $isOwner) {
            if (DB::table('tbl_trip_members')->where(['tripId' => $tripId, 'userId' => $userId])->delete() === 0) {
                return false;
            }
            $this->keepExpensesOf($tripId, $userId);
            // chủ chuyến mời ra: đổi liên kết mời để người đó không tự vào lại bằng liên kết cũ
            if ($isOwner && $actorId !== $userId && Schema::hasColumn('tbl_trips', 'joinToken')) {
                DB::table('tbl_trips')->where('tripId', $tripId)->whereNotNull('joinToken')->update(['joinToken' => Str::random(32)]);
            }

            return true;
        });
    }

    /**
     * Người rời nhóm mà đã có khoản chi: chuyển họ thành "người đi cùng" (giữ tên) để sổ chi tiêu vẫn cân và
     * vẫn biết ai nợ ai, thay vì để các khoản treo ở một khóa không còn tên.
     */
    private function keepExpensesOf(int $tripId, int $userId): void
    {
        if (!Schema::hasTable('tbl_trip_expenses')) {
            return;
        }
        $key = 'u:' . $userId;
        $ids = DB::table('tbl_trip_expenses')->where('tripId', $tripId)->pluck('expenseId');
        if ($ids->isEmpty()) {
            return;
        }
        $used = DB::table('tbl_trip_expenses')->whereIn('expenseId', $ids)->where('paidBy', $key)->exists()
            || DB::table('tbl_trip_expense_shares')->whereIn('expenseId', $ids)->where('who', $key)->exists();
        if (!$used) {
            return;
        }
        $name = trim((string) DB::table('tbl_users')->where('userId', $userId)->value('fullName')) ?: 'Thành viên cũ';
        $base = mb_substr($name, 0, 50);
        $name = $base;
        for ($i = 2; DB::table('tbl_trip_guests')->where(['tripId' => $tripId, 'name' => $name])->exists(); $i++) {
            $name = $base . ' (' . $i . ')';
        }
        $gid = DB::table('tbl_trip_guests')->insertGetId(['tripId' => $tripId, 'name' => $name, 'createdAt' => now()]);
        DB::table('tbl_trip_expenses')->whereIn('expenseId', $ids)->where('paidBy', $key)->update(['paidBy' => 'g:' . $gid]);
        DB::table('tbl_trip_expense_shares')->whereIn('expenseId', $ids)->where('who', $key)->update(['who' => 'g:' . $gid]);
    }

    /** Chuyến người khác mời tôi vào, kèm quyền và tên chủ chuyến. */
    public function sharedWithMe(int $userId): array
    {
        $rows = DB::table('tbl_trip_members as m')->join('tbl_trips as t', 't.tripId', '=', 'm.tripId')->join('tbl_users as o', 'o.userId', '=', 't.userId')
            ->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('m.userId', $userId)->where('t.kind', 'trip')
            ->selectRaw('t.*, m.role as myRole, o.fullName as ownerName, d.name AS destName, d.coverImage AS destCover,'
                . ' (SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId) AS itemCount,'
                . ' (SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId AND i.dayNo IS NOT NULL) AS placedCount,'
                . ' (SELECT COUNT(*) FROM tbl_trip_members x WHERE x.tripId = t.tripId) AS memberCount')
            ->orderByDesc(DB::raw('COALESCE(t.updatedAt, t.createdAt)'))->get()->all();

        return array_map([TripPlanner::class, 'cardInfo'], $rows);
    }

    // ------------------------------------------------------------------ liên kết mời cùng lên kế hoạch + quyền riêng tư

    /**
     * Bật / tắt / tạo lại liên kết MỜI (khác liên kết chỉ xem): ai đăng nhập và mở liên kết sẽ vào nhóm với quyền $role.
     * Tạo lại = liên kết cũ hết hiệu lực ngay. Chỉ chủ chuyến.
     *
     * @return array{token:?string,role:string}|false
     */
    public function joinLink(int $ownerId, int $tripId, bool $on, string $role = 'edit', bool $regenerate = false): array|false
    {
        $trip = DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $ownerId])->where('kind', 'trip')->first();
        if (!$trip || !in_array($role, self::ROLES, true)) {
            return false;
        }
        $token = $on ? (($regenerate || empty($trip->joinToken)) ? \Illuminate\Support\Str::random(32) : $trip->joinToken) : null;
        DB::table('tbl_trips')->where('tripId', $tripId)->update(['joinToken' => $token, 'joinRole' => $role]);

        return ['token' => $token, 'role' => $role];
    }

    /** Chuyến ứng với liên kết mời (null nếu sai / đã tắt). */
    public function byJoinToken(string $token): ?object
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }

        return DB::table('tbl_trips')->where('joinToken', $token)->where('kind', 'trip')->first();
    }

    /**
     * Người dùng bấm "Tham gia" từ liên kết mời. Đã là chủ / thành viên thì giữ quyền hiện có
     * (không hạ quyền, không nâng quyền "chỉ xem" lên "sửa" qua liên kết).
     *
     * @return array{ok:bool,message:string,tripId?:int}
     */
    public function join(int $userId, string $token): array
    {
        $trip = $this->byJoinToken($token);
        if (!$trip) {
            return ['ok' => false, 'message' => 'Liên kết mời không còn hiệu lực. Hãy xin chủ chuyến liên kết mới.'];
        }
        if ((int) $trip->userId === $userId || DB::table('tbl_trip_members')->where(['tripId' => $trip->tripId, 'userId' => $userId])->exists()) {
            return ['ok' => true, 'message' => 'Bạn đã ở trong chuyến đi này.', 'tripId' => (int) $trip->tripId];
        }
        if (DB::table('tbl_trip_members')->where('tripId', $trip->tripId)->count() >= self::MAX_MEMBERS) {
            return ['ok' => false, 'message' => 'Chuyến đi đã đủ ' . self::MAX_MEMBERS . ' người được mời.'];
        }
        $role = in_array($trip->joinRole ?? 'edit', self::ROLES, true) ? $trip->joinRole : 'edit';
        DB::table('tbl_trip_members')->insert(['tripId' => $trip->tripId, 'userId' => $userId, 'role' => $role, 'invitedBy' => $trip->userId, 'createdAt' => now()]);

        return ['ok' => true, 'message' => 'Bạn đã tham gia chuyến đi với quyền ' . ($role === 'edit' ? 'chỉnh sửa' : 'chỉ xem') . '.', 'tripId' => (int) $trip->tripId];
    }

    /** Bản chia sẻ chỉ xem hiện gì: ghi chú, đặt chỗ (không bao giờ hiện mã xác nhận / tệp), chi phí. Chỉ chủ chuyến. */
    public function privacy(int $ownerId, int $tripId, array $flags): bool
    {
        $set = [];
        foreach (['shareNotes', 'shareBookings', 'shareCosts'] as $k) {
            if (array_key_exists($k, $flags)) {
                $set[$k] = (bool) $flags[$k];
            }
        }

        return $set && DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $ownerId])->where('kind', 'trip')->update($set) >= 0
            && DB::table('tbl_trips')->where(['tripId' => $tripId, 'userId' => $ownerId])->exists();
    }

    /** @return array{rev:int,by:?string} */
    public function revision(int $tripId): array
    {
        $t = DB::table('tbl_trips')->where('tripId', $tripId)->first(['rev', 'lastEditorId']);
        $by = $t && $t->lastEditorId ? DB::table('tbl_users')->where('userId', $t->lastEditorId)->value('fullName') : null;

        return ['rev' => (int) ($t->rev ?? 0), 'by' => $by ? (string) $by : null];
    }
}
