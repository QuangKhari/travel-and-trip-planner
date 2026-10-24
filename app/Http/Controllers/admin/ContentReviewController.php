<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\PublishGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A7: duyệt nội dung lớp Khám phá. draft (nhập tự động) -> reviewed (đã đọc) -> published (hiện công khai).
 * Chỉ admin/manager (route nằm trong nhóm checkAdminRole:admin,manager).
 */
class ContentReviewController extends Controller
{
    private const STATUSES = ['draft' => 'Nháp', 'reviewed' => 'Đã xem', 'published' => 'Đã xuất bản'];

    private const URL = ['nullable', 'max:255', 'regex:/^https?:\/\//i'];

    /** Mỗi kind: bảng, khóa, cột tiêu đề, cột nội dung, có điểm đến không, các trường sửa được [nhãn, kiểu, luật]. */
    private function kinds(): array
    {
        $legal = [
            'sourceUrl' => ['Liên kết nguồn', 'text', self::URL],
            'license' => ['Giấy phép (vd CC BY-SA 4.0, hoặc "Tự viết")', 'text', ['nullable', 'max:80']],
            'imageCredit' => ['Ghi công ảnh', 'text', ['nullable', 'max:255']],
        ];

        return [
            'foods' => [
                'label' => 'Món ăn',
                'table' => 'tbl_foods',
                'key' => 'foodId',
                'title' => 'name',
                'body' => 'description',
                'dest' => true,
                'fields' => [
                    'name' => ['Tên món', 'text', ['required', 'max:160']],
                    'category' => ['Nhóm món', 'text', ['nullable', 'max:40']],
                    'description' => ['Mô tả', 'textarea', ['nullable']]
                ] + $legal
            ],
            'events' => [
                'label' => 'Lễ hội',
                'table' => 'tbl_events',
                'key' => 'eventId',
                'title' => 'name',
                'body' => 'description',
                'dest' => true,
                'fields' => [
                    'name' => ['Tên lễ hội', 'text', ['required', 'max:160']],
                    'description' => ['Mô tả', 'textarea', ['nullable']],
                    'isLunar' => ['Âm lịch? (1 = có, 0 = dương lịch)', 'number', ['required', 'in:0,1']],
                    'lunarMonth' => ['Tháng âm (để trống = lặp mỗi tháng)', 'number', ['nullable', 'integer', 'between:1,12']],
                    'lunarDay' => ['Ngày âm', 'number', ['nullable', 'integer', 'between:1,30']],
                    'solarMonth' => ['Tháng dương', 'number', ['nullable', 'integer', 'between:1,12']],
                    'solarDay' => ['Ngày dương (trống = chỉ biết tháng)', 'number', ['nullable', 'integer', 'between:1,31']],
                    'durationDays' => ['Số ngày diễn ra', 'number', ['required', 'integer', 'between:1,120']],
                    'dateNote' => ['Ghi chú ngày', 'text', ['nullable', 'max:255']]
                ] + $legal
            ],
            'guides' => [
                'label' => 'Cẩm nang',
                'table' => 'tbl_guides',
                'key' => 'guideId',
                'title' => 'title',
                'body' => 'body',
                'dest' => false,
                'fields' => ['title' => ['Tiêu đề', 'text', ['required', 'max:200']], 'body' => ['Nội dung (Markdown: "## " là mục, "> " là hộp lưu ý)', 'textarea', ['nullable']]] + $legal
            ],
            'itineraries' => [
                'label' => 'Lịch trình',
                'table' => 'tbl_itineraries',
                'key' => 'itineraryId',
                'title' => 'title',
                'body' => 'summary',
                'dest' => false,
                'fields' => [
                    'title' => ['Tiêu đề', 'text', ['required', 'max:160']],
                    'summary' => ['Tóm tắt', 'textarea', ['nullable']],
                    'days' => ['Số ngày', 'number', ['required', 'integer', 'between:1,30']]
                ] + $legal
            ],
            'places' => [
                'label' => 'Địa điểm (OSM)',
                'table' => 'tbl_external_places',
                'key' => 'placeId',
                'title' => 'name',
                'body' => 'address',
                'dest' => true,
                'fields' => []
            ],
        ];
    }

    private function cfg(string $kind): array
    {
        return $this->kinds()[$kind] ?? abort(404);
    }

    public function index(Request $request, string $kind = 'foods')
    {
        $cfg = $this->cfg($kind);
        $status = array_key_exists($request->query('status'), self::STATUSES) ? $request->query('status') : null;
        $destSlug = (string) $request->query('destination', '');
        $search = trim((string) $request->query('q', ''));

        $q = DB::table($cfg['table'] . ' as t')->select('t.*');
        if ($cfg['dest']) {
            $q->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')->addSelect('d.name as destName');
            if ($destSlug !== '') {
                $q->where('d.slug', $destSlug);
            }
        }
        if ($status) {
            $q->where('t.reviewStatus', $status);
        }
        if ($search !== '') {
            $q->where('t.' . $cfg['title'], 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
        }
        $q->orderByRaw("FIELD(t.reviewStatus, 'draft', 'reviewed', 'published')")->orderBy('t.' . $cfg['key'], 'desc');
        $rows = $q->paginate(25)->withQueryString();

        $problems = [];
        foreach ($rows as $r) {
            $problems[$r->{$cfg['key']}] = PublishGate::problems($kind, $r);
        }
        $counts = DB::table($cfg['table'])->selectRaw('reviewStatus, COUNT(*) as c')->groupBy('reviewStatus')->pluck('c', 'reviewStatus');
        $destinations = $cfg['dest'] ? DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['slug', 'name']) : collect();

        return view('admin.content', [
            'title' => 'Duyệt nội dung',
            'kind' => $kind,
            'cfg' => $cfg,
            'kinds' => $this->kinds(),
            'rows' => $rows,
            'problems' => $problems,
            'counts' => $counts,
            'statuses' => self::STATUSES,
            'status' => $status,
            'destSlug' => $destSlug,
            'destinations' => $destinations,
            'search' => $search,
        ]);
    }

    public function edit(string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        abort_if(!$cfg['fields'], 404);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);

        return view('admin.content-edit', [
            'title' => 'Sửa nội dung',
            'kind' => $kind,
            'cfg' => $cfg,
            'row' => $row,
            'id' => $id,
            'problems' => PublishGate::problems($kind, $row),
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        abort_if(!$cfg['fields'], 404);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);

        $data = $request->validate(array_map(fn($f) => $f[2], $cfg['fields']));
        $data = array_map(fn($v) => is_string($v) && trim($v) === '' ? null : $v, $data);
        $extra = $kind === 'guides' ? ['updatedOn' => now()->toDateString()] : [];   // C1: dòng "Cập nhật lần cuối" của bài
        DB::table($cfg['table'])->where($cfg['key'], $id)->update($data + ['updatedAt' => now()] + $extra);
        self::flushPublicCaches();

        // Sửa xong mà không còn đủ điều kiện thì tự hạ về "Đã xem" để không lộ nội dung lỗi ra công khai.
        $fresh = DB::table($cfg['table'])->where($cfg['key'], $id)->first();
        if ($fresh->reviewStatus === 'published' && PublishGate::problems($kind, $fresh)) {
            DB::table($cfg['table'])->where($cfg['key'], $id)->update(['reviewStatus' => 'reviewed']);

            return redirect()->route('admin.content.edit', [$kind, $id])->with('error', 'Đã lưu, nhưng nội dung chưa đủ điều kiện nên được hạ về "Đã xem": ' . implode('; ', PublishGate::problems($kind, $fresh)));
        }

        return redirect()->route('admin.content.edit', [$kind, $id])->with('success', 'Đã lưu.');
    }

    public function status(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $new = (string) $request->input('status');
        abort_unless(array_key_exists($new, self::STATUSES), 422);

        $err = $this->apply($kind, $cfg, $id, $new);

        return back()->with($err ? 'error' : 'success', $err ?: 'Đã chuyển sang "' . self::STATUSES[$new] . '".');
    }

    public function bulk(Request $request, string $kind)
    {
        $cfg = $this->cfg($kind);
        $new = (string) $request->input('status');
        abort_unless(array_key_exists($new, self::STATUSES), 422);
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        if (!$ids) {
            return back()->with('error', 'Chưa chọn dòng nào.');
        }

        $ok = 0;
        $fails = [];
        foreach ($ids as $id) {
            $err = $this->apply($kind, $cfg, $id, $new);
            $err ? $fails[] = "#{$id}: {$err}" : $ok++;
        }
        $msg = "Đã chuyển {$ok}/" . count($ids) . ' dòng sang "' . self::STATUSES[$new] . '".';

        return back()->with($fails ? 'error' : 'success', $fails ? $msg . ' Không đạt: ' . implode(' | ', array_slice($fails, 0, 5)) : $msg);
    }

    /** @return string|null  lỗi (null = thành công) */
    private function apply(string $kind, array $cfg, int $id, string $new): ?string
    {
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first();
        if (!$row) {
            return 'không tồn tại';
        }
        if ($new === 'published' && ($p = PublishGate::problems($kind, $row))) {
            return implode('; ', $p);
        }
        $upd = ['reviewStatus' => $new];
        if (in_array($kind, ['foods', 'events', 'guides', 'itineraries'], true)) {
            $upd['updatedAt'] = now();
        }
        DB::table($cfg['table'])->where($cfg['key'], $id)->update($upd);
        self::flushPublicCaches();

        return null;
    }

    /** C3, C4: chỉ mục tìm kiếm và sitemap đang đệm; xóa để nội dung vừa duyệt hiện ra ngay. */
    private static function flushPublicCaches(): void
    {
        \App\Services\Content\SearchCatalog::forget();
        \Illuminate\Support\Facades\Cache::forget('sitemap.xml.v1');
        \App\Services\Content\GuideCatalog::forgetMenu();
    }
}
