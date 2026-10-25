<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\ContentCatalog;
use App\Services\Content\CoverUploader;
use App\Services\Content\GuideCatalog;
use App\Services\Content\ImportRaw;
use App\Services\Content\PublicCache;
use App\Services\Content\PublishGate;
use App\Services\EventDateService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Duyệt và quản lý nội dung lớp Khám phá. draft (nháp / bị từ chối) -> reviewed (đã xem) -> published (hiện công khai).
 * Chỉ admin/manager (route nằm trong nhóm checkAdminRole:admin,manager).
 * Mỗi lần đổi trạng thái / sửa / xóa ghi vào tbl_content_review_log; mọi thay đổi đều xóa cache công khai (PublicCache).
 */
class ContentReviewController extends Controller
{
    private const STATUSES = ['draft' => 'Nháp', 'reviewed' => 'Đã xem', 'published' => 'Đã xuất bản'];

    private const URL = ['nullable', 'max:255', 'regex:/^https?:\/\//i'];

    private const PAGE = 25;

    /** @var array<string,bool> */
    private static array $colCache = [];

    private static function has(string $table, string $col): bool
    {
        return self::$colCache["$table.$col"] ??= Schema::hasTable($table) && Schema::hasColumn($table, $col);
    }

    /**
     * Mỗi kind: bảng, khóa, cột tiêu đề/nội dung, và các cờ giao diện:
     *   dest (gắn điểm đến), slug, cover (ảnh bìa), legal (nguồn/giấy phép), create (có nút Thêm), updatedCol, coverDir.
     * fields: tên => [nhãn, kiểu, luật, (tuỳ chọn cho enum)]; kiểu: text | textarea | number | dest | category | enum | bool.
     * Trường tuỳ chọn (isMustTry, isFeatured) chỉ xuất hiện khi cột đã được migrate.
     */
    private function kinds(): array
    {
        $legal = [
            'sourceUrl' => ['Liên kết nguồn', 'text', self::URL],
            'license' => ['Giấy phép (vd CC BY-SA 4.0, hoặc "Tự viết")', 'text', ['nullable', 'max:80']],
            'imageCredit' => ['Ghi công ảnh', 'text', ['nullable', 'max:255']],
        ];
        $dest = ['Điểm đến (bắt buộc để xuất bản)', 'dest', ['nullable', 'integer', 'exists:tbl_destinations,destinationId']];
        $flags = function (string $table) {
            $f = [];
            if (self::has($table, 'isMustTry')) {
                $f['isMustTry'] = ['Phải thử (nhãn vàng trên thẻ)', 'bool', ['nullable', 'boolean']];
            }
            if (self::has($table, 'isFeatured')) {
                $f['isFeatured'] = ['Nổi bật (ưu tiên ở trang chủ và hero)', 'bool', ['nullable', 'boolean']];
            }

            return $f;
        };
        $base = ['slug' => true, 'cover' => true, 'legal' => true, 'create' => true, 'updatedCol' => 'updatedAt'];

        return [
            'foods' => $base + [
                'label' => 'Món ăn', 'table' => 'tbl_foods', 'key' => 'foodId', 'title' => 'name', 'body' => 'description',
                'coverDir' => 'discover/foods', 'dest' => true,
                'fields' => [
                    'name' => ['Tên món', 'text', ['required', 'max:160']],
                    'destinationId' => $dest,
                    'category' => ['Nhóm món (gõ thống nhất để chip lọc không bị tách)', 'text', ['nullable', 'max:40']],
                    'description' => ['Mô tả', 'textarea', ['nullable']],
                ] + $flags('tbl_foods') + $legal,
            ],
            'experiences' => $base + [
                'label' => 'Trải nghiệm', 'table' => 'tbl_experiences', 'key' => 'experienceId', 'title' => 'name', 'body' => 'description',
                'coverDir' => 'discover/experiences', 'dest' => true,
                'fields' => [
                    'name' => ['Tên trải nghiệm', 'text', ['required', 'max:160']],
                    'destinationId' => $dest,
                    'category' => ['Loại (vd Thiên nhiên & thuyền)', 'text', ['nullable', 'max:40']],
                    'duration' => ['Thời lượng (chữ, vd Nửa ngày)', 'text', ['nullable', 'max:60']],
                    'description' => ['Mô tả', 'textarea', ['nullable']],
                ] + $flags('tbl_experiences') + $legal,
            ],
            'events' => $base + [
                'label' => 'Lễ hội', 'table' => 'tbl_events', 'key' => 'eventId', 'title' => 'name', 'body' => 'description',
                'coverDir' => 'discover/events', 'dest' => true,
                'fields' => [
                    'name' => ['Tên lễ hội', 'text', ['required', 'max:160']],
                    'destinationId' => $dest,
                    'description' => ['Mô tả', 'textarea', ['nullable']],
                    'isLunar' => ['Âm lịch? (1 = có, 0 = dương lịch)', 'number', ['required', 'in:0,1']],
                    'lunarMonth' => ['Tháng âm (để trống = lặp mỗi tháng)', 'number', ['nullable', 'integer', 'between:1,12']],
                    'lunarDay' => ['Ngày âm', 'number', ['nullable', 'integer', 'between:1,30']],
                    'solarMonth' => ['Tháng dương', 'number', ['nullable', 'integer', 'between:1,12']],
                    'solarDay' => ['Ngày dương (trống = chỉ biết tháng)', 'number', ['nullable', 'integer', 'between:1,31']],
                    'durationDays' => ['Số ngày diễn ra', 'number', ['required', 'integer', 'between:1,120']],
                    'dateNote' => ['Ghi chú ngày', 'text', ['nullable', 'max:255']],
                ] + $legal,
            ],
            'guides' => $base + [
                'dest' => false,
                'label' => 'Cẩm nang', 'table' => 'tbl_guides', 'key' => 'guideId', 'title' => 'title', 'body' => 'body',
                'coverDir' => 'discover/guides',
                'fields' => [
                    'title' => ['Tiêu đề', 'text', ['required', 'max:200']],
                    'categoryId' => ['Danh mục', 'category', ['nullable', 'integer', 'exists:tbl_guide_categories,categoryId']],
                    'body' => ['Nội dung (Markdown: "## " là mục, "> " là hộp lưu ý, bảng Markdown cho "Tóm tắt nhanh")', 'textarea', ['nullable']],
                ] + $legal,
            ],
            'itineraries' => $base + [
                'dest' => false,
                'label' => 'Lịch trình', 'table' => 'tbl_itineraries', 'key' => 'itineraryId', 'title' => 'title', 'body' => 'summary',
                'coverDir' => 'discover/itineraries',
                'fields' => [
                    'title' => ['Tiêu đề', 'text', ['required', 'max:160']],
                    'region' => ['Miền', 'enum', ['nullable', 'in:b,t,n'], ContentCatalog::REGIONS],
                    'days' => ['Số ngày', 'number', ['required', 'integer', 'between:1,30']],
                    'summary' => ['Tóm tắt', 'textarea', ['nullable']],
                ] + $legal,
            ],
            'places' => [
                'label' => 'Địa điểm (OSM)', 'table' => 'tbl_external_places', 'key' => 'placeId', 'title' => 'name', 'body' => 'address',
                'dest' => true, 'slug' => false, 'cover' => false, 'legal' => false, 'create' => false, 'updatedCol' => 'syncedAt',
                'fields' => [
                    'name' => ['Tên địa điểm', 'text', ['required', 'max:200']],
                    'destinationId' => $dest,
                ],
            ],
        ];
    }

    private function cfg(string $kind): array
    {
        return $this->kinds()[$kind] ?? abort(404);
    }

    // ------------------------------------------------------------------ Danh sách

    public function index(Request $request, string $kind = 'foods')
    {
        $cfg = $this->cfg($kind);
        $t = $cfg['table'];
        $str = fn(string $k) => is_string($request->query($k)) ? $request->query($k) : '';
        $status = array_key_exists($str('status'), self::STATUSES) ? $str('status') : null;
        $destSlug = $str('destination');
        $mien = isset(ContentCatalog::REGIONS[$str('mien')]) ? $str('mien') : '';
        $categoryFilter = $str('category');
        $filter = in_array($request->query('filter'), ['problem', 'noimage', 'nolicense'], true) ? $request->query('filter') : '';
        $search = trim($str('q'));
        $sort = in_array($request->query('sort'), ['title', 'status', 'updated'], true) ? $request->query('sort') : '';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $q = DB::table($t . ' as t')->select('t.*');
        if ($cfg['dest']) {
            $q->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')->addSelect('d.name as destName');
            if ($destSlug === '_none') {
                $q->whereNull('t.destinationId');
            } elseif ($destSlug !== '') {
                $q->where('d.slug', $destSlug);
            }
            if ($mien !== '') {
                $q->where('d.domain', $mien);
            }
        }
        if ($kind === 'itineraries' && $mien !== '') {
            $q->where('t.region', $mien);
        }
        if ($kind === 'guides' && $categoryFilter !== '') {
            $categoryFilter === '_none' ? $q->whereNull('t.categoryId') : $q->where('t.categoryId', (int) $categoryFilter);
        }
        if ($status) {
            $q->where('t.reviewStatus', $status);
        }
        if ($search !== '') {
            $q->where('t.' . $cfg['title'], 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
        }
        if ($filter === 'noimage' && $cfg['cover']) {
            $q->where(fn($w) => $w->whereNull('t.coverImage')->orWhere('t.coverImage', ''));
        }
        if ($filter === 'nolicense' && $cfg['legal']) {
            $q->where(fn($w) => $w->whereNull('t.license')->orWhere('t.license', ''));
        }

        if ($sort === 'title') {
            $q->orderBy('t.' . $cfg['title'], $dir);
        } elseif ($sort === 'status') {
            $q->orderByRaw("FIELD(t.reviewStatus, 'draft', 'reviewed', 'published') " . strtoupper($dir));
        } elseif ($sort === 'updated') {
            $q->orderBy('t.' . $cfg['updatedCol'], $dir);
        } else {
            $q->orderByRaw("FIELD(t.reviewStatus, 'draft', 'reviewed', 'published')");
        }
        $q->orderBy('t.' . $cfg['key'], 'desc');

        if ($filter === 'problem') {
            // "Chưa đạt điều kiện xuất bản" cần chạy PublishGate nên lọc ở PHP (giới hạn 2000 dòng) rồi tự phân trang
            $all = $q->limit(2000)->get()->filter(fn($r) => PublishGate::problems($kind, $r))->values();
            $page = max(1, (int) $request->query('page', 1));
            $rows = new LengthAwarePaginator($all->forPage($page, self::PAGE)->values(), $all->count(), self::PAGE, $page, ['path' => url()->current(), 'query' => $request->query()]);
        } else {
            $rows = $q->paginate(self::PAGE)->withQueryString();
        }

        $problems = [];
        foreach ($rows as $r) {
            $problems[$r->{$cfg['key']}] = PublishGate::problems($kind, $r);
        }

        return view('admin.content', [
            'title' => 'Duyệt nội dung',
            'kind' => $kind,
            'cfg' => $cfg,
            'kinds' => $this->kinds(),
            'rows' => $rows,
            'problems' => $problems,
            'counts' => DB::table($t)->selectRaw('reviewStatus, COUNT(*) as c')->groupBy('reviewStatus')->pluck('c', 'reviewStatus'),
            'statuses' => self::STATUSES,
            'status' => $status,
            'destSlug' => $destSlug,
            'destinations' => $cfg['dest'] ? DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['slug', 'name']) : collect(),
            'regions' => ContentCatalog::REGIONS,
            'mien' => $mien,
            'categories' => $kind === 'guides' ? DB::table('tbl_guide_categories')->orderBy('displayOrder')->orderBy('name')->get(['categoryId', 'name']) : collect(),
            'categoryFilter' => $categoryFilter,
            'filter' => $filter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'adminNames' => $this->adminNames(),
            'ragDirty' => Cache::has('rag.dirty'),
        ]);
    }

    // ------------------------------------------------------------------ Thêm / sửa

    public function create(string $kind)
    {
        $cfg = $this->cfg($kind);
        abort_unless($cfg['create'], 404);

        return $this->form($kind, $cfg, new \stdClass(), null);
    }

    public function edit(string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);

        return $this->form($kind, $cfg, $row, $id);
    }

    private function form(string $kind, array $cfg, object $row, ?int $id)
    {
        $isNew = $id === null;
        $dates = [];
        if ($kind === 'events' && !$isNew) {
            $dates = app(EventDateService::class)->upcoming($row, null, 5);
        }

        return view('admin.content-edit', [
            'title' => $isNew ? 'Thêm nội dung' : 'Sửa nội dung',
            'kind' => $kind,
            'cfg' => $cfg,
            'row' => $row,
            'id' => $id,
            'isNew' => $isNew,
            'problems' => $isNew ? [] : PublishGate::problems($kind, $row),
            'statuses' => self::STATUSES,
            'adminNames' => $this->adminNames(),
            'itemCount' => ($kind === 'itineraries' && !$isNew) ? DB::table('tbl_itinerary_items')->where('itineraryId', $id)->count() : 0,
            'dates' => $dates,
            'destinations' => $cfg['dest'] ? DB::table('tbl_destinations')->orderBy('name')->get(['destinationId', 'name', 'isActive']) : collect(),
            'categories' => $kind === 'guides' ? DB::table('tbl_guide_categories')->orderBy('displayOrder')->orderBy('name')->get(['categoryId', 'name']) : collect(),
            'raw' => $isNew ? null : $this->rawFor($kind, $row),
            'history' => $isNew ? collect() : DB::table('tbl_content_review_log')->where('kind', $kind)->where('refId', $id)->orderByDesc('logId')->limit(30)->get(),
        ]);
    }

    /** Đối chiếu bản gốc Wikipedia (chỉ món ăn và lễ hội có nguồn Wikipedia). */
    private function rawFor(string $kind, object $row): ?array
    {
        if (!in_array($kind, ['foods', 'events'], true)) {
            return null;
        }
        $title = (!empty($row->wikiTitle) ? $row->wikiTitle : null) ?: ImportRaw::titleFromUrl($row->sourceUrl ?? null);
        $raw = $title ? ImportRaw::get('wikipedia', config('discover.wikipedia.lang', 'vi') . ':' . $title) : null;

        return ['title' => $title, 'raw' => $raw];
    }

    public function store(Request $request, string $kind)
    {
        $cfg = $this->cfg($kind);
        abort_unless($cfg['create'], 404);
        $data = $this->validated($request, $cfg, null);
        $data['slug'] = ($data['slug'] ?? null) ?: $this->makeSlug($cfg, (string) $data[$cfg['title']]);
        $coverErr = $this->saveCover($data, $cfg, null, $data['slug']);
        if ($kind === 'guides') {
            $data['body'] = $data['body'] ?? '';
            $data['updatedOn'] = now()->toDateString();
        }
        $data['reviewStatus'] = 'draft';
        $data['createdAt'] = now();

        $id = (int) DB::table($cfg['table'])->insertGetId($data, $cfg['key']);
        $this->log($kind, $id, 'create', null, 'draft', 'Tạo mới');
        PublicCache::flush();

        return redirect()->route('admin.content.edit', [$kind, $id])
            ->with($coverErr ? 'error' : 'success', $coverErr ? "Đã tạo, nhưng ảnh chưa lưu được: {$coverErr}" : 'Đã tạo ở trạng thái Nháp. Bổ sung nguồn, giấy phép, ảnh rồi xuất bản.');
    }

    public function update(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);
        $data = $this->validated($request, $cfg, $id);
        if ($cfg['slug'] && empty($data['slug'])) {
            unset($data['slug']);
        }
        if ($err = $this->saveCover($data, $cfg, $row, $data['slug'] ?? $row->slug ?? (string) $id)) {
            return back()->withInput()->withErrors(['cover' => $err]);
        }
        if ($kind === 'guides') {
            $data['updatedOn'] = now()->toDateString();   // dòng "Cập nhật lần cuối" của bài
        }
        if (self::has($cfg['table'], 'updatedAt')) {
            $data['updatedAt'] = now();
        }
        DB::table($cfg['table'])->where($cfg['key'], $id)->update($data);
        $this->log($kind, $id, 'update', $row->reviewStatus, $row->reviewStatus, 'Sửa nội dung');

        // Sửa xong mà không còn đủ điều kiện thì tự hạ về "Đã xem" để không lộ nội dung lỗi ra công khai.
        $fresh = DB::table($cfg['table'])->where($cfg['key'], $id)->first();
        if ($fresh->reviewStatus === 'published' && ($p = PublishGate::problems($kind, $fresh))) {
            DB::table($cfg['table'])->where($cfg['key'], $id)->update(['reviewStatus' => 'reviewed']);
            $this->log($kind, $id, 'status', 'published', 'reviewed', 'Tự hạ vì không đủ điều kiện: ' . implode('; ', $p));
            PublicCache::flush();

            return redirect()->route('admin.content.edit', [$kind, $id])->with('error', 'Đã lưu, nhưng nội dung chưa đủ điều kiện nên được hạ về "Đã xem": ' . implode('; ', $p));
        }
        PublicCache::flush();

        return redirect()->route('admin.content.edit', [$kind, $id])->with('success', 'Đã lưu.');
    }

    private function validated(Request $request, array $cfg, ?int $id): array
    {
        $rules = array_map(fn($f) => $f[2], $cfg['fields']);
        if ($cfg['slug']) {
            $rules['slug'] = ['nullable', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique($cfg['table'], 'slug')->ignore($id, $cfg['key'])];
        }
        if ($cfg['cover']) {
            $rules['cover'] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:' . (int) config('media.limits.max_file_kb', 8192)];
            $rules['removeCover'] = ['nullable', 'boolean'];
            if ($request->hasFile('cover') && isset($cfg['fields']['imageCredit'])) {
                $rules['imageCredit'] = ['required', 'max:255'];   // ảnh tải lên bắt buộc có ghi công
            }
        }
        $data = $request->validate($rules, [
            'slug.regex' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Slug này đã có nội dung khác dùng.',
            'imageCredit.required' => 'Tải ảnh lên thì phải ghi công ảnh.',
            'destinationId.exists' => 'Điểm đến không tồn tại.',
        ]);
        foreach ($cfg['fields'] as $name => $f) {
            if ($f[1] === 'bool') {
                $data[$name] = $request->boolean($name) ? 1 : 0;
            }
        }

        return array_map(fn($v) => is_string($v) && trim($v) === '' ? null : $v, $data);
    }

    /** Lưu / xóa ảnh bìa. Trả về thông điệp lỗi (tiếng Việt) hoặc null. Gỡ khỏi $data các khóa không phải cột. */
    private function saveCover(array &$data, array $cfg, ?object $row, string $slug): ?string
    {
        $cover = $data['cover'] ?? null;
        $remove = !empty($data['removeCover']);
        unset($data['cover'], $data['removeCover']);
        if (!$cfg['cover']) {
            return null;
        }
        $up = app(CoverUploader::class);

        try {
            if ($cover) {
                $path = $up->store($cover, $cfg['coverDir'], $slug);
                if ($row && !empty($row->coverImage) && $row->coverImage !== $path) {
                    $up->delete($row->coverImage);
                }
                $data['coverImage'] = $path;
            } elseif ($remove && $row && !empty($row->coverImage)) {
                $up->delete($row->coverImage);
                $data['coverImage'] = null;
            }
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function makeSlug(array $cfg, string $text): string
    {
        $base = Str::limit(Str::slug($text) ?: 'noi-dung', 90, '');
        $slug = $base;
        for ($i = 2; DB::table($cfg['table'])->where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    // ------------------------------------------------------------------ Trạng thái

    public function status(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $new = (string) $request->input('status');
        abort_unless(array_key_exists($new, self::STATUSES), 422);

        $err = $this->apply($kind, $cfg, $id, $new);

        return back()->with($err ? 'error' : 'success', $err ?: 'Đã chuyển sang "' . self::STATUSES[$new] . '".');
    }

    public function reject(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason']);
        $err = $this->rejectOne($kind, $cfg, $id, $reason);

        return back()->with($err ? 'error' : 'success', $err ?: 'Đã từ chối và đưa về Nháp kèm lý do.');
    }

    public function bulk(Request $request, string $kind)
    {
        $cfg = $this->cfg($kind);
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        if (!$ids) {
            return back()->with('error', 'Chưa chọn dòng nào.');
        }
        $reject = $request->input('action') === 'reject';
        if ($reject) {
            $reason = trim((string) $request->input('reason', ''));
            if (mb_strlen($reason) < 5) {
                return back()->with('error', 'Từ chối cần nhập lý do (tối thiểu 5 ký tự).');
            }
        } else {
            $new = (string) $request->input('status');
            abort_unless(array_key_exists($new, self::STATUSES), 422);
        }

        $ok = 0;
        $fails = [];
        foreach ($ids as $id) {
            $err = $reject ? $this->rejectOne($kind, $cfg, $id, $reason) : $this->apply($kind, $cfg, $id, $new);
            $err ? $fails[] = "#{$id}: {$err}" : $ok++;
        }
        $label = $reject ? 'Từ chối (về Nháp)' : '"' . self::STATUSES[$new] . '"';
        $msg = "Đã xử lý {$ok}/" . count($ids) . " dòng: {$label}.";

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
        $upd = ['reviewStatus' => $new] + $this->stamp($cfg['table'], true);
        if (self::has($cfg['table'], 'updatedAt')) {
            $upd['updatedAt'] = now();
        }
        if (self::has($cfg['table'], 'reviewNote') && $new !== 'draft') {
            $upd['reviewNote'] = null;   // chuyển tiếp thì xóa lý do từ chối cũ
        }
        DB::table($cfg['table'])->where($cfg['key'], $id)->update($upd);
        $this->log($kind, $id, 'status', $row->reviewStatus, $new);
        PublicCache::flush();

        return null;
    }

    private function rejectOne(string $kind, array $cfg, int $id, string $reason): ?string
    {
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first();
        if (!$row) {
            return 'không tồn tại';
        }
        $upd = ['reviewStatus' => 'draft'] + $this->stamp($cfg['table'], true);
        if (self::has($cfg['table'], 'reviewNote')) {
            $upd['reviewNote'] = mb_substr($reason, 0, 255);
        }
        DB::table($cfg['table'])->where($cfg['key'], $id)->update($upd);
        $this->log($kind, $id, 'reject', $row->reviewStatus, 'draft', $reason);
        PublicCache::flush();

        return null;
    }

    /** reviewedBy / reviewedAt (chỉ khi bảng có cột; tbl_experiences cần migration 2026_10_26_000001). */
    private function stamp(string $table, bool $on): array
    {
        $out = [];
        if ($on && self::has($table, 'reviewedBy')) {
            $out['reviewedBy'] = session('adminId');
        }
        if ($on && self::has($table, 'reviewedAt')) {
            $out['reviewedAt'] = now();
        }

        return $out;
    }

    public function destroy(string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);
        if ($row->reviewStatus === 'published') {
            return back()->with('error', 'Không xóa nội dung đang xuất bản. Hãy đưa về Nháp trước.');
        }
        if ($cfg['cover'] && !empty($row->coverImage)) {
            app(CoverUploader::class)->delete($row->coverImage);
        }
        DB::table($cfg['table'])->where($cfg['key'], $id)->delete();   // điểm dừng lịch trình xóa theo (CASCADE)
        $this->log($kind, $id, 'delete', $row->reviewStatus, null, $row->{$cfg['title']});
        PublicCache::flush();

        return redirect()->route('admin.content', $kind)->with('success', 'Đã xóa "' . $row->{$cfg['title']} . '".');
    }

    // ------------------------------------------------------------------ Xem trước, chỉ mục RAG

    public function preview(string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);
        $days = [];
        if ($kind === 'itineraries') {
            $days = DB::table('tbl_itinerary_items')->where('itineraryId', $id)->orderBy('dayNo')->orderBy('orderNo')->get()->groupBy('dayNo')->all();
        }

        return view('admin.content-preview', [
            'title' => 'Xem trước',
            'kind' => $kind,
            'cfg' => $cfg,
            'row' => $row,
            'id' => $id,
            'statuses' => self::STATUSES,
            'problems' => PublishGate::problems($kind, $row),
            'dest' => ($cfg['dest'] && !empty($row->destinationId)) ? DB::table('tbl_destinations')->where('destinationId', $row->destinationId)->first() : null,
            'rendered' => $kind === 'guides' ? app(GuideCatalog::class)->render((string) $row->body) : null,
            'days' => $days,
            'dates' => $kind === 'events' ? app(EventDateService::class)->upcoming($row, null, 5) : [],
        ]);
    }

    /** Dựng lại chỉ mục RAG ngay (lệnh rag:build), thay vì chờ lịch 10 phút. */
    public function rebuildRag()
    {
        @set_time_limit(300);
        try {
            $code = Artisan::call('rag:build');
            $out = trim(Artisan::output());
            Cache::forget('rag.dirty');
        } catch (\Throwable $e) {
            return back()->with('error', 'Không dựng được chỉ mục: ' . $e->getMessage());
        }

        return back()->with($code === 0 ? 'success' : 'error', $code === 0 ? 'Đã cập nhật chỉ mục Hỏi đáp.' : 'rag:build báo lỗi: ' . Str::limit($out, 300));
    }

    // ------------------------------------------------------------------ Phụ trợ

    private function adminNames()
    {
        return DB::table('tbl_admin')->pluck('userName', 'adminId');
    }

    private function log(string $kind, int $id, string $action, ?string $from, ?string $to, ?string $note = null): void
    {
        DB::table('tbl_content_review_log')->insert([
            'kind' => $kind, 'refId' => $id, 'action' => $action, 'fromStatus' => $from, 'toStatus' => $to,
            'adminId' => session('adminId'), 'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'createdAt' => now(),
        ]);
    }
}
