<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\BlogRepository;
use App\Services\Content\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Quản lý bài blog trong DB. Chưa có bài xuất bản nào thì trang công khai vẫn dùng config/blog.php. */
class BlogAdminController extends Controller
{
    public function index()
    {
        return view('admin.blog.index', [
            'title' => 'Blog',
            'rows' => DB::table('tbl_blog_posts')->orderByDesc('publishedOn')->get(),
            'usingConfig' => !DB::table('tbl_blog_posts')->where('isPublished', 1)->exists(),
        ]);
    }

    public function create()
    {
        return view('admin.blog.edit', ['title' => 'Thêm bài blog', 'row' => null, 'categories' => config('blog.categories', []), 'text' => '']);
    }

    public function edit(int $id)
    {
        $row = DB::table('tbl_blog_posts')->where('postId', $id)->first() ?? abort(404);

        return view('admin.blog.edit', [
            'title' => 'Sửa bài blog', 'row' => $row, 'categories' => config('blog.categories', []),
            'text' => BlogRepository::sectionsToText(json_decode($row->sections, true) ?: []),
        ]);
    }

    private function data(Request $request, ?int $id): array
    {
        $d = $request->validate([
            'title' => ['required', 'max:200'],
            'slug' => ['nullable', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tbl_blog_posts', 'slug')->ignore($id, 'postId')],
            'excerpt' => ['nullable', 'max:400'],
            'category' => ['required', 'max:60'],
            'publishedOn' => ['required', 'date'],
            'readMinutes' => ['required', 'integer', 'between:1,60'],
            'intro' => ['nullable'],
            'quote' => ['nullable', 'max:255'],
            'body' => ['required'],
            'imageFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image' => ['nullable', 'max:160', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ], ['slug.unique' => 'Slug đã có bài khác dùng.', 'body.required' => 'Nhập nội dung bài.']);

        $d['sections'] = json_encode(BlogRepository::parseSections($d['body']), JSON_UNESCAPED_UNICODE);
        unset($d['body']);
        $d['featured'] = $request->boolean('featured') ? 1 : 0;
        $d['isPublished'] = $request->boolean('isPublished') ? 1 : 0;

        if ($request->hasFile('imageFile')) {
            $f = $request->file('imageFile');
            $name = 'blog-' . Str::random(10) . '.' . $f->guessExtension();
            $f->move(public_path('clients/assets/images/blog'), $name);   // đúng thư mục view đang đọc
            $d['image'] = $name;
        }
        unset($d['imageFile']);

        return array_map(fn($v) => is_string($v) && trim($v) === '' ? null : $v, $d);
    }

    public function store(Request $request)
    {
        $d = $this->data($request, null);
        $d['slug'] = $d['slug'] ?? Str::slug($d['title']);
        if (DB::table('tbl_blog_posts')->where('slug', $d['slug'])->exists()) {
            return back()->withInput()->with('error', 'Slug đã tồn tại, nhập slug khác.');
        }
        DB::table('tbl_blog_posts')->insert($d + ['createdAt' => now()]);
        PublicCache::flush(false);

        return redirect()->route('admin.blog')->with('success', 'Đã thêm bài.');
    }

    public function update(Request $request, int $id)
    {
        DB::table('tbl_blog_posts')->where('postId', $id)->exists() || abort(404);
        $d = $this->data($request, $id);
        if (empty($d['slug'])) {
            unset($d['slug']);
        }
        DB::table('tbl_blog_posts')->where('postId', $id)->update($d + ['updatedAt' => now()]);
        PublicCache::flush(false);

        return redirect()->route('admin.blog')->with('success', 'Đã lưu.');
    }

    public function destroy(int $id)
    {
        DB::table('tbl_blog_posts')->where('postId', $id)->delete();

        return back()->with('success', 'Đã xóa.');
    }

    /** Nhập 7 bài mẫu từ config/blog.php vào DB (ở trạng thái chưa xuất bản) để sửa tiếp trong admin. */
    public function importConfig()
    {
        $n = 0;
        foreach (config('blog.posts', []) as $p) {
            if (DB::table('tbl_blog_posts')->where('slug', $p['slug'])->exists()) {
                continue;
            }
            DB::table('tbl_blog_posts')->insert([
                'slug' => $p['slug'], 'title' => $p['title'], 'excerpt' => $p['excerpt'] ?? null, 'category' => $p['category'],
                'image' => $p['image'] ?? null, 'publishedOn' => $p['date'], 'readMinutes' => (int) ($p['read'] ?? 3),
                'featured' => !empty($p['featured']) ? 1 : 0, 'intro' => $p['intro'] ?? null, 'quote' => $p['quote'] ?? null,
                'sections' => json_encode($p['sections'] ?? [], JSON_UNESCAPED_UNICODE), 'isPublished' => 0, 'createdAt' => now(),
            ]);
            $n++;
        }

        return back()->with('success', "Đã nhập {$n} bài mẫu (chưa xuất bản). Tick \"Xuất bản\" từng bài để chuyển trang công khai sang dùng DB.");
    }
}
