<?php

namespace App\Services\Planner\Reco;

use App\Services\Planner\PlaceSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ghi lần hiện gợi ý (Đợt D, L1) và dựng tập học có nhãn (L3/L7).
 * Nhãn: 1 nếu sau lúc hiện, cùng người + cùng chuyến + cùng nơi có tín hiệu added / applied / visited trong POSITIVE_WINDOW;
 *       0 nếu không và lần hiện đã cũ hơn SETTLE (người dùng đã có đủ thời gian để chọn).
 */
final class RecoLog
{
    public const MAX_PER_LIST = 20;
    public const POSITIVE_WINDOW_H = 24;
    public const SETTLE_MIN = 30;
    public const TYPES = ['place', 'food', 'experience'];

    public static function ready(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasTable('tbl_reco_impressions');
    }

    /**
     * @param list<array> $results phần tử có type / refId (dạng trả về của tìm kiếm, quanh tuyến, gợi ý La bàn)
     * @return ?string listId (null khi không ghi: chưa đăng nhập, tạm dừng ghi nhớ, danh sách rỗng)
     */
    public static function log(?int $userId, ?object $trip, string $surface, array $results, array $ctx = [], string $policy = 'rules'): ?string
    {
        if (!$userId || !self::ready() || app(PlaceSignals::class)->paused($userId)) {
            return null;
        }
        $rows = [];
        $listId = Str::lower(Str::random(16));
        $pos = 0;
        foreach ($results as $r) {
            $type = (string) ($r['type'] ?? $r['refType'] ?? '');
            $ref = (int) ($r['refId'] ?? $r['id'] ?? 0);
            if (!in_array($type, self::TYPES, true) || $ref <= 0) {
                continue;
            }
            $rows[] = ['listId' => $listId, 'userId' => $userId, 'tripId' => $trip->tripId ?? null, 'surface' => $surface, 'policy' => $policy,
                'refType' => $type, 'refId' => $ref, 'position' => $pos++, 'score' => isset($r['score']) && is_numeric($r['score']) ? (float) $r['score'] : null,
                'ctx' => json_encode(self::context($trip, $ctx)), 'createdAt' => now()];
            if ($pos >= self::MAX_PER_LIST) {
                break;
            }
        }
        if (!$rows) {
            return null;
        }
        try {
            DB::table('tbl_reco_impressions')->insert($rows);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $listId;
    }

    public static function context(?object $trip, array $ctx = []): array
    {
        $start = $trip->startDate ?? null;
        $day = isset($ctx['day']) ? (int) $ctx['day'] : null;
        $date = $start && $day ? date('Y-m-d', strtotime($start . ' +' . ($day - 1) . ' days')) : $start;

        return array_filter([
            'dest' => isset($trip->destinationId) ? (int) $trip->destinationId : null,
            'day' => $day,
            'dow' => $date ? (int) date('N', strtotime($date)) : null,
            'month' => $date ? (int) date('n', strtotime($date)) : null,
            'hour' => (int) now()->format('G'),
            'group' => isset($trip->groupSize) ? (int) $trip->groupSize : null,
            'pace' => $trip->pace ?? null,
            'qlen' => $ctx['qlen'] ?? null,
            'lat' => $ctx['lat'] ?? null,
            'lng' => $ctx['lng'] ?? null,
        ], fn($v) => $v !== null);
    }

    /** Xóa dữ liệu hiện gợi ý của người dùng ("Quên hết" ở trang Travela nhớ gì về bạn). */
    public static function forget(int $userId): void
    {
        if (self::ready()) {
            DB::table('tbl_reco_impressions')->where('userId', $userId)->delete();
        }
    }

    /**
     * Tập có nhãn theo thời gian (cũ → mới).
     * @return list<array{impressionId:int,userId:int,tripId:?int,surface:string,policy:string,refType:string,refId:int,position:int,ctx:array,createdAt:string,label:int}>
     */
    public static function labelled(?string $since = null, ?int $userId = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $settle = now()->subMinutes(self::SETTLE_MIN);
        $imps = DB::table('tbl_reco_impressions')->when($since, fn($q) => $q->where('createdAt', '>=', $since))
            ->when($userId, fn($q) => $q->where('userId', $userId))->orderBy('impressionId')->get();
        if ($imps->isEmpty()) {
            return [];
        }
        $pos = [];
        foreach (DB::table('tbl_place_signals')->whereIn('userId', $imps->pluck('userId')->unique())->whereIn('signal', ['added', 'applied', 'visited'])
                     ->where('createdAt', '>=', $imps->min('createdAt'))->get(['userId', 'tripId', 'refType', 'refId', 'createdAt']) as $s) {
            $pos[$s->userId . '|' . ($s->tripId ?? 0) . '|' . $s->refType . '|' . $s->refId][] = strtotime((string) $s->createdAt);
        }
        $out = [];
        foreach ($imps as $i) {
            $t = strtotime((string) $i->createdAt);
            $hits = $pos[$i->userId . '|' . ($i->tripId ?? 0) . '|' . $i->refType . '|' . $i->refId] ?? [];
            $label = collect($hits)->contains(fn($h) => $h >= $t - 5 && $h <= $t + self::POSITIVE_WINDOW_H * 3600) ? 1 : 0;
            if ($label === 0 && $t > $settle->getTimestamp()) {
                continue;   // còn quá mới để coi là "không chọn"
            }
            $out[] = ['impressionId' => (int) $i->impressionId, 'userId' => (int) $i->userId, 'tripId' => $i->tripId ? (int) $i->tripId : null,
                'surface' => $i->surface, 'policy' => $i->policy, 'refType' => $i->refType, 'refId' => (int) $i->refId, 'position' => (int) $i->position,
                'ctx' => json_decode((string) $i->ctx, true) ?: [], 'createdAt' => (string) $i->createdAt, 'label' => $label];
        }

        return $out;
    }
}
