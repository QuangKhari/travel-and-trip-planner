<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;

/**
 * Chi tiết blog theo slug. Không truyền slug thì mở bài nổi bật (giữ tương thích route cũ).
 */
class BlogDetailController extends Controller
{
    public function index($slug = null)
    {
        $all = collect(config('blog.posts', []))->values();

        $post = $slug
            ? $all->firstWhere('slug', $slug)
            : ($all->firstWhere('featured', true) ?? $all->first());

        if (!$post) {
            abort(404);
        }

        $idx = $all->search(fn($p) => $p['slug'] === $post['slug']);
        $prev = $idx > 0 ? $all[$idx - 1] : null;
        $next = $idx < $all->count() - 1 ? $all[$idx + 1] : null;

        $related = $all
            ->filter(fn($p) => $p['slug'] !== $post['slug'])
            ->sortByDesc(fn($p) => $p['category'] === $post['category'] ? 1 : 0)
            ->take(3)
            ->values();

        $title = $post['title'];

        return view('clients.blog-detail', compact('title', 'post', 'prev', 'next', 'related'));
    }
}
