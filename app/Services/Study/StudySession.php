<?php

namespace App\Services\Study;

use App\Services\Planner\Health\TripHealth;
use App\Support\PasswordHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đợt F: buổi thử người dùng.
 * Người thử đăng nhập bằng tài khoản riêng (t1-01, t1-02… cho vòng 1), đồng ý tham gia, rồi làm 5 nhiệm vụ cố định.
 * Mỗi nhiệm vụ: bấm "Bắt đầu" -> hệ thống ghi mốc dữ liệu (đơn / chuyến / báo giá lớn nhất hiện có) -> người thử làm
 * trên Travela như bình thường -> bấm "Xong" -> hệ thống KIỂM TRA DỮ LIỆU THẬT xem đã đạt chưa. Chưa đạt thì người thử
 * làm tiếp (lần bấm hụt được đếm là một lần vấp) hoặc bỏ qua. Thời gian = từ Bắt đầu tới lần Xong đạt / Bỏ qua.
 */
final class StudySession
{
    public static function ready(): bool
    {
        return Schema::hasTable('tbl_study_participants');
    }

    public static function enabled(): bool
    {
        return (bool) config('study.enabled') && self::ready();
    }

    public static function participant(?int $userId): ?object
    {
        if (!$userId || !self::enabled()) {
            return null;
        }

        return DB::table('tbl_study_participants')->where('userId', $userId)->first();
    }

    /** @return list<string> mã nhiệm vụ theo thứ tự */
    public static function taskKeys(): array
    {
        return array_keys((array) config('study.tasks'));
    }

    /** Trạng thái hiện tại cho thanh nhiệm vụ. */
    public static function state(object $p): array
    {
        $rows = DB::table('tbl_study_tasks')->where('participantId', $p->participantId)->get()->keyBy('task');
        $tasks = [];
        $current = null;
        foreach (self::taskKeys() as $i => $k) {
            $r = $rows[$k] ?? null;
            $st = !$r || !$r->startedAt ? 'todo' : ($r->outcome ? $r->outcome : 'doing');
            $tasks[] = ['key' => $k, 'n' => $i + 1, 'title' => config("study.tasks.$k.title"), 'status' => $st];
            if (!$current && in_array($st, ['todo', 'doing'], true)) {
                $current = ['key' => $k, 'n' => $i + 1, 'status' => $st, 'title' => config("study.tasks.$k.title"),
                    'prompt' => config("study.tasks.$k.prompt"), 'done' => config("study.tasks.$k.done"),
                    'startedAt' => $r && $r->startedAt ? strtotime($r->startedAt) : null, 'checks' => $r ? (int) $r->checks : 0];
            }
        }
        $sus = DB::table('tbl_study_sus')->where('participantId', $p->participantId)->exists();

        return ['code' => $p->code, 'round' => (int) $p->round, 'consent' => (bool) $p->consentAt, 'tasks' => $tasks,
            'current' => $current, 'needSus' => !$current && !$sus, 'finished' => !$current && $sus,
            'softLimit' => (int) config('study.soft_limit_sec', 600)];
    }

    public static function consent(object $p, string $device): void
    {
        DB::table('tbl_study_participants')->where('participantId', $p->participantId)
            ->update(['consentAt' => now(), 'device' => in_array($device, ['desktop', 'mobile'], true) ? $device : null]);
    }

    /** Bắt đầu nhiệm vụ: chỉ nhiệm vụ đang tới lượt; bấm lại khi đang làm thì giữ mốc cũ. */
    public static function start(object $p, string $task): array
    {
        $st = self::state($p);
        if (!$p->consentAt || !$st['current'] || $st['current']['key'] !== $task) {
            return ['ok' => false, 'message' => 'Nhiệm vụ này chưa tới lượt.'];
        }
        $row = DB::table('tbl_study_tasks')->where(['participantId' => $p->participantId, 'task' => $task])->first();
        if ($row && $row->startedAt) {
            return ['ok' => true];
        }
        DB::table('tbl_study_tasks')->updateOrInsert(['participantId' => $p->participantId, 'task' => $task], [
            'position' => array_search($task, self::taskKeys(), true) + 1, 'startedAt' => now(),
            'baseline' => json_encode(self::baseline((int) $p->userId)),
        ]);

        return ['ok' => true];
    }

    /**
     * Bấm "Xong" (giveUp = false) hoặc "Bỏ qua" (true).
     * @return array{ok:bool, passed?:bool, message:string}
     */
    public static function finish(object $p, string $task, bool $giveUp, ?int $clicks = null, ?int $pages = null): array
    {
        $row = DB::table('tbl_study_tasks')->where(['participantId' => $p->participantId, 'task' => $task])->first();
        if (!$row || !$row->startedAt || $row->outcome) {
            return ['ok' => false, 'message' => 'Nhiệm vụ chưa bắt đầu hoặc đã kết thúc.'];
        }
        $passed = !$giveUp && self::check($task, $p, json_decode((string) $row->baseline, true) ?: []);
        $upd = ['checks' => (int) $row->checks + ($giveUp ? 0 : 1), 'clicks' => $clicks, 'pages' => $pages];
        if ($passed || $giveUp) {
            $upd += ['endedAt' => now(), 'outcome' => $passed ? 'success' : 'skip',
                'seconds' => max(0, now()->getTimestamp() - strtotime($row->startedAt))];
        }
        DB::table('tbl_study_tasks')->where('id', $row->id)->update($upd);
        if (($passed || $giveUp) && !self::state($p)['current']) {
            DB::table('tbl_study_participants')->where('participantId', $p->participantId)->update(['finishedAt' => now()]);
        }

        return ['ok' => true, 'passed' => $passed, 'message' => $passed ? 'Đạt. Sang nhiệm vụ tiếp theo.'
            : ($giveUp ? 'Đã bỏ qua nhiệm vụ này.' : 'Hệ thống chưa thấy kết quả: ' . config("study.tasks.$task.done") . ' Bạn làm tiếp, hoặc bấm Bỏ qua.')];
    }

    /** @param array<int,int> $answers 10 câu, 1–5 */
    public static function saveSus(object $p, array $answers, ?string $comment): ?float
    {
        $score = \App\Console\Commands\StudySummary::sus($answers);
        if ($score === null) {
            return null;
        }
        $row = ['score' => $score, 'comment' => $comment ? mb_substr($comment, 0, 2000) : null, 'createdAt' => now()];
        foreach (array_values($answers) as $i => $v) {
            $row['q' . ($i + 1)] = (int) $v;
        }
        DB::table('tbl_study_sus')->updateOrInsert(['participantId' => $p->participantId], $row);

        return $score;
    }

    /** Mốc dữ liệu lúc bắt đầu: chỉ tính kết quả tạo SAU mốc này. */
    public static function baseline(int $userId): array
    {
        return [
            'booking' => (int) DB::table('tbl_booking')->max('bookingId'),
            'trip' => (int) DB::table('tbl_trips')->max('tripId'),
            'quote' => Schema::hasTable('tbl_tour_quotes') ? (int) DB::table('tbl_tour_quotes')->max('quoteId') : 0,
            'members' => DB::table('tbl_trip_members as m')->join('tbl_trips as t', 't.tripId', '=', 'm.tripId')
                ->where('t.userId', $userId)->pluck('m.userId')->map(fn($v) => (int) $v)->all(),
            'joinLinks' => DB::table('tbl_trips')->where('userId', $userId)->whereNotNull('joinToken')->pluck('tripId')->map(fn($v) => (int) $v)->all(),
        ];
    }

    /** Kiểm tra kết quả trên dữ liệu thật. */
    public static function check(string $task, object $p, array $b): bool
    {
        $uid = (int) $p->userId;

        return match ($task) {
            'tour_book' => DB::table('tbl_booking')->where('userId', $uid)->where('bookingId', '>', $b['booking'] ?? 0)->exists(),
            'trip_create' => self::hasDaNangTrip($uid, (int) ($b['trip'] ?? 0)),
            'trip_fix' => self::fixed($p),
            'trip_invite' => self::invited($uid, $b),
            'quote' => Schema::hasTable('tbl_tour_quotes') && DB::table('tbl_tour_quotes')->where('userId', $uid)->where('quoteId', '>', $b['quote'] ?? 0)->exists(),
            default => false,
        };
    }

    private static function hasDaNangTrip(int $uid, int $after): bool
    {
        $dn = DB::table('tbl_destinations')->whereIn('slug', ['da-nang', 'hoi-an'])->pluck('destinationId')->map(fn($v) => (int) $v)->all();
        $trips = DB::table('tbl_trips')->where('userId', $uid)->where('kind', 'trip')->where('tripId', '>', $after)
            ->where('days', '>=', 3)->whereIn('destinationId', $dn ?: [0])->pluck('tripId');
        foreach ($trips as $t) {
            $perDay = DB::table('tbl_trip_items')->where('tripId', $t)->whereNull('skippedAt')->selectRaw('dayNo, COUNT(*) n')->groupBy('dayNo')->pluck('n', 'dayNo');
            $days = DB::table('tbl_trips')->where('tripId', $t)->value('days');
            $full = collect(range(1, (int) $days))->every(fn($d) => (int) ($perDay[$d] ?? 0) >= 1);
            if ($full && array_sum($perDay->all()) >= 6) {
                return true;
            }
        }

        return false;
    }

    private static function fixed(object $p): bool
    {
        $trip = $p->fixTripId ? DB::table('tbl_trips')->where('tripId', $p->fixTripId)->first() : null;
        if (!$trip) {
            return false;
        }
        foreach (app(TripHealth::class)->forTrip($trip)['checks'] as $c) {
            if (in_array($c['code'], TripHealth::HARD, true)) {
                return false;
            }
        }

        return true;
    }

    private static function invited(int $uid, array $b): bool
    {
        $companion = DB::table('tbl_users')->where('username', config('study.companion.username'))->value('userId');
        $members = DB::table('tbl_trip_members as m')->join('tbl_trips as t', 't.tripId', '=', 'm.tripId')
            ->where('t.userId', $uid)->pluck('m.userId')->map(fn($v) => (int) $v)->all();
        if ($companion && in_array((int) $companion, $members, true) && !in_array((int) $companion, $b['members'] ?? [], true)) {
            return true;
        }
        $links = DB::table('tbl_trips')->where('userId', $uid)->whereNotNull('joinToken')->pluck('tripId')->map(fn($v) => (int) $v)->all();

        return (bool) array_diff($links, $b['joinLinks'] ?? []);
    }

    /**
     * Tạo tài khoản người thử cho một vòng (mã t{vòng}-01…), tài khoản bạn đồng hành, và chuyến lỗi cho nhiệm vụ 3.
     * @return list<array{code:string,username:string,fixTripId:?int}>
     */
    public static function setup(int $round, int $n): array
    {
        $pass = PasswordHasher::make((string) config('study.password'));
        $c = (array) config('study.companion');
        DB::table('tbl_users')->updateOrInsert(['username' => $c['username']], ['email' => $c['email'], 'password' => $pass, 'fullName' => $c['name'], 'isActive' => 'y']);
        $src = self::fixSource();
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $code = sprintf('t%d-%02d', $round, $i);
            $username = 'thu' . $round . sprintf('%02d', $i);
            DB::table('tbl_users')->updateOrInsert(['username' => $username], ['email' => $username . '@travela.test', 'password' => $pass, 'fullName' => 'Người thử ' . strtoupper($code), 'isActive' => 'y']);
            $uid = (int) DB::table('tbl_users')->where('username', $username)->value('userId');
            $p = DB::table('tbl_study_participants')->where('userId', $uid)->first();
            $fix = $p && $p->fixTripId && DB::table('tbl_trips')->where('tripId', $p->fixTripId)->exists() ? (int) $p->fixTripId : ($src ? self::cloneTrip($src, $uid) : null);
            DB::table('tbl_study_participants')->updateOrInsert(['userId' => $uid], ['code' => $code, 'round' => $round, 'fixTripId' => $fix, 'createdAt' => $p->createdAt ?? now()]);
            $out[] = ['code' => $code, 'username' => $username, 'fixTripId' => $fix];
        }

        return $out;
    }

    /** Xóa dữ liệu buổi thử của một người (giữ tài khoản) để làm lại. */
    public static function resetParticipant(object $p): void
    {
        DB::table('tbl_study_tasks')->where('participantId', $p->participantId)->delete();
        DB::table('tbl_study_sus')->where('participantId', $p->participantId)->delete();
        DB::table('tbl_study_participants')->where('participantId', $p->participantId)->update(['consentAt' => null, 'finishedAt' => null]);
    }

    private static function fixSource(): ?object
    {
        $s = (array) config('study.fix_trip_source');
        $owner = DB::table('tbl_users')->where('username', $s['username'] ?? '')->value('userId');

        return $owner ? DB::table('tbl_trips')->where('userId', $owner)->where('title', $s['title'] ?? '')->where('kind', 'trip')->first() : null;
    }

    /** Bản sao chuyến (kèm các mục) cho một người; dùng cho buổi thử và thử tải. */
    public static function cloneTrip(object $src, int $uid, string $type = 'study'): int
    {
        $row = (array) $src;
        unset($row['tripId'], $row['shareToken'], $row['joinToken']);
        $row = array_merge($row, ['userId' => $uid, 'title' => $src->title . ($type === 'study' ? ' (thử)' : ''), 'isPublic' => 0, 'publishedAt' => null, 'viewCount' => 0,
            'likeCount' => 0, 'cloneCount' => 0, 'rev' => 1, 'lastEditorId' => $uid, 'createdAt' => now(), 'updatedAt' => now(),
            'sourceTripId' => $src->tripId, 'sourceType' => $type]);
        $id = (int) DB::table('tbl_trips')->insertGetId($row);
        foreach (DB::table('tbl_trip_items')->where('tripId', $src->tripId)->get() as $it) {
            $r = (array) $it;
            unset($r['itemId']);
            $r['tripId'] = $id;
            $r['visitedAt'] = null;
            DB::table('tbl_trip_items')->insert($r);
        }

        return $id;
    }
}
