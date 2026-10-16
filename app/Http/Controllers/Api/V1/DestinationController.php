<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DestinationService;
use App\Services\InspirationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/destinations?domain=b|t|n&inspiration=bien&limit=50
 * GET /api/v1/destinations/{slug}            chi tiết + thời tiết + địa điểm
 * GET /api/v1/destinations/{slug}/weather
 * GET /api/v1/destinations/{slug}/places?category=see|culture|eat
 *
 * Công khai, chỉ đọc DB (đã được các lệnh discover:* nạp sẵn). Trip Planner dùng lại các endpoint này.
 */
class DestinationController extends Controller
{
    public function __construct(private DestinationService $service, private InspirationService $inspirations)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 50)));
        $domain = $request->query('domain');
        $ins = $request->query('inspiration');

        if (is_string($ins) && $ins !== '') {
            $inspiration = $this->inspirations->find($ins);
            if (!$inspiration) {
                return response()->json(['message' => 'Không tìm thấy cảm hứng.'], 404);
            }
            $rows = $this->inspirations->destinations($inspiration, is_string($domain) ? $domain : null, $limit);
        } else {
            $q = DB::table('tbl_destinations as d')->where('d.isActive', 1)
                ->select('d.slug', 'd.name', 'd.province', 'd.domain', 'd.description', 'd.latitude', 'd.longitude', 'd.coverImage', 'd.imageCredit')
                ->orderBy('d.name')->limit($limit);
            if (is_string($domain) && isset(InspirationService::DOMAINS[$domain])) {
                $q->where('d.domain', $domain);
            }
            $rows = $q->get()->map(function ($r) {
                $r->image = \App\Support\DiscoverImage::url($r->coverImage);
                $r->url = route('destination.show', ['slug' => $r->slug]);

                return $r;
            });
        }

        return response()->json(['data' => $rows->map(fn($r) => [
            'slug' => $r->slug,
            'name' => $r->name,
            'province' => $r->province,
            'domain' => $r->domain,
            'description' => $r->description,
            'latitude' => (float) $r->latitude,
            'longitude' => (float) $r->longitude,
            'image' => $r->image,
            'imageCredit' => $r->imageCredit,
            'url' => $r->url,
            'score' => isset($r->score) ? (int) $r->score : null,
        ])->values()]);
    }

    public function show(string $slug): JsonResponse
    {
        $d = $this->service->find($slug);

        return $d
            ? response()->json(['data' => $this->service->payload($d)])
            : response()->json(['message' => 'Không tìm thấy điểm đến.'], 404);
    }

    public function weather(string $slug): JsonResponse
    {
        $d = $this->service->find($slug);
        if (!$d) {
            return response()->json(['message' => 'Không tìm thấy điểm đến.'], 404);
        }

        return response()->json([
            'data' => $this->service->weather($d),
            'attribution' => 'Weather data by Open-Meteo.com (CC BY 4.0)',
        ]);
    }

    public function places(Request $request, string $slug): JsonResponse
    {
        $d = $this->service->find($slug);
        if (!$d) {
            return response()->json(['message' => 'Không tìm thấy điểm đến.'], 404);
        }
        $cat = $request->query('category');
        $cat = is_string($cat) && in_array($cat, ['see', 'culture', 'eat'], true) ? $cat : null;

        return response()->json([
            'data' => $this->service->places($d, $cat),
            'attribution' => '© OpenStreetMap contributors',
        ]);
    }
}
