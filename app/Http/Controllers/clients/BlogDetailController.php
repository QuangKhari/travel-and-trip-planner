<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\TourCatalog;

/**
 * Chi tiết blog theo slug. Không truyền slug thì mở bài nổi bật (giữ tương thích route cũ).
 */
class BlogDetailController extends Controller
{
    public function index($slug = null)
    {
        $all = app(\App\Services\BlogRepository::class)->all();

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

        // Tour liên quan: bài nhắc tới điểm đến nào đang có tour thì gợi ý tối đa 3 tour sắp khởi hành của điểm đó.
        // Đây là chỗ biến độc giả thành người đặt tour, và chỉ hiện khi có dữ liệu thật.
        $tours = collect();
        $tourDest = null;
        try {
            $catalog = new TourCatalog();
            $text = mb_strtolower($post['title'] . ' ' . ($post['excerpt'] ?? '') . ' ' . ($post['intro'] ?? ''));
            $match = $catalog->destinations()->first(
                fn($d) => $d->name !== '' && mb_strpos($text, mb_strtolower($d->name)) !== false
            );
            if ($match) {
                $tourDest = $match->name;
                $tours = $catalog->paginate(['keyword' => $match->name, 'sort' => 'soon', 'page' => 1], 3)->getCollection();
            }
        } catch (\Throwable $e) {
            report($e); // lỗi gợi ý tour không được làm hỏng trang bài viết
        }

        $title = $post['title'];

        return view('clients.blog-detail', compact('title', 'post', 'prev', 'next', 'related', 'tours', 'tourDest'));
    }
}
