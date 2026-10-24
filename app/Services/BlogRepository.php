<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nguồn bài blog: bảng tbl_blog_posts (đã xuất bản) nếu có bài, ngược lại dùng config/blog.php như trước.
 * Trả về mảng cùng hình dạng với config để view không phải đổi:
 * slug, title, excerpt, category, image, date, read, featured, intro, quote, sections [[tiêu đề, [đoạn...]], ...]
 */
class BlogRepository
{
    public function all(): Collection
    {
        try {
            if (Schema::hasTable('tbl_blog_posts')) {
                $rows = DB::table('tbl_blog_posts')->where('isPublished', 1)->where('publishedOn', '<=', now()->toDateString())
                    ->orderByDesc('publishedOn')->get();
                if ($rows->isNotEmpty()) {
                    return $rows->map(fn($r) => $this->toArray($r))->values();
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return collect(config('blog.posts', []))->values();
    }

    public function toArray(object $r): array
    {
        return [
            'slug' => $r->slug,
            'title' => $r->title,
            'excerpt' => (string) $r->excerpt,
            'category' => $r->category,
            'image' => $r->image ?: 'blog1.jpg',
            'date' => (string) $r->publishedOn,
            'read' => (int) $r->readMinutes,
            'featured' => (bool) $r->featured,
            'intro' => (string) $r->intro,
            'quote' => $r->quote,
            'sections' => json_decode((string) $r->sections, true) ?: [],
        ];
    }

    /** Văn bản soạn thảo -> sections. "## Tiêu đề" mở mục mới, đoạn cách nhau bằng dòng trống. */
    public static function parseSections(string $text): array
    {
        $out = [];
        $cur = null;
        foreach (preg_split('/\R{2,}/', trim(str_replace("\r", '', $text))) as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^##\s+(.+?)(?:\n(.*))?$/su', $block, $m)) {
                if ($cur) {
                    $out[] = $cur;
                }
                $cur = [trim($m[1]), []];
                if (!empty($m[2]) && trim($m[2]) !== '') {
                    $cur[1][] = trim($m[2]);
                }
            } else {
                $cur ??= ['', []];
                $cur[1][] = $block;
            }
        }
        if ($cur) {
            $out[] = $cur;
        }

        return $out;
    }

    public static function sectionsToText(array $sections): string
    {
        $parts = [];
        foreach ($sections as $s) {
            $parts[] = '## ' . ($s[0] ?? '') . "\n\n" . implode("\n\n", $s[1] ?? []);
        }

        return implode("\n\n", $parts);
    }
}
