<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;

/**
 * Cộng đồng hành trình (T-24…T-35).
 *
 *  - Chủ chuyến công khai / thôi công khai chuyến của mình (T-24, T-33). Thôi công khai không ảnh hưởng các bản đã dùng lại.
 *  - Trang /cong-dong: tìm theo tên, lọc điểm đến / số ngày / ngân sách mỗi người / sở thích; 3 tab Nổi bật, Mới nhất, Yêu thích (T-25, T-27).
 *  - Thích một lần cho mỗi người (tbl_trip_likes), đếm lượt xem theo phiên (T-26, T-35).
 *  - "Dùng hành trình này": chép thông tin + các mục sang chuyến mới của người dùng, cho chỉnh ngày đi / số người / ngân sách (T-28, T-29).
 *    Bản chép ghi sourceTripId + sourceType = CLONED (T-32), không chép đơn đặt tour, thành viên, liên kết chia sẻ (T-31).
 *  - Quản trị gỡ một chuyến khỏi cộng đồng (T-34).
 */
class TripCommunity
{
    public const TABS = ['noi-bat' => 'Nổi bật', 'moi-nhat' => 'Mới nhất', 'yeu-thich' => 'Yêu thích'];
    public const DAY_RANGES = ['1-2' => '1–2 ngày', '3-4' => '3–4 ngày', '5-7' => '5–7 ngày', '8+' => 'Trên 7 ngày'];
    public const BUDGET_RANGES = ['duoi-2tr' => [0, 2_000_000, 'Dưới 2 triệu'], '2-5tr' => [2_000_000, 5_000_000, '2–5 triệu'], 'tren-5tr' => [5_000_000, null, 'Trên 5 triệu']];
    public const MIN_PLACED_ITEMS = 2;
    public const PER_PAGE = 12;

    // ------------------------------------------------------------------ Chủ chuyến

    /** Công khai chuyến của chính mình. Trả thông báo lỗi (string) hoặc null nếu thành công. */
    public function publish(int $userId, int $tripId, ?string $summary): ?string
    {
        $trip = app(TripPlanner::class)->owned($userId, $tripId);
        if (!$trip) {
            return 'Chỉ chủ chuyến mới công khai được chuyến đi.';
        }
        $placed = DB::table('tbl_trip_items')->where('tripId', $tripId)->whereNotNull('dayNo')->count();
        if ($placed < self::MIN_PLACED_ITEMS) {
            return 'Hãy xếp ít nhất ' . self::MIN_PLACED_ITEMS . ' địa điểm vào các ngày trước khi chia sẻ lên cộng đồng.';
        }
        DB::table('tbl_trips')->where('tripId', $tripId)->update([
            'isPublic' => 1,
            'publishedAt' => $trip->publishedAt ?? now(),
            'summary' => $summary !== null ? (mb_substr(trim(strip_tags($summary)), 0, 300) ?: null) : $trip->summary,
        ]);

        return null;
    }

    public function unpublish(int $userId, int $tripId): bool
    {
        if (!app(TripPlanner::class)->owned($userId, $tripId)) {
            return false;
        }
        DB::table('tbl_trips')->where('tripId', $tripId)->update(['isPublic' => 0]);

        return true;
    }

    /** Quản trị gỡ khỏi cộng đồng (không xóa chuyến của khách). */
    public function takeDown(int $tripId): bool
    {
        return DB::table('tbl_trips')->where('tripId', $tripId)->where('isPublic', 1)->update(['isPublic' => 0]) > 0;
    }

    // ------------------------------------------------------------------ Đọc

    private function base()
    {
        return DB::table('tbl_trips as t')
            ->join('tbl_users as u', 'u.userId', '=', 't.userId')
            ->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->where('t.kind', 'trip')->where('t.isPublic', 1)
            ->where(fn($w) => $w->whereNull('u.status')->orWhere('u.status', '!=', 'b'));
    }

    /**
     * @param array{q?:?string,dest?:?int,days?:?string,budget?:?string,interest?:?string,tab?:?string,page?:?int} $f
     * @return array{rows:list<object>,total:int,page:int,pages:int}
     */
    public function feed(array $f): array
    {
        $q = $this->base();
        if ($s = trim((string) ($f['q'] ?? ''))) {
            $like = '%' . addcslashes($s, '%_\\') . '%';
            $q->where(fn($w) => $w->where('t.title', 'like', $like)->orWhere('t.summary', 'like', $like)
                ->orWhere('d.name', 'like', $like)->orWhere('d.province', 'like', $like));
        }
        if (!empty($f['dest'])) {
            $q->where('t.destinationId', (int) $f['dest']);
        }
        if (!empty($f['days']) && isset(self::DAY_RANGES[$f['days']])) {
            [$lo, $hi] = str_ends_with($f['days'], '+') ? [(int) $f['days'], null] : array_map('intval', explode('-', $f['days']));
            $q->where('t.days', '>=', $lo);
            if ($hi) {
                $q->where('t.days', '<=', $hi);
            }
        }
        if (!empty($f['budget']) && isset(self::BUDGET_RANGES[$f['budget']])) {
            [$lo, $hi] = self::BUDGET_RANGES[$f['budget']];
            // ngân sách so theo mỗi người để chuyến đông người không bị xếp nhầm vào "đắt"
            $q->whereNotNull('t.budget')->whereRaw('t.budget / GREATEST(t.groupSize, 1) >= ?', [$lo]);
            if ($hi) {
                $q->whereRaw('t.budget / GREATEST(t.groupSize, 1) < ?', [$hi]);
            }
        }
        if (!empty($f['interest']) && preg_match('/^[a-z_]{2,20}$/', $f['interest'])) {
            $q->where('t.interests', 'like', '%"' . $f['interest'] . '"%');
        }

        $total = (clone $q)->count();
        $tab = $f['tab'] ?? 'noi-bat';
        match ($tab) {
            'moi-nhat' => $q->orderByDesc('t.publishedAt'),
            'yeu-thich' => $q->orderByDesc('t.likeCount')->orderByDesc('t.publishedAt'),
            // Nổi bật: lượt dùng lại nặng nhất, rồi lượt thích, lượt xem; giảm dần theo tuổi bài (nửa đời ~30 ngày)
            default => $q->orderByRaw('(t.cloneCount * 5 + t.likeCount * 3 + LEAST(t.viewCount, 2000) * 0.05 + 1)'
                . ' / POW(1 + TIMESTAMPDIFF(DAY, t.publishedAt, NOW()) / 30, 1.2) DESC')->orderByDesc('t.publishedAt'),
        };

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = $q->selectRaw('t.tripId, t.title, t.summary, t.days, t.groupSize, t.budget, t.interests, t.startDate, t.publishedAt,'
            . ' t.viewCount, t.likeCount, t.cloneCount, t.userId, u.fullName, u.username, u.avatar, d.name AS destName, d.coverImage AS destCover,'
            . ' (SELECT COUNT(*) FROM tbl_trip_items i WHERE i.tripId = t.tripId AND i.dayNo IS NOT NULL) AS placedCount')
            ->forPage($page, self::PER_PAGE)->get()->map(fn($r) => $this->decorate($r))->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function find(int $tripId): ?object
    {
        $r = $this->base()->where('t.tripId', $tripId)
            ->select('t.*', 'u.fullName', 'u.username', 'u.avatar', 'd.name as destName', 'd.coverImage as destCover', 'd.slug as destSlug')->first();

        return $r ? $this->decorate($r) : null;
    }

    /** Điểm đến đang có chuyến công khai (cho bộ lọc). */
    public function destinations(): array
    {
        return $this->base()->whereNotNull('t.destinationId')
            ->groupBy('d.destinationId', 'd.name')->orderBy('d.name')
            ->selectRaw('d.destinationId, d.name, COUNT(*) AS n')->get()->all();
    }

    /** Chuyến công khai khác cùng điểm đến. */
    public function related(object $trip, int $limit = 3): array
    {
        if (!$trip->destinationId) {
            return [];
        }

        return $this->base()->where('t.destinationId', $trip->destinationId)->where('t.tripId', '!=', $trip->tripId)
            ->orderByDesc('t.likeCount')->limit($limit)
            ->select('t.tripId', 't.title', 't.days', 't.likeCount', 't.cloneCount', 'd.name as destName')->get()->all();
    }

    private function decorate(object $r): object
    {
        // tên hiển thị: chỉ tên (từ cuối cùng) + chữ cái đầu họ, không lộ họ tên đầy đủ của khách
        $name = trim((string) ($r->fullName ?? ''));
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $r->author = $parts ? (count($parts) > 1 ? mb_substr($parts[0], 0, 1) . '. ' . end($parts) : $parts[0]) : ($r->username ?? 'Thành viên');
        $r->interestList = array_values(array_filter((array) json_decode((string) ($r->interests ?? ''), true), 'is_string'));
        $r->perPerson = $r->budget ? (float) $r->budget / max(1, (int) $r->groupSize) : null;

        return $r;
    }

    // ------------------------------------------------------------------ Tương tác

    /** Đếm lượt xem: mỗi phiên trình duyệt chỉ tính một lần cho một chuyến; chủ chuyến xem không tính. */
    public function countView(object $trip, ?int $viewerId): void
    {
        if ($viewerId === (int) $trip->userId) {
            return;
        }
        $seen = (array) session('community.seen', []);
        if (in_array((int) $trip->tripId, $seen, true)) {
            return;
        }
        session(['community.seen' => array_slice(array_merge($seen, [(int) $trip->tripId]), -200)]);
        DB::table('tbl_trips')->where('tripId', $trip->tripId)->increment('viewCount');
    }

    public function liked(int $userId, int $tripId): bool
    {
        return DB::table('tbl_trip_likes')->where(['tripId' => $tripId, 'userId' => $userId])->exists();
    }

    /** Bật / tắt thích. Trả [liked, likeCount]; null nếu chuyến không công khai. */
    public function toggleLike(int $userId, int $tripId): ?array
    {
        if (!$this->find($tripId)) {
            return null;
        }

        return DB::transaction(function () use ($userId, $tripId) {
            $deleted = DB::table('tbl_trip_likes')->where(['tripId' => $tripId, 'userId' => $userId])->delete();
            if (!$deleted) {
                DB::table('tbl_trip_likes')->insertOrIgnore(['tripId' => $tripId, 'userId' => $userId, 'createdAt' => now()]);
            }
            // đếm lại thay vì +1/-1 để số trên tbl_trips không lệch khi hai yêu cầu đến cùng lúc
            $n = DB::table('tbl_trip_likes')->where('tripId', $tripId)->count();
            DB::table('tbl_trips')->where('tripId', $tripId)->update(['likeCount' => $n]);

            return [!$deleted, $n];
        });
    }

    /**
     * "Dùng hành trình này": tạo chuyến mới của $userId từ một chuyến công khai.
     *
     * @param array{startDate?:?string,groupSize?:?int,budget?:?float,title?:?string} $o
     * @return int|null id chuyến mới; null nếu chuyến nguồn không còn công khai
     */
    public function cloneTrip(int $userId, int $tripId, array $o = []): ?int
    {
        $src = DB::table('tbl_trips')->where(['tripId' => $tripId, 'kind' => 'trip', 'isPublic' => 1])->first();
        if (!$src) {
            return null;
        }
        TripPlanner::assertCanCreate($userId);

        return DB::transaction(function () use ($src, $userId, $o) {
            $row = TripPlanner::freshCopyRow((array) $src);
            unset($row['tripId']);
            $row = array_merge($row, [
                'userId' => $userId,
                'title' => mb_substr(trim((string) ($o['title'] ?? '')) ?: (string) $src->title, 0, 160),
                'status' => 'draft',
                'source' => 'community',
                'startDate' => array_key_exists('startDate', $o) ? ($o['startDate'] ?: null) : null,
                'groupSize' => (int) ($o['groupSize'] ?? $src->groupSize ?: 1),
                'budget' => array_key_exists('budget', $o) ? $o['budget'] : $src->budget,
                'shareToken' => null, 'lastEditorId' => null, 'rev' => 0,
                'isPublic' => 0, 'publishedAt' => null,
                'viewCount' => 0, 'likeCount' => 0, 'cloneCount' => 0,
                'sourceTripId' => $src->tripId, 'sourceType' => 'CLONED',
                'createdAt' => now(), 'updatedAt' => now(),
            ]);
            // ngày đi mới -> tháng gợi ý theo ngày đi mới
            if (array_key_exists('month', $row)) {
                $row['month'] = $row['startDate'] ? (int) date('n', strtotime($row['startDate'])) : $src->month;
            }
            $newId = (int) DB::table('tbl_trips')->insertGetId($row);

            // chép các mục; chi phí "thực tế" của người khác không có nghĩa với chuyến mới -> đưa về dự kiến
            // không chép mục của đặt chỗ (khách sạn, chuyến bay của người chia sẻ)
            $items = collect(TripLogistics::withoutBookingRows(DB::table('tbl_trip_items')->where('tripId', $src->tripId)->orderBy('dayNo')->orderBy('orderNo')->get(), (int) $src->tripId))
                ->map(fn($r) => TripPlanner::freshCopyItem((array) $r, $newId, true))->all();
            foreach (array_chunk($items, 50) as $chunk) {
                DB::table('tbl_trip_items')->insert($chunk);
            }
            DB::table('tbl_trips')->where('tripId', $src->tripId)->increment('cloneCount');

            return $newId;
        });
    }
}
