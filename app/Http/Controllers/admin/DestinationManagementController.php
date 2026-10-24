<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Climate\ClimateRules;
use App\Services\Content\CoverUploader;
use App\Services\Content\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Quản lý điểm đến (tbl_destinations) cùng hai bảng gắn kèm:
 *  - tbl_destination_months: mùa đẹp 12 tháng (best / ok / avoid + ghi chú); sửa tay ghi source = manual,
 *    nên lệnh climate:build-months không ghi đè (trừ khi dùng --force).
 *  - tbl_destination_inspiration: điểm hợp từng cảm hứng (0-100).
 * Chỉ admin/manager.
 */
class DestinationManagementController extends Controller
{
    private const REGIONS = ['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'];

    public function index(Request $request)
    {
        $q = DB::table('tbl_destinations as d')
            ->leftJoin(DB::raw('(SELECT destinationId, COUNT(*) c FROM tbl_destination_months GROUP BY destinationId) m'), 'm.destinationId', '=', 'd.destinationId')
            ->leftJoin(DB::raw('(SELECT destinationId, COUNT(*) c FROM tbl_tours GROUP BY destinationId) t'), 't.destinationId', '=', 'd.destinationId')
            ->select('d.*', DB::raw('COALESCE(m.c,0) as monthCount'), DB::raw('COALESCE(t.c,0) as tourCount'));

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $q->where(fn($w) => $w->where('d.name', 'like', $like)->orWhere('d.province', 'like', $like));
        }
        if (array_key_exists((string) $request->query('mien'), self::REGIONS)) {
            $q->where('d.domain', $request->query('mien'));
        }
        $filter = (string) $request->query('filter', '');
        if ($filter === 'inactive') {
            $q->where('d.isActive', 0);
        } elseif ($filter === 'noimage') {
            $q->where(fn($w) => $w->whereNull('d.coverImage')->orWhere('d.coverImage', ''));
        } elseif ($filter === 'nomonths') {
            $q->whereRaw('COALESCE(m.c,0) < 12');
        } elseif ($filter === 'notour') {
            $q->whereRaw('COALESCE(t.c,0) = 0');
        }

        return view('admin.destinations.index', [
            'title' => 'Điểm đến',
            'rows' => $q->orderBy('d.name')->paginate(30)->withQueryString(),
            'search' => $search,
            'mien' => (string) $request->query('mien', ''),
            'filter' => $filter,
            'regions' => self::REGIONS,
        ]);
    }

    private function formView(?object $row)
    {
        $id = $row->destinationId ?? null;
        $months = $id ? DB::table('tbl_destination_months')->where('destinationId', $id)->get()->keyBy('month') : collect();
        $climate = $id ? DB::table('tbl_destination_climate')->where('destinationId', $id)->get()->keyBy('month') : collect();

        $suggest = [];
        if ($climate->isNotEmpty()) {
            $arr = [];
            foreach ($climate as $mo => $c) {
                $arr[(int) $mo] = ['tMax' => (float) $c->tMax, 'tMin' => (float) $c->tMin, 'rainMm' => (float) $c->rainMm, 'rainyDays' => (float) $c->rainyDays];
            }
            $suggest = ClimateRules::evaluate($arr, config('climate.rules'));
        }

        return view('admin.destinations.edit', [
            'title' => $id ? 'Sửa điểm đến' : 'Thêm điểm đến',
            'row' => $row ?? (object) ['isActive' => 1, 'domain' => 't'],
            'id' => $id,
            'regions' => self::REGIONS,
            'months' => $months,
            'climate' => $climate,
            'suggest' => $suggest,
            'inspirations' => DB::table('tbl_inspirations')->orderBy('displayOrder')->get(),
            'scores' => $id ? DB::table('tbl_destination_inspiration')->where('destinationId', $id)->pluck('score', 'inspirationId') : collect(),
            'counts' => $id ? [
                'tours' => DB::table('tbl_tours')->where('destinationId', $id)->count(),
                'foods' => DB::table('tbl_foods')->where('destinationId', $id)->count(),
                'events' => DB::table('tbl_events')->where('destinationId', $id)->count(),
                'places' => DB::table('tbl_external_places')->where('destinationId', $id)->count(),
            ] : null,
        ]);
    }

    public function create()
    {
        return $this->formView(null);
    }

    public function edit(int $id)
    {
        return $this->formView(DB::table('tbl_destinations')->where('destinationId', $id)->first() ?? abort(404));
    }

    private function validated(Request $request, ?int $id): array
    {
        $rules = [
            'name' => ['required', 'max:120'],
            'slug' => ['nullable', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tbl_destinations', 'slug')->ignore($id, 'destinationId')],
            'province' => ['required', 'max:120'],
            'domain' => ['required', 'in:b,t,n'],
            'description' => ['required', 'min:10'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'wikiTitle' => ['nullable', 'max:160'],
            'imageCredit' => ['nullable', 'max:255'],
            'imageSourceUrl' => ['nullable', 'max:255', 'regex:/^https?:\/\//i'],
            'cover' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:' . (int) config('media.limits.max_file_kb', 8192)],
            'removeCover' => ['nullable', 'boolean'],
        ];
        if ($request->hasFile('cover')) {
            $rules['imageCredit'] = ['required', 'max:255'];    // ảnh tải lên bắt buộc có ghi công
        }
        $data = $request->validate($rules, [
            'slug.regex' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Slug này đã có điểm đến khác dùng.',
            'imageCredit.required' => 'Tải ảnh lên thì phải ghi công ảnh.',
            'description.required' => 'Nhập mô tả (tối thiểu 10 ký tự).',
        ]);

        foreach (['isActive', 'isMustTry', 'isFeatured', 'isHero'] as $b) {
            $data[$b] = $request->boolean($b) ? 1 : 0;
        }

        return array_map(fn($v) => is_string($v) && trim($v) === '' ? null : $v, $data);
    }

    private function saveCover(array &$data, ?object $row, string $slug): ?string
    {
        $cover = $data['cover'] ?? null;
        $remove = !empty($data['removeCover']);
        unset($data['cover'], $data['removeCover']);
        $up = app(CoverUploader::class);

        try {
            if ($cover) {
                $path = $up->store($cover, 'discover/destinations', $slug, 200);   // ảnh điểm đến cho phép 200 KB
                if ($row && $row->coverImage && $row->coverImage !== $path) {
                    $up->delete($row->coverImage);
                }
                $data['coverImage'] = $path;
            } elseif ($remove && $row && $row->coverImage) {
                $up->delete($row->coverImage);
                $data['coverImage'] = null;
                $data['imageSourceUrl'] = null;
            }
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $data['slug'] = $data['slug'] ?? $this->makeSlug((string) $data['name']);
        $coverErr = $this->saveCover($data, null, $data['slug']);

        $id = (int) DB::table('tbl_destinations')->insertGetId($data, 'destinationId');
        PublicCache::flush();

        $msg = 'Đã thêm điểm đến. Bổ sung mùa đẹp và cảm hứng ở các khối bên dưới.';

        return redirect()->route('admin.destinations.edit', $id)->with($coverErr ? 'error' : 'success', $coverErr ? "Đã thêm, nhưng ảnh chưa lưu được: {$coverErr}" : $msg);
    }

    public function update(Request $request, int $id)
    {
        $row = DB::table('tbl_destinations')->where('destinationId', $id)->first() ?? abort(404);
        $data = $this->validated($request, $id);
        if (empty($data['slug'])) {
            unset($data['slug']);
        }
        if ($err = $this->saveCover($data, $row, $data['slug'] ?? $row->slug)) {
            return back()->withInput()->withErrors(['cover' => $err]);
        }
        DB::table('tbl_destinations')->where('destinationId', $id)->update($data);
        PublicCache::flush();

        return redirect()->route('admin.destinations.edit', $id)->with('success', 'Đã lưu điểm đến.');
    }

    private function makeSlug(string $text): string
    {
        $base = Str::limit(Str::slug($text) ?: 'diem-den', 70, '');
        $slug = $base;
        for ($i = 2; DB::table('tbl_destinations')->where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    /** Mùa đẹp 12 tháng. Ô "level" để trống = xóa dòng tháng đó (hiện "chưa có dữ liệu"). Dòng lưu tay có source = manual. */
    public function months(Request $request, int $id)
    {
        DB::table('tbl_destinations')->where('destinationId', $id)->exists() || abort(404);
        $request->validate([
            'level' => ['required', 'array'],
            'level.*' => ['nullable', 'in:best,ok,avoid'],
            'note' => ['nullable', 'array'],
            'note.*' => ['nullable', 'max:160'],
        ], ['note.*.max' => 'Ghi chú mỗi tháng tối đa 160 ký tự.']);

        DB::transaction(function () use ($request, $id) {
            for ($m = 1; $m <= 12; $m++) {
                $level = $request->input("level.$m");
                if (!$level) {
                    DB::table('tbl_destination_months')->where('destinationId', $id)->where('month', $m)->delete();
                    continue;
                }
                $note = trim((string) $request->input("note.$m", ''));
                DB::table('tbl_destination_months')->upsert(
                    [['destinationId' => $id, 'month' => $m, 'level' => $level, 'note' => $note !== '' ? $note : null, 'source' => 'manual']],
                    ['destinationId', 'month'],
                    ['level', 'note', 'source']
                );
            }
        });
        PublicCache::flush();

        return back()->with('success', 'Đã lưu mùa đẹp (đánh dấu nhập tay, lệnh climate:build-months sẽ không ghi đè).');
    }

    /** Điểm cảm hứng. Ô trống = không gắn cảm hứng đó. */
    public function inspirations(Request $request, int $id)
    {
        DB::table('tbl_destinations')->where('destinationId', $id)->exists() || abort(404);
        $request->validate(['score' => ['nullable', 'array'], 'score.*' => ['nullable', 'integer', 'between:0,100']], [
            'score.*.between' => 'Điểm cảm hứng từ 0 đến 100.',
            'score.*.integer' => 'Điểm cảm hứng phải là số nguyên.',
        ]);
        $valid = DB::table('tbl_inspirations')->pluck('inspirationId')->all();

        DB::transaction(function () use ($request, $id, $valid) {
            foreach ($valid as $iid) {
                $v = $request->input("score.$iid");
                if ($v === null || $v === '') {
                    DB::table('tbl_destination_inspiration')->where('destinationId', $id)->where('inspirationId', $iid)->delete();
                } else {
                    DB::table('tbl_destination_inspiration')->upsert(
                        [['destinationId' => $id, 'inspirationId' => $iid, 'score' => (int) $v]],
                        ['destinationId', 'inspirationId'],
                        ['score']
                    );
                }
            }
        });
        PublicCache::flush();

        return back()->with('success', 'Đã lưu điểm cảm hứng.');
    }

    public function destroy(int $id)
    {
        $row = DB::table('tbl_destinations')->where('destinationId', $id)->first() ?? abort(404);
        $used = [];
        foreach (['tbl_tours' => 'tour', 'tbl_foods' => 'món ăn', 'tbl_events' => 'lễ hội', 'tbl_external_places' => 'địa điểm'] as $t => $label) {
            if (($n = DB::table($t)->where('destinationId', $id)->count()) > 0) {
                $used[] = "{$n} {$label}";
            }
        }
        if ($used) {
            return back()->with('error', 'Không xóa được vì còn ' . implode(', ', $used) . ' gắn với điểm đến này. Hãy tắt hoạt động (bỏ chọn "Đang hiển thị") thay vì xóa.');
        }

        DB::transaction(function () use ($id) {
            DB::table('tbl_itinerary_items')->where('refType', 'destination')->where('refId', $id)->update(['refType' => null, 'refId' => null]);
            DB::table('tbl_user_marks')->where('refType', 'destination')->where('refId', $id)->delete();
            DB::table('tbl_trip_items')->where('refType', 'destination')->where('refId', $id)->delete();
            DB::table('tbl_destinations')->where('destinationId', $id)->delete();   // tháng, cảm hứng, khí hậu, chunk tự xóa theo (CASCADE)
        });
        app(CoverUploader::class)->delete($row->coverImage);
        PublicCache::flush();

        return redirect()->route('admin.destinations')->with('success', 'Đã xóa điểm đến.');
    }
}
