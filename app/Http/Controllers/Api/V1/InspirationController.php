<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\InspirationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/inspirations
 * GET /api/v1/inspirations/{slug}
 * GET /api/v1/inspirations/{slug}/destinations?domain=b|t|n&limit=24
 *
 * Công khai (dữ liệu hiển thị cho khách). Trip Planner / app di động dùng lại cùng endpoint.
 */
class InspirationController extends Controller
{
    public function __construct(private InspirationService $service)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->all()->map(fn($i) => $this->inspiration($i))->values()]);
    }

    public function show(string $slug): JsonResponse
    {
        $inspiration = $this->service->find($slug);

        return $inspiration
            ? response()->json(['data' => $this->inspiration($inspiration)])
            : response()->json(['message' => 'Không tìm thấy cảm hứng.'], 404);
    }

    public function destinations(Request $request, string $slug): JsonResponse
    {
        $inspiration = $this->service->find($slug);
        if (!$inspiration) {
            return response()->json(['message' => 'Không tìm thấy cảm hứng.'], 404);
        }

        $domain = $request->query('domain');
        $limit = max(1, min(50, (int) $request->query('limit', 24)));

        $rows = $this->service->destinations($inspiration, is_string($domain) ? $domain : null, $limit)
            ->map(fn($d) => [
                'slug' => $d->slug,
                'name' => $d->name,
                'province' => $d->province,
                'domain' => $d->domain,
                'description' => $d->description,
                'latitude' => (float) $d->latitude,
                'longitude' => (float) $d->longitude,
                'image' => $d->image,
                'score' => (int) $d->score,
                'tourCount' => $d->tourCount,
            ])->values();

        return response()->json(['inspiration' => $this->inspiration($inspiration), 'data' => $rows]);
    }

    private function inspiration(object $i): array
    {
        return [
            'slug' => $i->slug,
            'name' => $i->name,
            'tagline' => $i->tagline,
            'headline' => $i->headline,
            'icon' => $i->icon,
            'image' => $i->image,
            'destinationCount' => $i->destinationCount ?? null,
        ];
    }
}
