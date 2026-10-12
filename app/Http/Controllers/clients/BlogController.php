<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Danh sách blog. Nguồn dữ liệu: config/blog.php (nội dung mẫu).
 * Hỗ trợ lọc ?cat=Tên danh mục và tìm ?q=từ khóa.
 */
class BlogController extends Controller
{
    public function index(Request $request)
    {
        $title = 'Blog';

        $all = collect(config('blog.posts', []));
        $cat = trim((string) $request->query('cat', ''));
        $q = trim((string) $request->query('q', ''));

        $categories = collect(config('blog.categories', []))
            ->mapWithKeys(fn($name) => [$name => $all->where('category', $name)->count()]);

        $posts = $all
            ->when($cat !== '', fn($c) => $c->where('category', $cat))
            ->when($q !== '', fn($c) => $c->filter(
                fn($p) => mb_stripos($p['title'] . ' ' . $p['excerpt'], $q) !== false
            ))
            ->values();

        $featured = $posts->firstWhere('featured', true) ?? $posts->first();
        $rest = $posts
            ->reject(fn($p) => $featured && $p['slug'] === $featured['slug'])
            ->values();
        $latest = $all->sortByDesc('date')->take(4)->values();

        return view('clients.blogs', compact(
            'title',
            'all',
            'posts',
            'featured',
            'rest',
            'latest',
            'categories',
            'cat',
            'q'
        ));
    }
}
