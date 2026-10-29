<?php

namespace App\Services\Planner;

use App\Services\Content\SearchCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ "nhu cầu chưa có dữ liệu": mỗi lần người dùng tìm hoặc nhập một địa điểm/điểm đến mà kho Travela chưa có,
 * ghi lại (đã bỏ dấu, đếm số lần). Biên tập viên xem danh sách này để biết nên viết nội dung nào trước.
 * Không lưu ai tìm (chỉ cụm từ), không lưu chuỗi quá ngắn/quá dài hoặc toàn số, và có trần số dòng để không bị làm đầy.
 */
final class ContentRequests
{
    public const KINDS = ['place', 'destination'];

    /** Cụm đã chuẩn hóa dùng làm khóa; null nếu không đáng ghi. */
    public static function normalize(string $term): ?string
    {
        $f = SearchCatalog::fold(mb_substr(trim($term), 0, 100));
        if (mb_strlen($f) < 3 || mb_strlen($f) > 100 || preg_match('/^[0-9 ]+$/', $f)) {
            return null;
        }

        return $f;
    }

    public function record(string $term, string $kind = 'place', ?int $nearestDestinationId = null): bool
    {
        $f = self::normalize($term);
        if ($f === null || !in_array($kind, self::KINDS, true) || !Schema::hasTable('tbl_content_requests')) {
            return false;
        }
        $exists = DB::table('tbl_content_requests')->where('folded', $f)->exists();
        if (!$exists && DB::table('tbl_content_requests')->count() >= (int) config('planner.unknown.max_requests', 5000)) {
            return false;
        }
        DB::table('tbl_content_requests')->insertOrIgnore([
            'folded' => $f, 'sample' => mb_substr(trim(preg_replace('/\s+/u', ' ', $term) ?? $term), 0, 120), 'kind' => $kind,
            'hits' => 0, 'nearestDestinationId' => $nearestDestinationId, 'firstAt' => now(),
        ]);
        $upd = ['hits' => DB::raw('hits + 1'), 'lastAt' => now(), 'resolved' => 0];
        if ($nearestDestinationId) {
            $upd['nearestDestinationId'] = $nearestDestinationId;
        }
        DB::table('tbl_content_requests')->where('folded', $f)->update($upd);

        return true;
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public function top(bool $includeResolved = false, int $limit = 100)
    {
        if (!Schema::hasTable('tbl_content_requests')) {
            return collect();
        }

        return DB::table('tbl_content_requests as r')->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 'r.nearestDestinationId')
            ->when(!$includeResolved, fn($q) => $q->where('r.resolved', 0))
            ->orderByDesc('r.hits')->orderByDesc('r.lastAt')->limit($limit)
            ->get(['r.requestId', 'r.sample', 'r.kind', 'r.hits', 'r.firstAt', 'r.lastAt', 'r.resolved', 'd.name as nearestName']);
    }

    public function resolve(int $id, bool $on = true): bool
    {
        return DB::table('tbl_content_requests')->where('requestId', $id)->update(['resolved' => $on ? 1 : 0]) > 0;
    }
}
