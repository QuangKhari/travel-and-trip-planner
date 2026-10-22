<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Content\GuideCatalog;
use Illuminate\Http\Request;

/** C1: /cam-nang, /cam-nang/{danh-muc}, /cam-nang/{danh-muc}/{slug}. Chỉ bài published. */
class GuidePagesController extends Controller
{
    public function __construct(private GuideCatalog $guides)
    {
        parent::__construct();
    }

    // GET /cam-nang
    public function index()
    {
        return view('clients.guide.guides', [
            'title' => 'Cẩm nang du lịch',
            'groups' => $this->guides->groups(),
            'category' => null,
            'items' => null,
        ]);
    }

    // GET /cam-nang/{category}
    public function category(Request $r, string $category)
    {
        $cat = $this->guides->category($category);
        abort_unless($cat, 404);
        $page = max(1, min(500, (int) $r->query('page', 1)));
        $items = $this->guides->list($cat, $page);
        abort_if($items->total() === 0, 404);   // danh mục rỗng: 404 để không có trang trống bị lập chỉ mục

        return view('clients.guide.guides', [
            'title' => $cat->name . ' - Cẩm nang',
            'groups' => $this->guides->groups(0),
            'category' => $cat,
            'items' => $items,
        ]);
    }

    // GET /cam-nang/{category}/{slug}
    public function show(string $category, string $slug)
    {
        $g = $this->guides->find($slug);
        abort_unless($g, 404);
        $canonical = $g->catSlug ?: GuideCatalog::OTHER_SLUG;
        if ($canonical !== $category) {   // một URL chuẩn cho mỗi bài
            return redirect()->route('guides.show', ['category' => $canonical, 'slug' => $slug], 301);
        }
        $doc = $this->guides->render((string) $g->body);

        return view('clients.guide.guide', [
            'title' => $g->title,
            'guide' => $g,
            'html' => $doc['html'],
            'toc' => $doc['toc'],
            'related' => $this->guides->related($g),
            'cover' => \App\Support\DiscoverImage::url($g->coverImage),
        ]);
    }
}
