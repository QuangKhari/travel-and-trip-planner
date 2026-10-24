<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** CRUD danh mục cẩm nang (tbl_guide_categories). */
class GuideCategoryController extends Controller
{
    public function index()
    {
        return view('admin.guide-categories', [
            'title' => 'Danh mục cẩm nang',
            'rows' => DB::table('tbl_guide_categories as c')
                ->leftJoin(DB::raw('(SELECT categoryId, COUNT(*) n FROM tbl_guides GROUP BY categoryId) g'), 'g.categoryId', '=', 'c.categoryId')
                ->select('c.*', DB::raw('COALESCE(g.n,0) as guideCount'))->orderBy('c.displayOrder')->orderBy('c.name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'max:100'],
            'slug' => ['nullable', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tbl_guide_categories,slug'],
            'displayOrder' => ['nullable', 'integer', 'between:0,255'],
        ], ['slug.unique' => 'Slug đã tồn tại.']);
        $slug = $d['slug'] ?: Str::slug($d['name']);
        if ($slug === 'khac' || DB::table('tbl_guide_categories')->where('slug', $slug)->exists()) {
            return back()->withInput()->with('error', 'Slug "' . $slug . '" đã được dùng (slug "khac" dành cho mục "Khác" tự động).');
        }
        DB::table('tbl_guide_categories')->insert(['name' => $d['name'], 'slug' => $slug, 'displayOrder' => (int) ($d['displayOrder'] ?? 0)]);
        PublicCache::flush();

        return back()->with('success', 'Đã thêm danh mục.');
    }

    public function update(Request $request, int $id)
    {
        DB::table('tbl_guide_categories')->where('categoryId', $id)->exists() || abort(404);
        $d = $request->validate([
            'name' => ['required', 'max:100'],
            'slug' => ['required', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tbl_guide_categories', 'slug')->ignore($id, 'categoryId'), Rule::notIn(['khac'])],
            'displayOrder' => ['required', 'integer', 'between:0,255'],
        ]);
        DB::table('tbl_guide_categories')->where('categoryId', $id)->update($d);
        PublicCache::flush();

        return back()->with('success', 'Đã lưu.');
    }

    public function destroy(int $id)
    {
        if (DB::table('tbl_guides')->where('categoryId', $id)->exists()) {
            return back()->with('error', 'Còn bài cẩm nang trong danh mục này. Chuyển các bài sang danh mục khác trước.');
        }
        DB::table('tbl_guide_categories')->where('categoryId', $id)->delete();
        PublicCache::flush();

        return back()->with('success', 'Đã xóa danh mục.');
    }
}
