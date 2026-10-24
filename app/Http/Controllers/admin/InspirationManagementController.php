<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\CoverUploader;
use App\Services\Content\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** CRUD cảm hứng (tbl_inspirations). Điểm từng điểm đến cho cảm hứng sửa ở màn hình điểm đến. */
class InspirationManagementController extends Controller
{
    public function index(Request $request)
    {
        $editId = (int) $request->query('edit', 0);

        return view('admin.inspirations', [
            'title' => 'Cảm hứng',
            'rows' => DB::table('tbl_inspirations as i')
                ->leftJoin(DB::raw('(SELECT inspirationId, COUNT(*) c FROM tbl_destination_inspiration GROUP BY inspirationId) x'), 'x.inspirationId', '=', 'i.inspirationId')
                ->select('i.*', DB::raw('COALESCE(x.c,0) as destCount'))->orderBy('i.displayOrder')->get(),
            'edit' => $editId ? DB::table('tbl_inspirations')->where('inspirationId', $editId)->first() : null,
        ]);
    }

    private function data(Request $request, ?int $id): array
    {
        $rules = [
            'name' => ['required', 'max:80'],
            'slug' => ['nullable', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tbl_inspirations', 'slug')->ignore($id, 'inspirationId')],
            'tagline' => ['required', 'max:160'],
            'headline' => ['required', 'max:200'],
            'icon' => ['required', 'max:40', 'regex:/^[a-z0-9\- ]+$/i'],
            'displayOrder' => ['required', 'integer', 'between:0,255'],
            'cover' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:' . (int) config('media.limits.max_file_kb', 8192)],
        ];
        $d = $request->validate($rules, ['slug.unique' => 'Slug đã tồn tại.', 'icon.regex' => 'Icon là tên class Font Awesome, vd fa-water.']);
        $d['isActive'] = $request->boolean('isActive') ? 1 : 0;

        return $d;
    }

    private function cover(array &$d, ?object $row, string $slug): ?string
    {
        $file = $d['cover'] ?? null;
        unset($d['cover']);
        if (!$file) {
            return null;
        }
        try {
            $up = app(CoverUploader::class);
            $path = $up->store($file, 'discover/inspirations', $slug, 200, false);
            if ($row && $row->coverImage && $row->coverImage !== $path) {
                $up->delete($row->coverImage);
            }
            $d['coverImage'] = $path;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function store(Request $request)
    {
        $d = $this->data($request, null);
        $d['slug'] = $d['slug'] ?: Str::slug($d['name']);
        if (DB::table('tbl_inspirations')->where('slug', $d['slug'])->exists()) {
            return back()->withInput()->with('error', 'Slug đã tồn tại, nhập slug khác.');
        }
        $err = $this->cover($d, null, $d['slug']);
        DB::table('tbl_inspirations')->insert($d);
        PublicCache::flush();

        return redirect()->route('admin.inspirations')->with($err ? 'error' : 'success', $err ? "Đã thêm, ảnh chưa lưu: {$err}" : 'Đã thêm cảm hứng.');
    }

    public function update(Request $request, int $id)
    {
        $row = DB::table('tbl_inspirations')->where('inspirationId', $id)->first() ?? abort(404);
        $d = $this->data($request, $id);
        $d['slug'] = $d['slug'] ?: $row->slug;
        $err = $this->cover($d, $row, $d['slug']);
        DB::table('tbl_inspirations')->where('inspirationId', $id)->update($d);
        PublicCache::flush();

        return redirect()->route('admin.inspirations')->with($err ? 'error' : 'success', $err ? "Đã lưu, ảnh chưa lưu: {$err}" : 'Đã lưu.');
    }

    public function destroy(int $id)
    {
        $row = DB::table('tbl_inspirations')->where('inspirationId', $id)->first() ?? abort(404);
        if (DB::table('tbl_destination_inspiration')->where('inspirationId', $id)->exists()) {
            return back()->with('error', 'Còn điểm đến gắn với cảm hứng này. Hãy tắt hiển thị thay vì xóa.');
        }
        DB::table('tbl_inspirations')->where('inspirationId', $id)->delete();
        app(CoverUploader::class)->delete($row->coverImage);
        PublicCache::flush();

        return back()->with('success', 'Đã xóa.');
    }
}
