<?php

namespace App\Services\Planner;

use App\Mail\TemplateMail;
use App\Services\Ai\Copilot\TripSuggest;
use App\Services\Planner\Health\TripHealth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Theo dõi chuyến đi: hệ thống tự phản ứng với thế giới bên ngoài thay vì chờ người dùng mở trang.
 *
 * observe(): lấy các cảnh báo hiện tại của chuyến — CÙNG nguồn với nút "Cảnh báo" của La bàn (TripSuggest 'alerts'):
 * dự báo theo giờ (dông / mưa / nắng nóng đúng giờ hoạt động ngoài trời), nơi đóng cửa đúng ngày, cảnh báo điểm đến do Travela nhập —
 * cộng lỗi cứng của lịch do thay đổi bên ngoài (giờ tới / giờ đi của vé). So với ảnh chụp lần trước:
 *  - thẻ mới hoặc nội dung đổi (vd. mưa 40% -> 80%) => sự kiện "alert" (ghi nhật ký, gửi email nếu đáng kể);
 *  - thẻ biến mất => sự kiện "resolved" (đã ổn: người dùng sửa, hoặc dự báo tốt lên).
 * Không có gì mới thì im lặng. Mỗi sự kiện mở thẳng La bàn ở đúng thẻ cảnh báo, có nút sửa (đề xuất đã kiểm chứng bằng TripHealth).
 *
 * digest(): bản tin theo mốc (trước 7 ngày, trước 1 ngày, sáng mỗi ngày đi) — mỗi mốc một lần (khóa ref).
 */
final class TripWatch
{
    /** Cửa sổ theo dõi: chuyến bắt đầu trong N ngày tới hoặc đang đi (dự báo theo giờ có tới 16 ngày). */
    public const HORIZON_DAYS = 16;
    /** Email cảnh báo mức "warn" tối đa 1 lần / khoảng này; mức "bad" (dông, đóng cửa, lỗi cứng) gửi ngay. */
    public const MAIL_GAP_HOURS = 6;

    public function __construct(private TripSuggest $suggest, private TripHealth $health, private TripPlanner $planner) {}

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_trip_events') && Schema::hasTable('tbl_trip_watch');
    }

    /** Chuyến cần theo dõi hôm nay ($today giả lập được khi thử nghiệm). */
    public function dueTrips(?Carbon $today = null)
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return DB::table('tbl_trips')->where('kind', 'trip')->whereNotNull('startDate')
            ->whereNotIn('status', ['done', 'cancelled'])
            ->where('startDate', '<=', $today->copy()->addDays(self::HORIZON_DAYS)->toDateString())
            ->whereRaw('DATE_ADD(startDate, INTERVAL GREATEST(days, 1) - 1 DAY) >= ?', [$today->toDateString()])
            ->orderBy('startDate')->get();
    }

    /**
     * Cảnh báo hiện tại của chuyến, mỗi cảnh báo một dấu vân tay (đổi nội dung -> đổi dấu).
     *
     * @return array<string,array{id:string,tone:string,title:string,text:string,day:?int,hash:string}>
     */
    public function signals(object $trip): array
    {
        $out = [];
        try {
            $cards = $this->suggest->suggest($trip, 'alerts')['cards'] ?? [];
        } catch (\Throwable $e) {
            report($e);
            $cards = [];
        }
        foreach ($cards as $c) {
            if (($c['kind'] ?? '') !== 'alert' || !in_array($c['tone'] ?? '', ['bad', 'warn'], true)) {
                continue;
            }
            $title = (string) $c['title'];
            $text = trim(($c['sub'] ?? '') . ($c['text'] ? ' — ' . $c['text'] : ''));
            $out[$c['id']] = ['id' => (string) $c['id'], 'tone' => $c['tone'], 'title' => $title, 'text' => mb_substr($text, 0, 600),
                'day' => isset($c['day']) ? (int) $c['day'] : null, 'hash' => substr(md5($c['tone'] . '|' . $title . '|' . ($c['sub'] ?? '')), 0, 12)];
        }
        // lỗi cứng do yếu tố bên ngoài lịch: chuyến bay / tàu (giờ tới, giờ đi) — người dùng có thể chưa mở trang sau khi đổi vé
        try {
            foreach ($this->health->forTrip($trip)['checks'] as $c) {
                // + quá giờ mở cửa: thường do vé đổi giờ (cả ngày bị lùi) hoặc giờ mở cửa vừa cập nhật — đóng cả ngày đã có thẻ cảnh báo riêng
                if (in_array($c['code'], ['time.arrival', 'time.departure', 'time.transfer', 'hours.late', 'hours.early'], true)) {
                    $id = 'health:' . $c['code'] . ':' . ($c['day'] ?? 0) . ':' . ($c['index'] ?? 0);
                    $out[$id] = ['id' => $id, 'tone' => 'bad', 'title' => ['time.arrival' => 'Lịch đặt trước giờ bạn tới nơi', 'time.transfer' => 'Lịch trùng lúc đang di chuyển',
                        'hours.late' => 'Không kịp giờ mở cửa', 'hours.early' => 'Tới khi nơi này chưa mở cửa'][$c['code']] ?? 'Lịch chưa xong đã tới giờ phải rời đi',
                        'text' => $c['text'], 'day' => $c['day'], 'hash' => substr(md5($c['text']), 0, 12)];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $out;
    }

    /**
     * Một lần theo dõi: so với ảnh chụp trước, ghi sự kiện, cập nhật ảnh chụp.
     *
     * @return array{new:list<array>,resolved:list<array>,current:int,first:bool}
     */
    public function observe(object $trip, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $sig = $this->signals($trip);
        $row = DB::table('tbl_trip_watch')->where('tripId', $trip->tripId)->first();
        $prev = $row ? (json_decode((string) $row->snapshot, true) ?: []) : [];
        $prevMeta = $prev['_meta'] ?? [];
        unset($prev['_meta']);
        $new = [];
        $resolved = [];
        foreach ($sig as $id => $s) {
            if (($prev[$id] ?? null) !== $s['hash']) {
                $new[] = $s + ['changed' => isset($prev[$id])];
            }
        }
        foreach ($prev as $id => $hash) {
            if (!isset($sig[$id])) {
                $resolved[] = ['id' => $id, 'title' => $prevMeta[$id]['title'] ?? 'Cảnh báo', 'day' => $prevMeta[$id]['day'] ?? null];
            }
        }
        DB::transaction(function () use ($trip, $new, $resolved, $sig, $now) {
            foreach ($new as $s) {
                DB::table('tbl_trip_events')->insertOrIgnore(['tripId' => $trip->tripId, 'kind' => 'alert', 'tone' => $s['tone'],
                    'title' => ($s['changed'] ? 'Thay đổi: ' : '') . $s['title'], 'text' => $s['text'], 'day' => $s['day'],
                    'ref' => mb_substr($s['id'], 0, 90) . ':' . $s['hash'] . ':' . $now->format('Ymd'), 'createdAt' => $now]);
            }
            foreach ($resolved as $s) {
                DB::table('tbl_trip_events')->insertOrIgnore(['tripId' => $trip->tripId, 'kind' => 'resolved', 'tone' => 'ok',
                    'title' => 'Đã ổn: ' . $s['title'], 'text' => null, 'day' => $s['day'],
                    'ref' => mb_substr($s['id'], 0, 90) . ':' . $now->format('YmdH'), 'createdAt' => $now]);
            }
            $snap = array_map(fn($s) => $s['hash'], $sig);
            $snap['_meta'] = array_map(fn($s) => ['title' => $s['title'], 'day' => $s['day']], $sig);
            DB::table('tbl_trip_watch')->updateOrInsert(['tripId' => $trip->tripId], ['snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'checkedAt' => $now]);
        });

        return ['new' => $new, 'resolved' => $resolved, 'current' => count($sig), 'first' => $row === null];
    }

    /** Có nên gửi email cho lần này: có cảnh báo mới mức "bad", hoặc "warn" và đã lâu chưa gửi. */
    public function shouldMail(object $trip, array $res, ?Carbon $now = null): bool
    {
        if (!$res['new']) {
            return false;
        }
        $now = $now ?? now();
        if (collect($res['new'])->contains(fn($s) => $s['tone'] === 'bad')) {
            return true;
        }
        $last = DB::table('tbl_trip_watch')->where('tripId', $trip->tripId)->value('mailedAt');

        return !$last || Carbon::parse($last)->lt($now->copy()->subHours(self::MAIL_GAP_HOURS));
    }

    /** Chủ chuyến + thành viên có email (đã kích hoạt). @return list<object{userId,email,fullName}> */
    public function recipients(object $trip): array
    {
        $ids = DB::table('tbl_trip_members')->where('tripId', $trip->tripId)->pluck('userId')->push($trip->userId)->unique()->all();

        return DB::table('tbl_users')->whereIn('userId', $ids)->whereNotNull('email')->where('email', '!=', '')
            ->where(fn($q) => $q->whereNull('status')->orWhere('status', '!=', 'b'))->get(['userId', 'email', 'fullName'])->all();
    }

    public function mailAlerts(object $trip, array $res, ?Carbon $now = null): int
    {
        $n = 0;
        foreach ($this->recipients($trip) as $u) {
            Mail::to($u->email)->queue(new TemplateMail('admin.emails.trip-watch', [
                'name' => $u->fullName, 'title' => $trip->title, 'start' => $trip->startDate, 'alerts' => $res['new'], 'resolved' => $res['resolved'],
                'url' => route('planner.edit', ['id' => $trip->tripId]) . '?la-ban=alerts',
            ], 'Chuyến ' . $trip->title . ': ' . count($res['new']) . ' thay đổi cần xem'));
            $n++;
        }
        DB::table('tbl_trip_watch')->where('tripId', $trip->tripId)->update(['mailedAt' => $now ?? now()]);

        return $n;
    }

    // ------------------------------------------------------------------ bản tin theo mốc

    /** Mốc bản tin của ngày hôm nay cho chuyến: d7 | d1 | day{N} | null. */
    public static function milestone(object $trip, Carbon $today): ?string
    {
        if (!$trip->startDate) {
            return null;
        }
        $diff = (int) $today->copy()->startOfDay()->diffInDays(Carbon::parse($trip->startDate)->startOfDay(), false);
        if ($diff === 7) {
            return 'd7';
        }
        if ($diff === 1) {
            return 'd1';
        }
        if ($diff <= 0 && -$diff < max(1, (int) $trip->days)) {
            return 'day' . (1 - $diff);
        }

        return null;
    }

    /**
     * Nội dung bản tin: thời tiết các ngày (trong tầm dự báo), cảnh báo đang có, việc đặt chỗ còn thiếu,
     * và với ngày đang đi: điểm đầu tiên, giờ xuất phát, giờ phải rời đi.
     */
    public function digestData(object $trip, string $milestone): array
    {
        $payload = TripHealth::withWeather($trip, $this->planner->payload($trip));
        $weather = [];
        foreach ($payload['weather']['days'] ?? [] as $w) {
            $weather[] = ['day' => $w['day'], 'date' => $w['date'], 'label' => $w['label'], 'tMax' => $w['tMax'], 'tMin' => $w['tMin'], 'rain' => $w['rainMax']];
        }
        $alerts = array_values($this->signals($trip));
        $book = BookingPlan::ready() ? app(BookingPlan::class)->forTrip($trip) : ['needs' => [], 'planned' => []];
        $todo = array_merge(array_map(fn($n) => $n['title'], array_filter($book['needs'], fn($n) => empty($n['plannedId']))),
            array_map(fn($p) => 'Cần đặt: ' . $p['title'], $book['planned']));
        $today = null;
        if (str_starts_with($milestone, 'day')) {
            $d = (int) substr($milestone, 3);
            $health = $this->health->forTrip($trip);
            $day = collect($health['days'])->firstWhere('day', $d);
            if ($day) {
                $today = ['day' => $d, 'first' => $day['rows'][0] ?? null, 'count' => $day['count'], 'start' => $day['start'], 'end' => $day['end'],
                    'leave' => $day['leave'], 'arrive' => $day['arrive'], 'from' => $payload['days'][$d - 1]['ctx']['from']['name'] ?? null,
                    'startLeg' => $day['startLeg']];
            }
        }

        return ['weather' => $weather, 'alerts' => $alerts, 'todo' => array_slice($todo, 0, 6), 'today' => $today];
    }

    /**
     * Gửi bản tin nếu hôm nay là một mốc và chưa gửi mốc đó. Ghi sự kiện "digest" (chuông trong trang cũng thấy).
     *
     * @return string|null mốc đã gửi
     */
    public function digest(object $trip, ?Carbon $today = null, bool $send = true): ?string
    {
        $today = ($today ?? now())->copy();
        $m = self::milestone($trip, $today);
        if (!$m || DB::table('tbl_trip_events')->where(['tripId' => $trip->tripId, 'kind' => 'digest', 'ref' => $m])->exists()) {
            return null;
        }
        $data = $this->digestData($trip, $m);
        $title = match (true) {
            $m === 'd7' => 'Còn 7 ngày: chuẩn bị cho chuyến',
            $m === 'd1' => 'Ngày mai khởi hành',
            default => 'Hôm nay, ngày ' . substr($m, 3),
        };
        $lines = [];
        foreach (array_slice($data['weather'], 0, 3) as $w) {
            $lines[] = 'Ngày ' . $w['day'] . ': ' . $w['label'] . ($w['tMax'] !== null ? ', ' . round($w['tMin']) . '–' . round($w['tMax']) . '°C' : '') . ($w['rain'] !== null ? ', mưa tới ' . $w['rain'] . '%' : '');
        }
        if ($data['alerts']) {
            $lines[] = count($data['alerts']) . ' cảnh báo cần xem';
        }
        if ($data['todo']) {
            $lines[] = 'Còn ' . count($data['todo']) . ' việc đặt chỗ';
        }
        if ($data['today']['first'] ?? null) {
            $t = $data['today'];
            $lines[] = 'Bắt đầu ' . $t['first']['start'] . ' tại ' . $t['first']['name'] . ($t['leave'] ? ', rời đi trước ' . $t['leave'] : '');
        }
        DB::table('tbl_trip_events')->insertOrIgnore(['tripId' => $trip->tripId, 'kind' => 'digest', 'tone' => $data['alerts'] ? 'warn' : 'info',
            'title' => $title, 'text' => implode(' · ', $lines) ?: null, 'day' => str_starts_with($m, 'day') ? (int) substr($m, 3) : null,
            'ref' => $m, 'createdAt' => now()]);
        if ($send) {
            foreach ($this->recipients($trip) as $u) {
                Mail::to($u->email)->queue(new TemplateMail('admin.emails.trip-digest', [
                    'name' => $u->fullName, 'trip' => $trip, 'heading' => $title, 'milestone' => $m] + $data + [
                    'url' => route('planner.edit', ['id' => $trip->tripId]),
                ], $title . ' · ' . $trip->title));
            }
        }

        return $m;
    }

    // ------------------------------------------------------------------ chuông trong trang

    /** @return array{events:list<array>,unread:int} */
    public function feed(int $tripId, int $userId, int $limit = 30): array
    {
        if (!self::ready()) {
            return ['events' => [], 'unread' => 0, 'checkedAt' => null];
        }
        $seen = (int) DB::table('tbl_trip_event_seen')->where(['tripId' => $tripId, 'userId' => $userId])->value('lastEventId');
        $rows = DB::table('tbl_trip_events')->where('tripId', $tripId)->orderByDesc('eventId')->limit($limit)->get();

        return [
            'events' => $rows->map(fn($e) => ['id' => (int) $e->eventId, 'kind' => $e->kind, 'tone' => $e->tone, 'title' => $e->title, 'text' => $e->text,
                'day' => $e->day !== null ? (int) $e->day : null, 'at' => Carbon::parse($e->createdAt)->toIso8601String(), 'unread' => (int) $e->eventId > $seen])->all(),
            'unread' => DB::table('tbl_trip_events')->where('tripId', $tripId)->where('eventId', '>', $seen)->count(),
            'checkedAt' => DB::table('tbl_trip_watch')->where('tripId', $tripId)->value('checkedAt'),
        ];
    }

    public function markSeen(int $tripId, int $userId): void
    {
        $max = (int) DB::table('tbl_trip_events')->where('tripId', $tripId)->max('eventId');
        DB::table('tbl_trip_event_seen')->updateOrInsert(['tripId' => $tripId, 'userId' => $userId], ['lastEventId' => $max]);
    }

    /** Ghi một sự kiện do người dùng / hệ thống tạo trong trang (vd. đã xếp lại hôm nay). */
    public static function log(int $tripId, string $kind, string $tone, string $title, ?string $text = null, ?int $day = null): void
    {
        if (self::ready()) {
            DB::table('tbl_trip_events')->insert(['tripId' => $tripId, 'kind' => $kind, 'tone' => $tone, 'title' => mb_substr($title, 0, 200),
                'text' => $text, 'day' => $day, 'ref' => null, 'createdAt' => now()]);
        }
    }
}
