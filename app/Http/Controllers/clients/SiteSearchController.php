<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\SearchCatalog;
use Illuminate\Http\Request;

/** C3: /tim-kiem?q= — kết quả nhóm theo loại. Không thay /search (cửa vào tìm tour). */
class SiteSearchController extends Controller
{
    public function __construct(private SearchCatalog $search)
    {
        parent::__construct();
    }

    public function index(Request $r)
    {
        $raw = $r->query('q', $r->query('keyword', ''));
        $q = is_string($raw) ? trim(mb_substr($raw, 0, 60)) : '';
        $groups = $q === '' ? [] : $this->search->search($q, 6);

        return view('clients.guide.search', [
            'title' => $q === '' ? 'Tìm kiếm' : 'Tìm "' . $q . '"',
            'q' => $q,
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'total')),
            'noindex' => true,   // trang kết quả không đưa vào chỉ mục
        ]);
    }
}
