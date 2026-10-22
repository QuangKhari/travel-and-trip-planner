<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Content\SearchCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** C3: GET /api/v1/search?q= (nhóm theo loại) và GET /api/v1/search/suggest?q= (gợi ý khi gõ). */
class SearchController extends Controller
{
    private function q(Request $r): ?string
    {
        $q = $r->query('q');

        return is_string($q) && mb_strlen(trim($q)) >= 2 ? trim(mb_substr($q, 0, 60)) : null;
    }

    public function index(Request $r, SearchCatalog $search): JsonResponse
    {
        $q = $this->q($r);
        if ($q === null) {
            return response()->json(['message' => 'q cần từ 2 ký tự trở lên'], 422);
        }
        $groups = [];
        foreach ($search->search($q, 10) as $type => $g) {
            $groups[$type] = ['label' => $g['label'], 'total' => $g['total'], 'items' => array_map(fn($o) => [
                'slug' => $o->slug, 'title' => $o->title, 'meta' => $o->meta ?: $o->badge, 'url' => $o->url,
            ], $g['items'])];
        }

        return response()->json(['q' => $q, 'groups' => $groups]);
    }

    public function suggest(Request $r, SearchCatalog $search): JsonResponse
    {
        $q = $this->q($r);

        return response()->json(['q' => $q ?? '', 'items' => $q === null ? [] : $search->suggest($q, 8)]);
    }
}
