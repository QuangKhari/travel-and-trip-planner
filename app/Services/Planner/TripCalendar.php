<?php

namespace App\Services\Planner;

use App\Services\Planner\Health\DaySchedule;
use App\Services\Planner\Health\TripHealth;
use App\Services\Planner\Route\RouteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lịch chuyến đi dạng iCalendar (RFC 5545) để thêm vào Google Calendar / Apple Calendar / Outlook.
 * Giờ từng mục lấy từ ĐÚNG bộ xếp giờ của trang sửa (DaySchedule), nên khớp với những gì người dùng thấy.
 * Hai cách dùng: tải tệp .ics một lần, hoặc đăng ký đường dẫn có chữ ký (lịch tự cập nhật khi chuyến đổi; SEQUENCE = rev).
 */
final class TripCalendar
{
    public const TZ = 'Asia/Ho_Chi_Minh';

    public function __construct(private TripPlanner $planner, private RouteService $route) {}

    /** Chữ ký của đường dẫn đăng ký: gắn với người dùng, nên mất quyền xem chuyến là đường dẫn hết hiệu lực. */
    public static function sign(int $tripId, int $userId): string
    {
        return substr(hash_hmac('sha256', 'ics|' . $tripId . '|' . $userId, (string) config('app.key')), 0, 24);
    }

    public static function feedUrl(int $tripId, int $userId): string
    {
        return route('planner.ics.feed', ['id' => $tripId, 'user' => $userId, 'sig' => self::sign($tripId, $userId)]);
    }

    /** @return list<array{uid:string,start:Carbon,end:Carbon,title:string,where:?string,lat:?float,lng:?float,note:string,day:int}> */
    public function events(object $trip): array
    {
        if (empty($trip->startDate)) {
            return [];
        }
        $p = TripHealth::withWeather($trip, $this->planner->payload($trip));
        $out = [];
        $seen = [];
        foreach ($p['days'] as $d) {
            $date = Carbon::parse($trip->startDate, self::TZ)->addDays((int) $d['day'] - 1)->startOfDay();
            $s = new DaySchedule((int) $d['day'], $d['items'], $this->route, null, $d['dow'] ?? null, $d['ctx'] ?? null);
            foreach ($s->rows as $r) {
                $it = $r['item'];
                $base = $trip->tripId . '-' . $d['day'] . '-' . ($it['type'] ?? '') . '-' . ($it['refId'] ?? '') . '-' . md5((string) $it['name']);
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $wx = $p['weather']['days'][$d['day']] ?? null;
                $note = trim(implode("\n", array_filter([
                    $it['sub'] ?? null,
                    $it['note'] ?? null,
                    $wx && ($it['outdoor'] ?? null) === true ? 'Dự báo ngày ' . $d['day'] . ': ' . $wx['label'] . ', mưa ' . (int) ($wx['rainMax'] ?? 0) . '%' : null,
                    !empty($it['url']) ? url($it['url']) : null,
                ])));
                $out[] = ['uid' => $base . '-' . $seen[$base] . '@travela', 'start' => $date->copy()->addMinutes((int) $r['start']),
                    'end' => $date->copy()->addMinutes(max((int) $r['end'], (int) $r['start'] + 15)), 'title' => (string) $it['name'],
                    'where' => $it['address'] ?? null, 'lat' => $it['lat'] ?? null, 'lng' => $it['lng'] ?? null, 'note' => $note, 'day' => (int) $d['day']];
            }
        }

        return $out;
    }

    public function ics(object $trip): string
    {
        $esc = fn(?string $s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $s);
        $utc = fn(Carbon $c) => $c->copy()->utc()->format('Ymd\THis\Z');
        $stamp = Carbon::parse($trip->updatedAt ?? $trip->createdAt ?? now())->utc()->format('Ymd\THis\Z');
        $seq = (int) ($trip->rev ?? 0);
        $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Travela//Trip Planner//VI', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $esc('Travela · ' . $trip->title), 'X-WR-TIMEZONE:' . self::TZ, 'REFRESH-INTERVAL;VALUE=DURATION:PT3H', 'X-PUBLISHED-TTL:PT3H'];
        foreach ($this->events($trip) as $e) {
            $L[] = 'BEGIN:VEVENT';
            $L[] = 'UID:' . $e['uid'];
            $L[] = 'DTSTAMP:' . $stamp;
            $L[] = 'SEQUENCE:' . $seq;
            $L[] = 'DTSTART:' . $utc($e['start']);
            $L[] = 'DTEND:' . $utc($e['end']);
            $L[] = 'SUMMARY:' . $esc($e['title']);
            if ($e['where']) {
                $L[] = 'LOCATION:' . $esc($e['where']);
            }
            if ($e['lat'] !== null && $e['lng'] !== null) {
                $L[] = 'GEO:' . round((float) $e['lat'], 6) . ';' . round((float) $e['lng'], 6);
            }
            if ($e['note'] !== '') {
                $L[] = 'DESCRIPTION:' . $esc($e['note']);
            }
            $L[] = 'CATEGORIES:' . $esc('Ngày ' . $e['day']);
            $L[] = 'END:VEVENT';
        }
        $L[] = 'END:VCALENDAR';

        // gập dòng dài 75 octet theo RFC 5545 (không cắt giữa ký tự UTF-8)
        $fold = function (string $line): string {
            $out = '';
            while (strlen($line) > 75) {
                $cut = 75;
                while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                    $cut--;
                }
                $out .= substr($line, 0, $cut) . "\r\n ";
                $line = substr($line, $cut);
            }

            return $out . $line;
        };

        return implode("\r\n", array_map($fold, $L)) . "\r\n";
    }

    public static function fileName(object $trip): string
    {
        $slug = \Illuminate\Support\Str::slug((string) $trip->title) ?: 'chuyen-di';

        return 'travela-' . $slug . '.ics';
    }

    public static function hasAccess(int $tripId, int $userId): bool
    {
        return (bool) app(TripSharing::class)->access($userId, $tripId);
    }

    public static function trip(int $id): ?object
    {
        return DB::table('tbl_trips')->where('tripId', $id)->first();
    }
}
