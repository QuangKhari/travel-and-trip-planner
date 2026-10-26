<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use App\Services\Content\MustDoCatalog;
use Illuminate\Http\Request;

/** /phai-thu: điểm đến, địa điểm, món, trải nghiệm, lễ hội do biên tập đánh dấu "Phải thử". Lọc theo miền, điểm đến, loại. */
class MustDoController extends Controller
{
    public function __construct(private MustDoCatalog $catalog)
    {
        parent::__construct();
    }

    public function index(Request $r)
    {
        $res = $this->catalog->groups($r->query());

        return view('clients.guide.must-do', [
            'title' => 'Nhất định phải thử',
            'groups' => $res['groups'],
            'filters' => $res['filters'],
            'total' => $res['total'],
            'labels' => MustDoCatalog::GROUPS,
            'regions' => ContentCatalog::REGIONS,
            'destinations' => $this->catalog->destinationOptions(),
            'perGroup' => MustDoCatalog::PER_GROUP,
        ]);
    }
}
