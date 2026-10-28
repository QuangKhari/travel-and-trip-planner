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
    private const STATUSES = ['draft' => 'Nháp', 'reviewed' => 'Đã kiểm tra (cũ)', 'published' => 'Đã xuất bản'];

    private const URL = ['nullable', 'max:255', 'regex:/^https?:\/\//i'];

    private const PAGE = 25;

    /** Gợi ý giấy phép khi nội dung dựa theo nguồn khác (vẫn gõ được giá trị khác). */
    public const LICENSE_HINTS = ['CC BY-SA 4.0', 'CC BY 4.0', 'CC0 1.0', 'Công cộng (public domain)'];

    /** Nội dung/ảnh do Travela tự viết, tự chụp: không cần nguồn ngoài. */
    public const OWN_LICENSE = 'Tự viết';
    public const OWN_CREDIT = 'Travela';

    /**
     * Cách nhóm các trường trong form (trường không nằm trong nhóm nào là phần chính, luôn hiện):
     *   planner = cần để Planner xếp lịch (hiện, có tiêu đề riêng); when = thời gian diễn ra (lễ hội); advanced = thu gọn, không bắt buộc.
     */
    private const GROUPS = [
        'foods' => ['advanced' => ['monthFrom', 'monthTo', 'whereToTry', 'placeId', 'isMustTry', 'isFeatured']],
        'experiences' => [
            'planner' => ['durationMinutes', 'difficulty', 'indoor'],
            'advanced' => ['duration', 'priceLevel', 'monthFrom', 'monthTo', 'suitableFor', 'priceNote', 'priceMin', 'priceMax', 'latitude', 'longitude', 'isMustTry', 'isFeatured'],
        ],
        'events' => [
            'when' => ['isLunar', 'lunarMonth', 'lunarDay', 'solarMonth', 'solarDay', 'durationDays', 'dateNote'],
            'advanced' => ['isMustTry', 'isFeatured'],
        ],
        'guides' => [],
        'itineraries' => [],
        'places' => ['advanced' => ['isMustTry', 'isFeatured']],
    ];

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
        // Trường chỉ hiện khi cột đã được migrate (giống $flags): chạy được cả khi chưa migrate tuần 1-2.
        $opt = function (string $table, array $defs) {
            return array_filter($defs, fn($k) => self::has($table, $k), ARRAY_FILTER_USE_KEY);
        };
        $month = fn(string $label) => [$label, 'number', ['nullable', 'integer', 'between:1,12']];
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
                ] + $opt('tbl_foods', [
                    'monthFrom' => $month('Mùa ngon: từ tháng (1-12)'),
                    'monthTo' => $month('Đến tháng (nhỏ hơn "từ" nghĩa là qua năm)'),
                    'whereToTry' => ['Nơi nên thử (chữ, chỉ ghi khi có nguồn)', 'text', ['nullable', 'max:255']],
                    'placeId' => ['Địa điểm ăn uống (placeId trong Địa điểm OSM, để trống nếu chưa xác minh)', 'number', ['nullable', 'integer', 'exists:tbl_external_places,placeId']],
                ]) + $flags('tbl_foods') + $legal,
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
                ] + $opt('tbl_experiences', [
                    'durationMinutes' => ['Thời lượng (phút, để Planner xếp lịch; bắt buộc để vào Planner)', 'number', ['nullable', 'integer', 'between:5,1440']],
                    'difficulty' => ['Độ khó (bắt buộc để vào Planner)', 'enum', ['nullable', 'in:easy,medium,hard'], ['easy' => 'Dễ', 'medium' => 'Vừa sức', 'hard' => 'Khó']],
                    'indoor' => ['Trong nhà hay ngoài trời', 'enum', ['nullable', 'in:0,1'], ['1' => 'Trong nhà', '0' => 'Ngoài trời']],
                    'priceLevel' => ['Mức giá (1 rẻ đến 4 đắt)', 'number', ['nullable', 'integer', 'between:1,4']],
                    'monthFrom' => $month('Mùa phù hợp: từ tháng (1-12)'),
                    'monthTo' => $month('Đến tháng (nhỏ hơn "từ" nghĩa là qua năm)'),
                    'suitableFor' => ['Phù hợp với (vd gia đình, cặp đôi)', 'text', ['nullable', 'max:160']],
                    'priceNote' => ['Ghi chú giá (chỉ khi có nguồn, ghi rõ "tham khảo")', 'text', ['nullable', 'max:160']],
                    'priceMin' => ['Giá thấp nhất (VND, chỉ khi có nguồn)', 'number', ['nullable', 'numeric', 'min:0']],
                    'priceMax' => ['Giá cao nhất (VND, chỉ khi có nguồn)', 'number', ['nullable', 'numeric', 'min:0']],
                    'latitude' => ['Vĩ độ (chỉ khi xác minh được vị trí riêng)', 'number', ['nullable', 'numeric', 'between:-90,90']],
                    'longitude' => ['Kinh độ', 'number', ['nullable', 'numeric', 'between:-180,180']],
                ]) + $flags('tbl_experiences') + $legal,
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
                ] + $flags('tbl_events') + $legal,
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
                ] + $opt('tbl_external_places', [
                    'description' => ['Giới thiệu ngắn (biên tập viết)', 'textarea', ['nullable']],
                ]) + $flags('tbl_external_places'),
            ],
        ];
    }

    private function cfg(string $kind): array
    {
        $cfg = $this->kinds()[$kind] ?? abort(404);
        $cfg['groups'] = self::GROUPS[$kind] ?? [];

        return $cfg;
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
            'plannerProblems' => $isNew ? [] : PublishGate::plannerProblems($kind, $row),
            'statuses' => self::STATUSES,
            'adminNames' => $this->adminNames(),
            'itemCount' => ($kind === 'itineraries' && !$isNew) ? DB::table('tbl_itinerary_items')->where('itineraryId', $id)->count() : 0,
            'dates' => $dates,
            'destinations' => $cfg['dest'] ? DB::table('tbl_destinations')->orderBy('name')->get(['destinationId', 'name', 'isActive']) : collect(),
            'categories' => $kind === 'guides' ? DB::table('tbl_guide_categories')->orderBy('displayOrder')->orderBy('name')->get(['categoryId', 'name']) : collect(),
            'categoryHints' => isset($cfg['fields']['category']) && $cfg['fields']['category'][1] === 'text'
                ? DB::table($cfg['table'])->whereNotNull('category')->where('category', '<>', '')->distinct()->orderBy('category')->limit(60)->pluck('category')->all() : [],
            'licenseHints' => self::LICENSE_HINTS,
            'ownLicense' => self::OWN_LICENSE,
            'ownCredit' => self::OWN_CREDIT,
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

        if ($coverErr) {
            return redirect()->route('admin.content.edit', [$kind, $id])->with('error', "Đã tạo, nhưng ảnh chưa lưu được: {$coverErr}");
        }
        if ($request->input('after') === 'publish') {
            $err = $this->apply($kind, $cfg, $id, 'published');

            return redirect()->route('admin.content.edit', [$kind, $id])->with($err ? 'error' : 'success',
                $err ? 'Đã lưu nháp, chưa xuất bản được vì: ' . $err : 'Đã tạo và xuất bản.');
        }

        return redirect()->route('admin.content.edit', [$kind, $id])->with('success', 'Đã lưu nháp. Xem mục "Trước khi xuất bản" bên phải để biết còn thiếu gì.');
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

        // Sửa xong mà không còn đủ điều kiện thì tự hạ về Nháp để không lộ nội dung lỗi ra công khai.
        $fresh = DB::table($cfg['table'])->where($cfg['key'], $id)->first();
        if ($fresh->reviewStatus === 'published' && ($p = PublishGate::problems($kind, $fresh))) {
            DB::table($cfg['table'])->where($cfg['key'], $id)->update(['reviewStatus' => 'draft']);
            $this->log($kind, $id, 'status', 'published', 'draft', 'Tự hạ vì không đủ điều kiện: ' . implode('; ', $p));
            PublicCache::flush();

            return redirect()->route('admin.content.edit', [$kind, $id])->with('error', 'Đã lưu, nhưng nội dung chưa đủ điều kiện nên được đưa về Nháp: ' . implode('; ', $p));
        }
        PublicCache::flush();

        if ($request->input('after') === 'publish' && $fresh->reviewStatus !== 'published') {
            $err = $this->apply($kind, $cfg, $id, 'published');

            return redirect()->route('admin.content.edit', [$kind, $id])->with($err ? 'error' : 'success',
                $err ? 'Đã lưu, chưa xuất bản được vì: ' . $err : 'Đã lưu và xuất bản.');
        }

        return redirect()->route('admin.content.edit', [$kind, $id])->with('success', 'Đã lưu.');
    }

    private function validated(Request $request, array $cfg, ?int $id): array
    {
        $rules = array_map(fn($f) => $f[2], $cfg['fields']);
        $this->applyLegalChoices($request, $cfg, $rules);
        if ($cfg['slug']) {
            $rules['slug'] = ['nullable', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique($cfg['table'], 'slug')->ignore($id, $cfg['key'])];
        }
        if ($cfg['cover']) {
            $rules['cover'] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:' . (int) config('media.limits.max_file_kb', 8192)];
            $rules['removeCover'] = ['nullable', 'boolean'];
            if ($request->hasFile('cover') && isset($cfg['fields']['imageCredit'])) {
                $rules['imageCredit'] = ['required', 'max:255'];   // ảnh tải lên bắt buộc có nguồn / tác giả
            }
        }
        $data = $request->validate($rules, [
            'slug.regex' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang.',
            'slug.unique' => 'Slug này đã có nội dung khác dùng.',
            'imageCredit.required' => 'Ảnh cần có nguồn: chọn "Ảnh Travela tự chụp" hoặc ghi tên tác giả / nguồn ảnh.',
            'license.required' => 'Nội dung dựa theo nguồn khác thì cần ghi giấy phép (ví dụ CC BY-SA 4.0).',
            'sourceUrl.required' => 'Nội dung dựa theo nguồn khác thì cần dán liên kết nguồn.',
            'destinationId.exists' => 'Điểm đến không tồn tại.',
        ]);
        foreach ($cfg['fields'] as $name => $f) {
            if ($f[1] === 'bool') {
                $data[$name] = $request->boolean($name) ? 1 : 0;
            }
        }

        return array_map(fn($v) => is_string($v) && trim($v) === '' ? null : $v, $data);
    }

    /**
     * Hai lựa chọn gọn thay cho ba ô gõ tay:
     *  - provenance = own   -> giấy phép "Tự viết", không cần liên kết nguồn;
     *  - provenance = other -> bắt buộc ghi giấy phép và liên kết nguồn;
     *  - imageCreditMode = own -> nguồn ảnh "Travela" (ảnh tự chụp / tự tạo); = other -> bắt buộc gõ nguồn / tác giả.
     * Không gửi các khóa này (API cũ, test cũ) thì giữ nguyên hành vi trước đây.
     */
    private function applyLegalChoices(Request $request, array $cfg, array &$rules): void
    {
        if ($cfg['legal'] && isset($cfg['fields']['license'])) {
            $mode = (string) $request->input('provenance', '');
            if ($mode === 'own') {
                $request->merge(['license' => self::OWN_LICENSE, 'sourceUrl' => null]);
            } elseif ($mode === 'other') {
                $rules['license'] = ['required', 'max:80'];
                $rules['sourceUrl'] = array_merge(['required'], self::URL);
                if (trim((string) $request->input('license')) === self::OWN_LICENSE) {
                    $request->merge(['license' => null]);   // đang chọn "nguồn khác" thì không được để lại "Tự viết" cũ
                }
            }
        }
        if ($cfg['cover'] && isset($cfg['fields']['imageCredit'])) {
            $mode = (string) $request->input('imageCreditMode', '');
            $hasImage = $request->hasFile('cover') || $request->boolean('hasCover');
            if ($mode === 'own' && $hasImage) {
                $request->merge(['imageCredit' => self::OWN_CREDIT]);
            } elseif ($mode === 'other' && $hasImage) {
                $rules['imageCredit'] = ['required', 'max:255'];
                if (trim((string) $request->input('imageCredit')) === self::OWN_CREDIT) {
                    $request->merge(['imageCredit' => null]);
                }
            }
        }
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
