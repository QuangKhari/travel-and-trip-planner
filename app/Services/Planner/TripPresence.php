<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\Cache;

/**
 * Ai đang mở chuyến, đang xem / sửa mục nào ("sửa chung gần thời gian thực").
 * Không cần máy chủ WebSocket: trình duyệt hỏi định kỳ (4 giây khi có người khác, 20 giây khi một mình) qua /phien-ban,
 * mỗi lần hỏi là một nhịp "còn ở đây". Quá 25 giây không hỏi = đã rời. Dữ liệu chỉ nằm trong cache, không ghi CSDL.
 */
final class TripPresence
{
    public const TTL_SEC = 25;

    private static function key(int $tripId): string
    {
        return 'trip-presence:' . $tripId;
    }

    /** @return list<array{userId:int,name:string,focus:?string,editing:bool,ago:int}> người khác đang mở chuyến */
    public function touch(int $tripId, int $userId, string $name, ?string $focus, bool $editing): array
    {
        $now = time();
        $all = array_filter((array) Cache::get(self::key($tripId), []), fn($p) => $now - (int) ($p['at'] ?? 0) <= self::TTL_SEC);
        if ($userId > 0) {
            $all[$userId] = ['name' => mb_substr($name, 0, 60), 'focus' => $focus !== null ? mb_substr($focus, 0, 80) : null, 'editing' => $editing, 'at' => $now];
        }
        Cache::put(self::key($tripId), $all, self::TTL_SEC * 4);
        $out = [];
        foreach ($all as $uid => $p) {
            if ((int) $uid !== $userId) {
                $out[] = ['userId' => (int) $uid, 'name' => (string) $p['name'], 'focus' => $p['focus'], 'editing' => (bool) $p['editing'], 'ago' => $now - (int) $p['at']];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    public function leave(int $tripId, int $userId): void
    {
        $all = (array) Cache::get(self::key($tripId), []);
        unset($all[$userId]);
        Cache::put(self::key($tripId), $all, self::TTL_SEC * 4);
    }
}
