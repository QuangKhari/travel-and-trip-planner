<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use Illuminate\Http\Request;

/** C2: /dang-vao-mua (điểm đến và lễ hội của một tháng) và /nen-di-thang-nao (ma trận 12 tháng). */
class SeasonPagesController extends Controller
{
    public function __construct(private ContentCatalog $catalog)
    {
        parent::__construct();
    }

    // GET /dang-vao-mua?thang=
    public function now(Request $r)
    {
        $explicit = ContentCatalog::validMonth($r->query('thang'));
        $month = $explicit ?? (int) date('n');
        $dest = $this->catalog->destinations(['thang' => $month], 1, 24);
        $events = $this->catalog->events(['thang' => $month], 1, 12);

        return view('clients.guide.season-now', [
            'title' => 'Đang vào mùa: tháng ' . $month,
            'month' => $month,
            'isCurrent' => $month === (int) date('n'),
            'destinations' => $dest['items'],
            'events' => $events['items'],
            'canonical' => route('seasons.now', $explicit ? ['thang' => $explicit] : []),
        ]);
    }

    // GET /nen-di-thang-nao  và  /nen-di-thang-nao/{thang}
    public function matrix(Request $r, ?int $thang = null)
    {
        abort_if($thang !== null && ($thang < 1 || $thang > 12), 404);
        $region = ContentCatalog::validRegion($r->query('mien'));
        $rows = collect($this->catalog->seasonMatrix($region))->groupBy('domain');
        $best = $thang ? $this->catalog->destinations(['thang' => $thang, 'mien' => $region], 1, 24)['items'] : null;

        return view('clients.guide.season-matrix', [
            'title' => $thang ? 'Nên đi tháng ' . $thang . ' ở đâu' : 'Nên đi tháng nào',
            'groups' => $rows,
            'regions' => ContentCatalog::REGIONS,
            'region' => $region,
            'month' => $thang,
            'best' => $best,
            'filters' => ['mien' => $region],   // header tự gắn noindex khi đang lọc theo miền
            'canonical' => $thang ? route('seasons.matrix', ['thang' => $thang]) : route('seasons.matrix'),
        ]);
    }
}
