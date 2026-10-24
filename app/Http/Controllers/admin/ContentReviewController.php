<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\Content\CoverUploader;
use App\Services\Content\GuideCatalog;
use App\Services\Content\ImportRaw;
use App\Services\Content\PublicCache;
use App\Services\Content\PublishGate;
use App\Services\EventDateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Duyệt và quản lý nội dung lớp Khám phá (món ăn, lễ hội, cẩm nang, lịch trình, địa điểm OSM).
 * draft (nhập tự động / bị từ chối) -> reviewed (đã đọc) -> published (hiện công khai).
 * Chỉ admin/manager (route nằm trong nhóm checkAdminRole:admin,manager).
 *
 * So với bản cũ: thêm mới, xóa, sửa slug, tải/xóa ảnh bìa, chọn điểm đến / danh mục / miền,
 * "Phải thử" + "Nổi bật", từ chối kèm lý do, lưu ai duyệt lúc nào, xem trước bản nháp,
 * đối chiếu dữ liệu thô, lọc/sắp xếp nâng cao, xóa đủ bộ nhớ đệm công khai.
 */
class ContentReviewController extends Controller
{
    private const STATUSES = ['draft' => 'Nháp', 'reviewed' => 'Đã xem', 'published' => 'Đã xuất bản'];

    private const URL = ['nullable', 'max:255', 'regex:/^https?:\/\//i'];

    private const REGIONS = ['b' => 'Miền Bắc', 't' => 'Miền Trung', 'n' => 'Miền Nam'];

    private function covers(): CoverUploader
    {
        return app(CoverUploader::class);
    }

    /**
     * Mỗi kind: bảng, khóa, cột tiêu đề, cột nội dung, có điểm đến không, thư mục ảnh, các trường sửa được.
     * Trường: [nhãn, kiểu, luật, (tùy chọn) danh sách lựa chọn cho kiểu enum].
     * Kiểu: text | textarea | number | bool | dest | category | enum.
     */
    private function kinds(): array
    {
        $legal = [
            'sourceUrl' => ['Liên kết nguồn', 'text', self::URL],
            'license' => ['Giấy phép (vd CC BY-SA 4.0, hoặc "Tự viết")', 'text', ['nullable', 'max:80']],
            'imageCredit' => ['Ghi công ảnh (bắt buộc khi có ảnh bìa)', 'text', ['nullable', 'max:255']],
        ];
        $imgSrc = ['imageSourceUrl' => ['Trang gốc của ảnh (nếu có)', 'text', self::URL]];
        $dest = ['Điểm đến', 'dest', ['nullable', 'integer', 'exists:tbl_destinations,destinationId']];

        return [
            'foods' => [
                'label' => 'Món ăn', 'table' => 'tbl_foods', 'key' => 'foodId', 'title' => 'name', 'body' => 'description',
                'dest' => true, 'slug' => true, 'slugMax' => 100, 'cover' => true, 'imgDir' => 'discover/foods', 'refType' => 'food',
                'create' => true, 'legal' => true, 'touch' => true, 'updatedCol' => 'updatedAt', 'hasImgSrc' => true,
                'fields' => [
                    'name' => ['Tên món', 'text', ['required', 'max:160']],
                    'destinationId' => $dest,
                    'category' => ['Nhóm món', 'text', ['nullable', 'max:40']],
                    'description' => ['Mô tả', 'textarea', ['nullable']],
                    'isMustTry' => ['Nhãn "Phải thử"', 'bool', ['nullable', 'boolean']],
                    'isFeatured' => ['Nổi bật (được ưu tiên hiện đầu dải Ẩm thực ở trang chủ)', 'bool', ['nullable', 'boolean']],
                ] + $legal + $imgSrc,
            ],
            'events' => [
                'label' => 'Lễ hội', 'table' => 'tbl_events', 'key' => 'eventId', 'title' => 'name', 'body' => 'description',
                'dest' => true, 'slug' => true, 'slugMax' => 100, 'cover' => true, 'imgDir' => 'discover/events', 'refType' => 'event',
                'create' => true, 'legal' => true, 'touch' => true, 'updatedCol' => 'updatedAt', 'hasImgSrc' => true,
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
                ] + $legal + $imgSrc,
            ],
            'guides' => [
                'label' => 'Cẩm nang', 'table' => 'tbl_guides', 'key' => 'guideId', 'title' => 'title', 'body' => 'body',
                'dest' => false, 'slug' => true, 'slugMax' => 120, 'cover' => true, 'imgDir' => 'discover/guides', 'refType' => 'guide',
                'create' => true, 'legal' => true, 'touch' => true, 'updatedCol' => 'updatedAt', 'hasImgSrc' => false,
                'fields' => [
                    'title' => ['Tiêu đề', 'text', ['required', 'max:200']],
                    'categoryId' => ['Danh mục', 'category', ['nullable', 'integer', 'exists:tbl_guide_categories,categoryId']],
                    'body' => ['Nội dung (Markdown: "## " là mục, "> " là hộp lưu ý)', 'textarea', ['nullable']],
                ] + $legal,
            ],
            'itineraries' => [
                'label' => 'Lịch trình', 'table' => 'tbl_itineraries', 'key' => 'itineraryId', 'title' => 'title', 'body' => 'summary',
                'dest' => false, 'slug' => true, 'slugMax' => 100, 'cover' => true, 'imgDir' => 'discover/itineraries', 'refType' => 'itinerary',
                'create' => true, 'legal' => true, 'touch' => true, 'updatedCol' => 'updatedAt', 'hasImgSrc' => false,
                'fields' => [
                    'title' => ['Tiêu đề', 'text', ['required', 'max:160']],
                    'region' => ['Miền', 'enum', ['nullable', 'in:b,t,n'], self::REGIONS],
                    'summary' => ['Tóm tắt', 'textarea', ['nullable']],
                    'days' => ['Số ngày (các điểm dừng ở ngày lớn hơn số này sẽ chặn việc lưu)', 'number', ['required', 'integer', 'between:1,30']],
                ] + $legal,
            ],
            'places' => [
                'label' => 'Địa điểm (OSM)', 'table' => 'tbl_external_places', 'key' => 'placeId', 'title' => 'name', 'body' => 'address',
                'dest' => true, 'slug' => false, 'slugMax' => 0, 'cover' => false, 'imgDir' => null, 'refType' => 'place',
                'create' => false, 'legal' => false, 'touch' => false, 'updatedCol' => 'syncedAt', 'hasImgSrc' => false,
                'fields' => [
                    'name' => ['Tên địa điểm', 'text', ['required', 'max:160']],
                    'destinationId' => ['Điểm đến', 'dest', ['required', 'integer', 'exists:tbl_destinations,destinationId']],
                    'category' => ['Nhóm', 'enum', ['required', 'in:see,culture,eat'], ['see' => 'Tham quan', 'culture' => 'Văn hóa', 'eat' => 'Ăn uống']],
                    'kind' => ['Loại (beach, museum, restaurant...)', 'text', ['nullable', 'max:60']],
                    'address' => ['Địa chỉ', 'text', ['nullable', 'max:255']],
                    'rank' => ['Độ nổi bật (số lớn hơn = hiện trước)', 'number', ['required', 'integer', 'between:0,65000']],
                    'website' => ['Website', 'text', self::URL],
                ],
            ],
        ];
    }

    private function cfg(string $kind): array
    {
        return $this->kinds()[$kind] ?? abort(404);
    }

    // ------------------------------------------------------------------ Danh sách

    private function baseQuery(array $cfg, Request $request)
    {
        $q = DB::table($cfg['table'] . ' as t');
        $mien = array_key_exists((string) $request->query('mien'), self::REGIONS) ? (string) $request->query('mien') : null;

        if ($cfg['dest']) {
            $q->leftJoin('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId');
            $destSlug = (string) $request->query('destination', '');
            if ($destSlug === '_none') {
                $q->whereNull('t.destinationId');
            } elseif ($destSlug !== '') {
                $q->where('d.slug', $destSlug);
            }
            if ($mien) {
                $q->where('d.domain', $mien);
            }
        } elseif ($cfg['table'] === 'tbl_itineraries' && $mien) {
            $q->where('t.region', $mien);
        }

        if ($cfg['table'] === 'tbl_guides') {
            $cat = (string) $request->query('category', '');
            if ($cat === '_none') {
                $q->whereNull('t.categoryId');
            } elseif (ctype_digit($cat)) {
                $q->where('t.categoryId', (int) $cat);
            }
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->where('t.' . $cfg['title'], 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%');
        }

        $filter = (string) $request->query('filter', '');
        if ($filter === 'noimage' && $cfg['cover']) {
            $q->where(fn($w) => $w->whereNull('t.coverImage')->orWhere('t.coverImage', ''));
        }
        if ($filter === 'nolicense' && $cfg['legal']) {
            $q->where(fn($w) => $w->whereNull('t.license')->orWhere('t.license', ''));
        }

        return $q;
    }

    public function index(Request $request, string $kind = 'foods')
    {
        $cfg = $this->cfg($kind);
        $status = array_key_exists((string) $request->query('status'), self::STATUSES) ? (string) $request->query('status') : null;
        $filter = in_array((string) $request->query('filter'), ['problem', 'noimage', 'nolicense'], true) ? (string) $request->query('filter') : '';
        $search = trim((string) $request->query('q', ''));

        $q = $this->baseQuery($cfg, $request);

        // "Chưa đạt điều kiện xuất bản": tính bằng đúng PublishGate (kể cả luật ngày lễ hội), nên lọc phía PHP rồi whereIn.
        if ($filter === 'problem') {
            $ids = [];
            foreach ((clone $q)->select('t.*')->cursor() as $r) {
                if (PublishGate::problems($kind, $r)) {
                    $ids[] = $r->{$cfg['key']};
                }
            }
            $q->whereIn('t.' . $cfg['key'], $ids ?: [0]);
        }

        // Số đếm theo trạng thái tôn trọng MỌI bộ lọc khác (điểm đến, miền, tìm kiếm...), chỉ bỏ lọc trạng thái.
        $counts = (clone $q)->selectRaw('t.reviewStatus as s, COUNT(*) as c')->groupBy('t.reviewStatus')->pluck('c', 's');

        if ($status) {
            $q->where('t.reviewStatus', $status);
        }

        $sort = (string) $request->query('sort', '');
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $sortable = ['title' => 't.' . $cfg['title'], 'updated' => 't.' . $cfg['updatedCol'], 'id' => 't.' . $cfg['key']];
        $q->select('t.*');
        if ($cfg['dest']) {
            $q->addSelect('d.name as destName');
        }
        if ($sort === 'status') {
            $q->orderByRaw("FIELD(t.reviewStatus, 'draft', 'reviewed', 'published') {$dir}");
        } elseif (isset($sortable[$sort])) {
            $q->orderBy($sortable[$sort], $dir);
        } else {
            $q->orderByRaw("FIELD(t.reviewStatus, 'draft', 'reviewed', 'published')");
        }
        $q->orderBy('t.' . $cfg['key'], 'desc');
        $rows = $q->paginate(25)->withQueryString();

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
            'counts' => $counts,
            'statuses' => self::STATUSES,
            'status' => $status,
            'filter' => $filter,
            'destSlug' => (string) $request->query('destination', ''),
            'mien' => (string) $request->query('mien', ''),
            'regions' => self::REGIONS,
            'categoryFilter' => (string) $request->query('category', ''),
            'categories' => $cfg['table'] === 'tbl_guides' ? DB::table('tbl_guide_categories')->orderBy('displayOrder')->orderBy('name')->get(['categoryId', 'name']) : collect(),
            'destinations' => $cfg['dest'] ? DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name')->get(['slug', 'name']) : collect(),
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'adminNames' => DB::table('tbl_admin')->pluck('userName', 'adminId'),
            'ragDirty' => Cache::has('rag.dirty'),
        ]);
    }

    // ------------------------------------------------------------------ Form thêm / sửa

    private function formView(string $kind, array $cfg, object $row, ?int $id)
    {
        $history = $id ? DB::table('tbl_content_review_log')->where('kind', $kind)->where('refId', $id)->orderByDesc('logId')->limit(15)->get() : collect();

        $dates = [];
        if ($id && $kind === 'events') {
            $dates = (new EventDateService())->upcoming($row, new \DateTimeImmutable('today'), 5);
        }

        return view('admin.content-edit', [
            'title' => $id ? 'Sửa nội dung' : 'Thêm nội dung',
            'kind' => $kind,
            'cfg' => $cfg,
            'row' => $row,
            'id' => $id,
            'isNew' => $id === null,
            'problems' => $id ? PublishGate::problems($kind, $row) : [],
            'statuses' => self::STATUSES,
            'destinations' => DB::table('tbl_destinations')->orderBy('name')->get(['destinationId', 'name', 'isActive']),
            'categories' => DB::table('tbl_guide_categories')->orderBy('displayOrder')->orderBy('name')->get(['categoryId', 'name']),
            'history' => $history,
            'adminNames' => DB::table('tbl_admin')->pluck('userName', 'adminId'),
            'dates' => $dates,
            'raw' => $id ? $this->rawCompare($kind, $row) : null,
            'itemCount' => $id && $kind === 'itineraries' ? DB::table('tbl_itinerary_items')->where('itineraryId', $id)->count() : 0,
        ]);
    }

    /** Đối chiếu bản đang lưu với dữ liệu thô Wikipedia trong tbl_import_raw (chỉ món ăn, lễ hội có sourceUrl Wikipedia). */
    private function rawCompare(string $kind, object $row): ?array
    {
        if (!in_array($kind, ['foods', 'events'], true)) {
            return null;
        }
        $t = ImportRaw::titleFromUrl($row->sourceUrl ?? null);
        if (!$t) {
            return ['title' => null, 'raw' => null];
        }
        $raw = ImportRaw::get('wikipedia', config('discover.wikipedia.lang', 'vi') . ':' . $t);

        return ['title' => $t, 'raw' => $raw];
    }

    public function create(string $kind)
    {
        $cfg = $this->cfg($kind);
        abort_unless($cfg['create'], 404);
        $defaults = match ($kind) {
            'events' => ['isLunar' => 0, 'durationDays' => 1],
            'itineraries' => ['days' => 1],
            default => [],
        };

        return $this->formView($kind, $cfg, (object) ($defaults + ['reviewStatus' => 'draft']), null);
    }

    public function edit(string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);

        return $this->formView($kind, $cfg, $row, $id);
    }

    /** @return array{data:array,cover:?\Illuminate\Http\UploadedFile,remove:bool} */
    private function validated(Request $request, string $kind, array $cfg, ?int $id): array
    {
        $rules = array_map(fn($f) => $f[2], $cfg['fields']);
        if ($cfg['slug']) {
            $rules['slug'] = ['nullable', 'max:' . $cfg['slugMax'], 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique($cfg['table'], 'slug')->ignore($id, $cfg['key'])];
        }
        if ($cfg['cover']) {
            $rules['cover'] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:' . (int) config('media.limits.max_file_kb', 8192)];
            $rules['removeCover'] = ['nullable', 'boolean'];
            if ($request->hasFile('cover')) {
                $rules['imageCredit'] = ['required', 'max:255'];   // ảnh tải lên bắt buộc có ghi công (PublishGate)
            }
        }

        $data = $request->validate($rules, [
            'slug.regex' => 'Slug chỉ gồm chữ thường không dấu, số và dấu gạch ngang (vd pho-ha-noi).',
            'slug.unique' => 'Slug này đã có bài khác dùng.',
            'imageCredit.required' => 'Tải ảnh lên thì phải ghi công ảnh (tác giả / giấy phép hoặc "Ảnh: ... (tự chụp)").',
            'cover.mimes' => 'Ảnh bìa chỉ nhận JPG, PNG hoặc WebP.',
            'cover.max' => 'Ảnh bìa vượt dung lượng cho phép.',
            'destinationId.exists' => 'Điểm đến không tồn tại.',
            'destinationId.required' => 'Phải chọn điểm đến.',
        ]);

        $cover = $data['cover'] ?? null;
        $remove = (bool) ($data['removeCover'] ?? false);
        unset($data['cover'], $data['removeCover']);

        $data = array_map(fn($v) => is_string($v) && trim($v) === '' ? null : $v, $data);
        foreach ($cfg['fields'] as $name => $f) {
            if ($f[1] === 'bool') {
                $data[$name] = $request->boolean($name) ? 1 : 0;
            }
        }
        if ($kind === 'guides') {
            $data['body'] = $data['body'] ?? '';       // tbl_guides.body là NOT NULL
        }
        if ($cfg['slug']) {
            if (empty($data['slug'])) {
                unset($data['slug']);                  // sửa: giữ slug cũ; thêm mới: store() tự sinh từ tiêu đề
            }
        }

        return ['data' => $data, 'cover' => $cover, 'remove' => $remove];
    }

    private function makeSlug(array $cfg, string $text): string
    {
        $base = Str::limit(Str::slug($text) ?: 'muc', max(10, $cfg['slugMax'] - 6), '');
        $slug = $base;
        for ($i = 2; DB::table($cfg['table'])->where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function logChange(string $kind, int $id, string $action, ?string $from, ?string $to, ?string $note = null): void
    {
        DB::table('tbl_content_review_log')->insert([
            'kind' => $kind,
            'refId' => $id,
            'action' => $action,
            'fromStatus' => $from,
            'toStatus' => $to,
            'adminId' => session('adminId'),
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'createdAt' => now(),
        ]);
    }

    public function store(Request $request, string $kind)
    {
        $cfg = $this->cfg($kind);
        abort_unless($cfg['create'], 404);
        $v = $this->validated($request, $kind, $cfg, null);
        $data = $v['data'];
        $data['slug'] = $data['slug'] ?? $this->makeSlug($cfg, (string) $data[$cfg['title']]);

        $extra = $kind === 'guides' ? ['updatedOn' => now()->toDateString()] : [];   // PublishGate đòi bài cẩm nang có ngày cập nhật
        $id = (int) DB::table($cfg['table'])->insertGetId($data + $extra + ['reviewStatus' => 'draft', 'updatedAt' => now()], $cfg['key']);

        if ($v['cover']) {
            try {
                $path = $this->covers()->store($v['cover'], $cfg['imgDir'], $data['slug']);
                DB::table($cfg['table'])->where($cfg['key'], $id)->update(['coverImage' => $path]);
            } catch (\RuntimeException $e) {
                $this->logChange($kind, $id, 'create', null, 'draft', 'Tạo mới (ảnh lỗi)');
                PublicCache::flush();

                return redirect()->route('admin.content.edit', [$kind, $id])->with('error', 'Đã tạo, nhưng ảnh bìa chưa lưu được: ' . $e->getMessage());
            }
        }
        $this->logChange($kind, $id, 'create', null, 'draft', 'Tạo mới');
        PublicCache::flush();

        return redirect()->route('admin.content.edit', [$kind, $id])->with('success', 'Đã tạo ở trạng thái Nháp. Bổ sung đủ thông tin rồi xuất bản.');
    }

    public function update(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);
        $v = $this->validated($request, $kind, $cfg, $id);
        $data = $v['data'];

        if ($cfg['cover']) {
            try {
                $slug = $data['slug'] ?? $row->slug;
                if ($v['cover']) {
                    $path = $this->covers()->store($v['cover'], $cfg['imgDir'], $slug);
                    if ($row->coverImage && $row->coverImage !== $path) {
                        $this->covers()->delete($row->coverImage);
                    }
                    $data['coverImage'] = $path;
                } elseif ($v['remove'] && $row->coverImage) {
                    $this->covers()->delete($row->coverImage);
                    $data['coverImage'] = null;
                    if ($cfg['hasImgSrc']) {
                        $data['imageSourceUrl'] = null;
                    }
                }
            } catch (\RuntimeException $e) {
                return back()->withInput()->withErrors(['cover' => $e->getMessage()]);
            }
        }

        $extra = $cfg['touch'] ? ['updatedAt' => now()] : [];
        if ($kind === 'guides') {
            $extra['updatedOn'] = now()->toDateString();    // dòng "Cập nhật lần cuối" của bài
        }
        DB::table($cfg['table'])->where($cfg['key'], $id)->update($data + $extra);
        $this->logChange($kind, $id, 'update', $row->reviewStatus, $row->reviewStatus, 'Sửa nội dung');
        PublicCache::flush();

        // Sửa xong mà không còn đủ điều kiện thì tự hạ về "Đã xem" để không lộ nội dung lỗi ra công khai.
        $fresh = DB::table($cfg['table'])->where($cfg['key'], $id)->first();
        if ($fresh->reviewStatus === 'published' && ($p = PublishGate::problems($kind, $fresh))) {
            DB::table($cfg['table'])->where($cfg['key'], $id)->update(['reviewStatus' => 'reviewed']);
            $this->logChange($kind, $id, 'status', 'published', 'reviewed', 'Tự hạ vì chưa đủ điều kiện: ' . implode('; ', $p));
            PublicCache::flush();

            return redirect()->route('admin.content.edit', [$kind, $id])->with('error', 'Đã lưu, nhưng nội dung chưa đủ điều kiện nên được hạ về "Đã xem": ' . implode('; ', $p));
        }

        return redirect()->route('admin.content.edit', [$kind, $id])->with('success', 'Đã lưu.');
    }

    // ------------------------------------------------------------------ Đổi trạng thái, từ chối, xóa

    public function status(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $new = (string) $request->input('status');
        abort_unless(array_key_exists($new, self::STATUSES), 422);

        $err = $this->apply($kind, $cfg, $id, $new);

        return back()->with($err ? 'error' : 'success', $err ?: 'Đã chuyển sang "' . self::STATUSES[$new] . '".');
    }

    /** Từ chối = về Nháp kèm lý do (bắt buộc), lý do hiện ở danh sách và màn hình sửa. */
    public function reject(Request $request, string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [
            'reason.required' => 'Nhập lý do từ chối.',
            'reason.min' => 'Lý do quá ngắn (tối thiểu 5 ký tự).',
        ]);
        $err = $this->apply($kind, $cfg, $id, 'draft', $data['reason']);

        return back()->with($err ? 'error' : 'success', $err ?: 'Đã từ chối, nội dung về Nháp kèm lý do.');
    }

    public function bulk(Request $request, string $kind)
    {
        $cfg = $this->cfg($kind);
        $isReject = $request->input('action') === 'reject';   // nút "Từ chối" gửi action=reject, không gửi status
        $new = $isReject ? 'draft' : (string) $request->input('status');
        abort_unless(array_key_exists($new, self::STATUSES), 422);
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        if (!$ids) {
            return back()->with('error', 'Chưa chọn dòng nào.');
        }
        $reason = $isReject ? trim((string) $request->input('reason', '')) : '';
        if ($isReject && mb_strlen($reason) < 5) {
            return back()->with('error', 'Từ chối hàng loạt cần nhập lý do (tối thiểu 5 ký tự).');
        }

        $ok = 0;
        $fails = [];
        foreach ($ids as $id) {
            $err = $this->apply($kind, $cfg, $id, $new, $reason !== '' ? $reason : null);
            $err ? $fails[] = "#{$id}: {$err}" : $ok++;
        }
        $msg = "Đã chuyển {$ok}/" . count($ids) . ' dòng sang "' . self::STATUSES[$new] . '".';

        return back()->with($fails ? 'error' : 'success', $fails ? $msg . ' Không đạt: ' . implode(' | ', array_slice($fails, 0, 5)) : $msg);
    }

    /** @return string|null  lỗi (null = thành công) */
    private function apply(string $kind, array $cfg, int $id, string $new, ?string $reason = null): ?string
    {
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first();
        if (!$row) {
            return 'không tồn tại';
        }
        if ($new === 'published' && ($p = PublishGate::problems($kind, $row))) {
            return implode('; ', $p);
        }
        $upd = [
            'reviewStatus' => $new,
            'reviewedBy' => session('adminId'),
            'reviewedAt' => now(),
            'reviewNote' => $new === 'draft' ? $reason : null,   // lý do chỉ có ý nghĩa khi bị từ chối về Nháp
        ];
        if ($cfg['touch']) {
            $upd['updatedAt'] = now();
        }
        DB::table($cfg['table'])->where($cfg['key'], $id)->update($upd);
        $this->logChange($kind, $id, $reason ? 'reject' : 'status', $row->reviewStatus, $new, $reason);
        PublicCache::flush();

        return null;
    }

    public function destroy(string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);
        if ($row->reviewStatus === 'published') {
            return back()->with('error', 'Đang xuất bản, hãy hạ về Nháp trước rồi mới xóa (tránh xóa nhầm nội dung đang hiển thị).');
        }

        DB::transaction(function () use ($cfg, $kind, $id) {
            $ref = $cfg['refType'];
            // Các bảng trỏ tới nội dung bằng (refType, refId) không có khóa ngoại nên phải dọn tay.
            DB::table('tbl_chunks')->where('refType', $ref)->where('refId', $id)->delete();
            if (in_array($ref, ['food', 'event', 'place'], true)) {
                DB::table('tbl_user_marks')->where('refType', $ref)->where('refId', $id)->delete();
                DB::table('tbl_trip_items')->where('refType', $ref)->where('refId', $id)->delete();
                // điểm dừng lịch trình: giữ lại dòng chữ (label), chỉ bỏ liên kết
                DB::table('tbl_itinerary_items')->where('refType', $ref)->where('refId', $id)->update(['refType' => null, 'refId' => null]);
            }
            DB::table($cfg['table'])->where($cfg['key'], $id)->delete();   // điểm dừng của lịch trình tự xóa theo (ON DELETE CASCADE)
        });
        if ($cfg['cover']) {
            $this->covers()->delete($row->coverImage ?? null);
        }
        $this->logChange($kind, $id, 'delete', $row->reviewStatus, null, 'Xóa: ' . ($row->{$cfg['title']} ?? ''));
        PublicCache::flush();

        return redirect()->route('admin.content', $kind)->with('success', 'Đã xóa.');
    }

    // ------------------------------------------------------------------ Xem trước bản nháp

    public function preview(string $kind, int $id)
    {
        $cfg = $this->cfg($kind);
        $row = DB::table($cfg['table'])->where($cfg['key'], $id)->first() ?? abort(404);

        $dest = ($row->destinationId ?? null) ? DB::table('tbl_destinations')->where('destinationId', $row->destinationId)->first() : null;
        $rendered = $kind === 'guides' ? app(GuideCatalog::class)->render((string) $row->body) : null;
        $dates = $kind === 'events' ? (new EventDateService())->upcoming($row, new \DateTimeImmutable('today'), 5) : [];
        $days = [];
        if ($kind === 'itineraries') {
            foreach (DB::table('tbl_itinerary_items')->where('itineraryId', $id)->orderBy('dayNo')->orderBy('orderNo')->get() as $it) {
                $days[$it->dayNo][] = $it;
            }
        }

        return view('admin.content-preview', [
            'title' => 'Xem trước',
            'kind' => $kind,
            'cfg' => $cfg,
            'row' => $row,
            'id' => $id,
            'dest' => $dest,
            'rendered' => $rendered,
            'dates' => $dates,
            'days' => $days,
            'problems' => PublishGate::problems($kind, $row),
            'statuses' => self::STATUSES,
        ]);
    }

    // ------------------------------------------------------------------ Chỉ mục Hỏi đáp (RAG)

    /** Dựng lại chỉ mục Hỏi đáp ngay sau khi trả trang (chỉ nhúng đoạn mới/đổi nên nhanh). */
    public function rebuildRag()
    {
        dispatch(function () {
            $lock = Cache::lock('rag.build.lock', 900);
            if (!$lock->get()) {
                return;
            }
            try {
                Cache::forget('rag.dirty');
                Artisan::call('rag:build');
            } finally {
                $lock->release();
            }
        })->afterResponse();

        return back()->with('success', 'Đang cập nhật chỉ mục Hỏi đáp ở nền. Nội dung mới sẽ trả lời được sau ít phút.');
    }
}
