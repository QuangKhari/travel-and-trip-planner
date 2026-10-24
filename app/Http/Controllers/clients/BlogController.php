<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Danh sách blog. Nguồn dữ liệu: config/blog.php (nội dung mẫu).
 * Hỗ trợ lọc ?cat=Tên danh mục, tìm ?q=từ khóa và phân trang ?page=.
 */
class BlogController extends Controller
{
    private const PER_PAGE = 9;

    public function index(Request $request)
    {
        $title = 'Blog';

        // Bài mới nhất luôn đứng đầu (trước đây theo thứ tự khai báo trong file config)
        $all = app(\App\Services\BlogRepository::class)->all()->sortByDesc('date')->values();
        $cat = trim((string) $request->query('cat', ''));
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $page = max(1, (int) $request->query('page', 1));

        $categories = collect(config('blog.categories', []))
            ->mapWithKeys(fn($name) => [$name => $all->where('category', $name)->count()]);

        $posts = $all
            ->when($cat !== '', fn($c) => $c->where('category', $cat))
            ->when($q !== '', fn($c) => $c->filter(
                fn($p) => mb_stripos($p['title'] . ' ' . $p['excerpt'] . ' ' . $p['category'], $q) !== false
            ))
            ->values();

        $isFiltered = $cat !== '' || $q !== '';

        // Bài nổi bật chỉ tách riêng khi xem toàn bộ; đang lọc/tìm thì hiện một danh sách duy nhất cho dễ so sánh
        $featuredPost = !$isFiltered ? ($posts->firstWhere('featured', true) ?? $posts->first()) : null;
        $list = $posts
            ->reject(fn($p) => $featuredPost && $p['slug'] === $featuredPost['slug'])
            ->values();
        $featured = $page === 1 ? $featuredPost : null;

        $rest = new LengthAwarePaginator(
            $list->forPage($page, self::PER_PAGE)->values(),
            $list->count(),
            self::PER_PAGE,
            $page,
            ['path' => route('blogs'), 'query' => $request->except('page')]
        );

        $latest = $all->take(4)->values();
        $total = $posts->count();

        return view('clients.blogs', compact(
            'title',
            'all',
            'posts',
            'featured',
            'rest',
            'latest',
            'categories',
            'cat',
            'q',
            'total',
            'isFiltered'
        ));
    }
}
