<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * B6: khay Chuyến đi. Khách chưa đăng nhập lưu ở trình duyệt (localStorage); khi đăng nhập, trình duyệt gửi lên đây
 * để gộp vào một chuyến "Khay chuyến đi" (tbl_trips.status = draft) của người dùng. Planner (sau này) đọc chính chuyến này.
 */
class TripTray
{
    public const TITLE = 'Khay chuyến đi';
    public const MAX_ITEMS = 60;
    private const TABLE = ['destination' => ['tbl_destinations', 'destinationId'], 'food' => ['tbl_foods', 'foodId'], 'event' => ['tbl_events', 'eventId'], 'experience' => ['tbl_experiences', 'experienceId']];

    private function tripId(int $userId): int
    {
        $id = DB::table('tbl_trips')->where(['userId' => $userId, 'title' => self::TITLE, 'status' => 'draft'])->value('tripId');

        return (int) ($id ?: DB::table('tbl_trips')->insertGetId(['userId' => $userId, 'title' => self::TITLE, 'status' => 'draft', 'groupSize' => 1, 'createdAt' => now()]));
    }

    /**
     * Gộp các mục gửi lên vào khay trên máy chủ rồi trả về toàn bộ khay.
     *
     * @param list<array{type?:mixed,slug?:mixed}> $incoming
     * @return list<array{type:string,slug:string,name:string,url:string}>
     */
    public function sync(int $userId, array $incoming): array
    {
        $trip = $this->tripId($userId);
        $marks = new UserMarks();
        $have = DB::table('tbl_trip_items')->where('tripId', $trip)->count();
        foreach (array_slice($incoming, 0, self::MAX_ITEMS) as $it) {
            $type = is_array($it) && is_string($it['type'] ?? null) ? $it['type'] : '';
            $slug = is_array($it) && is_string($it['slug'] ?? null) ? $it['slug'] : '';
            $id = $marks->resolve($type, $slug);
            if (!$id || $have >= self::MAX_ITEMS) {
                continue;
            }
            $have += DB::table('tbl_trip_items')->insertOrIgnore(['tripId' => $trip, 'refType' => $type, 'refId' => $id, 'orderNo' => $have]);
        }

        return $this->items($userId);
    }

    /** Bỏ một mục khỏi khay trên máy chủ. */
    public function remove(int $userId, string $type, string $slug): void
    {
        $id = (new UserMarks())->resolve($type, $slug);
        if ($id) {
            DB::table('tbl_trip_items')->where('tripId', $this->tripId($userId))->where(['refType' => $type, 'refId' => $id])->delete();
        }
    }

    public function items(int $userId): array
    {
        $trip = DB::table('tbl_trips')->where(['userId' => $userId, 'title' => self::TITLE, 'status' => 'draft'])->value('tripId');
        if (!$trip) {
            return [];
        }
        $rows = DB::table('tbl_trip_items')->where('tripId', $trip)->orderBy('orderNo')->get();
        $out = [];
        foreach ($rows as $r) {
            if (!isset(self::TABLE[$r->refType])) {
                continue;
            }
            [$table, $key] = self::TABLE[$r->refType];
            $row = DB::table($table)->where($key, $r->refId)->first();
            if (!$row) {
                continue;
            }
            $out[] = [
                'type' => $r->refType,
                'slug' => $row->slug,
                'name' => $row->name,
                'url' => match ($r->refType) {
                    'destination' => route('destination.show', ['slug' => $row->slug]),
                    'food' => route('foods.show', ['slug' => $row->slug]),
                    'experience' => route('experiences.show', ['slug' => $row->slug]),
                    default => route('events.show', ['slug' => $row->slug]),
                },
            ];
        }

        return $out;
    }
}
