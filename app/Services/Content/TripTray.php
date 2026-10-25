<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

/**
 * B6: khay Chuyến đi. Khách chưa đăng nhập lưu ở trình duyệt (localStorage); khi đăng nhập, trình duyệt gửi lên đây
 * để gộp vào "khay" của người dùng (tbl_trips.kind = 'tray', status = draft). Khay chứa mục của nhiều điểm đến,
 * không có destinationId và không có dayNo. Trip thật (kind = 'trip') tạo từ khay ở tuần Planner.
 *
 * Từ migration 2026_10_27_000001 không còn unique(tripId, refType, refId), nên chống trùng làm bằng code ở đây.
 */
class TripTray
{
    public const TITLE = 'Khay chuyến đi';
    public const MAX_ITEMS = 60;
    private const TABLE = ['destination' => ['tbl_destinations', 'destinationId'], 'food' => ['tbl_foods', 'foodId'], 'event' => ['tbl_events', 'eventId']];

    private function findTrayId(int $userId): ?int
    {
        $id = DB::table('tbl_trips')->where(['userId' => $userId, 'kind' => 'tray', 'status' => 'draft'])->orderBy('tripId')->value('tripId');

        return $id ? (int) $id : null;
    }

    private function trayId(int $userId): int
    {
        return $this->findTrayId($userId) ?? (int) DB::table('tbl_trips')->insertGetId([
            'userId' => $userId, 'kind' => 'tray', 'title' => self::TITLE, 'status' => 'draft', 'groupSize' => 1, 'createdAt' => now(),
        ]);
    }

    /**
     * Gộp các mục gửi lên vào khay trên máy chủ rồi trả về toàn bộ khay.
     *
     * @param list<array{type?:mixed,slug?:mixed}> $incoming
     * @return list<array{type:string,slug:string,name:string,url:string}>
     */
    public function sync(int $userId, array $incoming): array
    {
        $trip = $this->trayId($userId);
        $marks = new UserMarks();

        DB::transaction(function () use ($trip, $incoming, $marks) {
            // Khóa dòng chuyến để hai lần đồng bộ cùng lúc không chèn trùng
            DB::table('tbl_trips')->where('tripId', $trip)->lockForUpdate()->value('tripId');

            $have = [];
            foreach (DB::table('tbl_trip_items')->where('tripId', $trip)->get(['refType', 'refId']) as $r) {
                $have[$r->refType . ':' . $r->refId] = true;
            }
            $next = (int) (DB::table('tbl_trip_items')->where('tripId', $trip)->max('orderNo') ?? -1) + 1;

            foreach (array_slice($incoming, 0, self::MAX_ITEMS) as $it) {
                if (count($have) >= self::MAX_ITEMS) {
                    break;
                }
                $type = is_array($it) && is_string($it['type'] ?? null) ? $it['type'] : '';
                $slug = is_array($it) && is_string($it['slug'] ?? null) ? $it['slug'] : '';
                $id = $marks->resolve($type, $slug);
                if (!$id || isset($have[$type . ':' . $id])) {
                    continue;
                }
                DB::table('tbl_trip_items')->insert(['tripId' => $trip, 'refType' => $type, 'refId' => $id, 'dayNo' => null, 'orderNo' => $next++]);
                $have[$type . ':' . $id] = true;
            }
        });

        return $this->items($userId);
    }

    /** Bỏ một mục khỏi khay trên máy chủ (đúng một dòng, kể cả khi dữ liệu cũ lỡ có trùng). */
    public function remove(int $userId, string $type, string $slug): void
    {
        $id = (new UserMarks())->resolve($type, $slug);
        $trip = $this->findTrayId($userId);
        if (!$id || !$trip) {
            return;
        }
        $itemId = DB::table('tbl_trip_items')->where(['tripId' => $trip, 'refType' => $type, 'refId' => $id])->orderBy('itemId')->value('itemId');
        if ($itemId) {
            DB::table('tbl_trip_items')->where('itemId', $itemId)->delete();
        }
    }

    public function items(int $userId): array
    {
        $trip = $this->findTrayId($userId);
        if (!$trip) {
            return [];
        }
        $rows = DB::table('tbl_trip_items')->where('tripId', $trip)->orderBy('orderNo')->orderBy('itemId')->get();
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
                    default => route('events.show', ['slug' => $row->slug]),
                },
            ];
        }

        return $out;
    }
}
