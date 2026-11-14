<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bàn bạc trong chuyến nhóm (bình luận + bỏ phiếu "muốn đi / không" cho từng mục) và đánh giá nơi đã ghé sau chuyến.
 * Khóa mục (itemKey) = "type:refId" cho mục trong kho, "custom:<tên>" cho mục tự thêm: bền qua các lần lưu (itemId đổi mỗi lần lưu).
 */
class TripSocialController extends Controller
{
    private function access(int $id): array
    {
        return app(TripSharing::class)->access((int) session('userId'), $id) ?? abort(404);
    }

    /** GET /len-ke-hoach/{id}/ban-bac : mọi bình luận + phiếu của chuyến */
    public function index(int $id): JsonResponse
    {
        $this->access($id);
        abort_unless(Schema::hasTable('tbl_trip_comments'), 404);
        $uid = (int) session('userId');
        $out = [];
        foreach (DB::table('tbl_trip_comments as c')->join('tbl_users as u', 'u.userId', '=', 'c.userId')->where('c.tripId', $id)
            ->orderBy('c.commentId')->limit(500)->get(['c.commentId', 'c.itemKey', 'c.text', 'c.createdAt', 'c.userId', 'u.fullName', 'u.username']) as $c) {
            $out[$c->itemKey]['comments'][] = ['id' => (int) $c->commentId, 'by' => $c->fullName ?: $c->username, 'mine' => (int) $c->userId === $uid,
                'text' => $c->text, 'at' => \Carbon\Carbon::parse($c->createdAt)->toIso8601String()];
        }
        foreach (DB::table('tbl_trip_votes as v')->join('tbl_users as u', 'u.userId', '=', 'v.userId')->where('v.tripId', $id)->get(['v.itemKey', 'v.vote', 'v.userId', 'u.fullName', 'u.username']) as $v) {
            $k = $v->vote > 0 ? 'up' : 'down';
            $out[$v->itemKey][$k][] = $v->fullName ?: $v->username;
            if ((int) $v->userId === $uid) {
                $out[$v->itemKey]['mine'] = (int) $v->vote;
            }
        }

        return response()->json(['items' => (object) $out]);
    }

    public function comment(Request $request, int $id): JsonResponse
    {
        $this->access($id);
        $v = $request->validate(['itemKey' => ['required', 'string', 'max:80', 'regex:/^(place|experience|food|event|tour|destination|custom):.+$/u'], 'text' => 'required|string|max:500']);
        DB::table('tbl_trip_comments')->insert(['tripId' => $id, 'userId' => (int) session('userId'), 'itemKey' => $v['itemKey'], 'text' => strip_tags($v['text']), 'createdAt' => now()]);

        return $this->index($id);
    }

    public function deleteComment(int $id, int $commentId): JsonResponse
    {
        $this->access($id);
        DB::table('tbl_trip_comments')->where('tripId', $id)->where('commentId', $commentId)->where('userId', (int) session('userId'))->delete();

        return $this->index($id);
    }

    public function vote(Request $request, int $id): JsonResponse
    {
        $this->access($id);
        $v = $request->validate(['itemKey' => ['required', 'string', 'max:80', 'regex:/^(place|experience|food|event|tour|destination|custom):.+$/u'], 'vote' => 'required|integer|in:-1,0,1']);
        $key = ['tripId' => $id, 'userId' => (int) session('userId'), 'itemKey' => $v['itemKey']];
        if ((int) $v['vote'] === 0) {
            DB::table('tbl_trip_votes')->where($key)->delete();
        } else {
            DB::table('tbl_trip_votes')->updateOrInsert($key, ['vote' => (int) $v['vote'], 'createdAt' => now()]);
        }

        return $this->index($id);
    }

    /** POST /len-ke-hoach/{id}/danh-gia : đánh giá một nơi đã ghé (sau khi đánh dấu "Xong") */
    public function review(Request $request, int $id): JsonResponse
    {
        $acc = $this->access($id);
        abort_unless(Schema::hasTable('tbl_place_reviews'), 404);
        $v = $request->validate(['type' => 'required|in:place,experience,food', 'refId' => 'required|integer|min:1', 'stars' => 'required|integer|between:1,5',
            'stayMin' => 'nullable|integer|between:5,720', 'crowded' => 'nullable|boolean', 'text' => 'nullable|string|max:500']);
        $visited = DB::table('tbl_trip_items')->where('tripId', $id)->where('refType', $v['type'])->where('refId', $v['refId'])->whereNotNull('visitedAt')->exists();
        if (!$visited) {
            return response()->json(['message' => 'Chỉ đánh giá được nơi đã đánh dấu "Xong" trong chuyến.'], 422);
        }
        DB::table('tbl_place_reviews')->updateOrInsert(['userId' => (int) session('userId'), 'tripId' => $id, 'refType' => $v['type'], 'refId' => (int) $v['refId']],
            ['stars' => (int) $v['stars'], 'stayMin' => $v['stayMin'] ?? null, 'crowded' => isset($v['crowded']) ? (bool) $v['crowded'] : null,
                'text' => isset($v['text']) ? strip_tags($v['text']) : null, 'createdAt' => now()]);

        return response()->json(['ok' => true, 'message' => 'Cảm ơn bạn. Đánh giá giúp Travela gợi ý tốt hơn cho người đi sau.', 'reviews' => $this->mine($id)]);
    }

    /** GET /len-ke-hoach/{id}/danh-gia : đánh giá của tôi trong chuyến (để tô sao đã chọn) */
    public function myReviews(int $id): JsonResponse
    {
        $this->access($id);

        return response()->json(['reviews' => $this->mine($id)]);
    }

    private function mine(int $id): array
    {
        if (!Schema::hasTable('tbl_place_reviews')) {
            return [];
        }

        return DB::table('tbl_place_reviews')->where('tripId', $id)->where('userId', (int) session('userId'))->get(['refType', 'refId', 'stars', 'stayMin', 'crowded'])
            ->mapWithKeys(fn($r) => [$r->refType . ':' . $r->refId => ['stars' => (int) $r->stars, 'stayMin' => $r->stayMin, 'crowded' => $r->crowded]])->all();
    }
}
