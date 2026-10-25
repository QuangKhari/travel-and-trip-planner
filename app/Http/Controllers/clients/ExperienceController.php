<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use App\Services\Content\ExperienceCatalog;
use Illuminate\Http\Request;

/** /trai-nghiem và /trai-nghiem/{slug}: danh sách có lọc phía server + trang chi tiết có nút nối sang tour. */
class ExperienceController extends Controller
{
    public function __construct(private ExperienceCatalog $catalog)
    {
        parent::__construct();
    }

    public function index(Request $r)
    {
        $page = max(1, min(500, (int) $r->query('page', 1)));
        $res = $this->catalog->list($r->query(), $page);
        $provinces = $this->catalog->provinces();

        return view('clients.guide.experiences', [
            'title' => 'Trải nghiệm',
            'hero' => $this->catalog->featured(3),
            'items' => $res['items'],
            'filters' => $res['filters'],
            'total' => $res['total'],
            'regions' => ContentCatalog::REGIONS,
            'groupCounts' => $this->catalog->groupCounts($res['filters']['mien'], $res['filters']['tinh']),
            'provinces' => array_combine($provinces, $provinces),
        ]);
    }

    public function show(string $slug)
    {
        $e = $this->catalog->find($slug);
        abort_unless($e, 404);

        return view('clients.guide.experience', [
            'title' => $e->name,
            'e' => $e,
            'cover' => \App\Support\DiscoverImage::url($e->coverImage),
            'related' => $this->catalog->related($e, 6),
            'tours' => $this->catalog->toursOf($e->destinationId ? (int) $e->destinationId : null, 3),
        ]);
    }
}
