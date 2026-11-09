<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\DepartureService;
use App\Services\ResourceAllocationService;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Xếp xe / hướng dẫn viên cho các đoàn thường (lịch khởi hành), theo khung giờ.
 *  - Bảng lịch: mỗi tài nguyên một dòng, mỗi ngày một ô, ô ghi số chỗ đã dùng / sức chứa (đỉnh trong ngày).
 *  - Danh sách lịch khởi hành sắp tới cùng xe / HDV đã xếp và cảnh báo "chưa có xe", "xe thiếu chỗ".
 *  - Xếp / trả tài nguyên đi qua ResourceAllocationService (khóa dòng tài nguyên, không vượt sức chứa, không trùng giờ).
 * Danh mục tài nguyên dùng chung tbl_rescue_resources với luồng ghép một phần hành trình.
 */
class ResourceScheduleController extends Controller
{
    public const KINDS = ['transport' => 'Xe / phương tiện', 'guide' => 'Hướng dẫn viên', 'activity' => 'Hoạt động / dịch vụ có sức chứa'];
    public const OWNER = 'departure';

    public function index(Request $request)
    {
        $title = 'Xếp xe & hướng dẫn viên';
        if (!ResourceAllocationService::ready() || !Schema::hasTable('tbl_rescue_resources')) {
            return view('admin.resources.index', ['title' => $title, 'ready' => false]);
        }
        $from = $this->date($request->query('from')) ?? now()->startOfDay();
        $span = in_array((int) $request->query('days'), [7, 14, 21, 28], true) ? (int) $request->query('days') : 14;
        $to = $from->copy()->addDays($span);   // [from, to)
        $kind = array_key_exists((string) $request->query('kind'), self::KINDS) ? (string) $request->query('kind') : null;
        $focus = (int) $request->query('availabilityId', 0);

        $resources = DB::table('tbl_rescue_resources')->when($kind, fn($q) => $q->where('kind', $kind))
            ->orderByDesc('active')->orderBy('kind')->orderBy('name')->get();

        // phân bổ còn hiệu lực giao với cửa sổ đang xem
        $allocs = DB::table('tbl_resource_allocations as a')
            ->join('tbl_rescue_resources as r', 'r.resourceId', '=', 'a.resourceId')
            ->leftJoin('tbl_tour_availability as av', function ($j) {
                $j->on('av.availabilityId', '=', 'a.ownerId')->where('a.ownerType', '=', self::OWNER);
            })
            ->leftJoin('tbl_tours as t', 't.tourId', '=', 'av.tourId')
            ->whereIn('a.status', ResourceAllocationService::ACTIVE)
            ->where('a.startAt', '<', $to)->where('a.endAt', '>', $from)
            ->orderBy('a.startAt')
            ->get(['a.*', 'r.name as resourceName', 'r.kind', 'r.capacity', 't.title as tourTitle', 'av.departureDate']);

        // ô lịch: đỉnh sử dụng trong ngày (cộng các phân bổ giao với ngày đó, cùng quy tắc với service)
        $days = [];
        for ($d = $from->copy(); $d < $to; $d->addDay()) {
            $days[] = $d->copy();
        }
        $grid = [];
        foreach ($resources as $r) {
            foreach ($days as $i => $d) {
                $end = $d->copy()->addDay();
                $mine = $allocs->filter(fn($a) => (int) $a->resourceId === (int) $r->resourceId && $a->startAt < $end->format('Y-m-d H:i:s') && $a->endAt > $d->format('Y-m-d H:i:s'));
                $used = (int) $mine->sum('quantity');
                $grid[$r->resourceId][$i] = ['used' => $used, 'n' => $mine->count(),
                    'level' => $used === 0 ? 'free' : ($used > $r->capacity ? 'over' : ($used === (int) $r->capacity ? 'full' : 'part')),
                    'title' => $mine->map(fn($a) => ($a->tourTitle ? $a->tourTitle . ' · ' : ($a->ownerType !== self::OWNER ? 'Ghép chặng · ' : ''))
                        . Carbon::parse($a->startAt)->format('d/m H:i') . '–' . Carbon::parse($a->endAt)->format('d/m H:i') . ' · ' . $a->quantity . ' chỗ')->implode("\n")];
            }
        }

        // lịch khởi hành sắp tới trong cửa sổ + tài nguyên đã xếp
        $deps = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->where('a.departureDate', '>=', $from->toDateString())->where('a.departureDate', '<', $to->toDateString())
            ->when($focus, fn($q) => $q->orWhere('a.availabilityId', $focus))
            ->orderBy('a.departureDate')->limit(200)
            ->get(['a.availabilityId', 'a.tourId', 'a.departureDate', 'a.totalSlots', 'a.bookedSlots', 'a.heldSlots', 'a.status', 't.title', 't.durationDays', 't.startDate as tStart', 't.endDate as tEnd']);
        $assigned = DB::table('tbl_resource_allocations as a')->join('tbl_rescue_resources as r', 'r.resourceId', '=', 'a.resourceId')
            ->where('a.ownerType', self::OWNER)->whereIn('a.ownerId', $deps->pluck('availabilityId'))->whereIn('a.status', ResourceAllocationService::ACTIVE)
            ->orderBy('a.startAt')->get(['a.*', 'r.name', 'r.kind', 'r.capacity'])->groupBy('ownerId');
        foreach ($deps as $d) {
            $len = DepartureService::durationDays((object) ['durationDays' => $d->durationDays, 'startDate' => $d->tStart, 'endDate' => $d->tEnd]);
            $d->endDate = DepartureService::endDate($d->departureDate, $len);
            $d->pax = (int) $d->bookedSlots + (int) $d->heldSlots;
            $d->alloc = $assigned->get($d->availabilityId, collect());
            $seats = (int) $d->alloc->where('kind', 'transport')->sum('quantity');
            $d->hasGuide = $d->alloc->where('kind', 'guide')->isNotEmpty();
            $d->hasTransport = $seats > 0;
            $d->seatsShort = $d->hasTransport && $seats < $d->pax ? $d->pax - $seats : 0;
            // gợi ý khung giờ mặc định cho form: 06:00 ngày đi -> 20:00 ngày về
            $d->defStart = $d->departureDate . 'T06:00';
            $d->defEnd = $d->endDate . 'T20:00';
        }

        $kpi = [
            'deps' => $deps->count(),
            'noTransport' => $deps->filter(fn($d) => $d->pax > 0 && !$d->hasTransport)->count(),
            'noGuide' => $deps->filter(fn($d) => $d->pax > 0 && !$d->hasGuide)->count(),
            'short' => $deps->filter(fn($d) => $d->seatsShort > 0)->count(),
        ];

        return view('admin.resources.index', compact('title', 'resources', 'allocs', 'days', 'grid', 'deps', 'from', 'span', 'kind', 'focus', 'kpi') + [
            'ready' => true, 'kinds' => self::KINDS, 'activeRes' => $resources->where('active', true)->values(),
        ]);
    }

    /** POST /admin/resources/allocate: xếp một tài nguyên cho một lịch khởi hành. */
    public function allocate(Request $request, ResourceAllocationService $svc)
    {
        $data = $request->validate([
            'availabilityId' => 'required|integer|exists:tbl_tour_availability,availabilityId',
            'resourceId' => 'required|integer|exists:tbl_rescue_resources,resourceId',
            'startAt' => 'required|date',
            'endAt' => 'required|date|after:startAt',
            'quantity' => 'required|integer|min:1|max:10000',
            'note' => 'nullable|string|max:255',
        ], [], ['availabilityId' => 'lịch khởi hành', 'resourceId' => 'tài nguyên', 'startAt' => 'giờ bắt đầu', 'endAt' => 'giờ kết thúc', 'quantity' => 'số chỗ']);
        $res = DB::table('tbl_rescue_resources')->where('resourceId', $data['resourceId'])->first();
        // hướng dẫn viên / đội hướng dẫn: một đoàn chiếm cả "suất" (1), sức chứa > 1 nghĩa là đội nhiều người
        try {
            $a = $svc->allocate((int) $data['resourceId'], self::OWNER, (int) $data['availabilityId'], $data['startAt'], $data['endAt'],
                (int) $data['quantity'], 'confirmed', (int) session('adminId') ?: null, $data['note'] ?? null);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', $e->validator->errors()->first());
        }
        AuditLog::record('resource.allocate', 'resource_allocation', $a->allocationId,
            'Xếp ' . $res->name . ' cho lịch #' . $data['availabilityId'] . ' (' . $a->quantity . ' chỗ, ' . Carbon::parse($a->startAt)->format('d/m H:i') . '–' . Carbon::parse($a->endAt)->format('d/m H:i') . ')');

        return back()->with('success', 'Đã xếp “' . $res->name . '” cho lịch #' . $data['availabilityId'] . '.');
    }

    /** POST /admin/resources/allocations/{id}/release */
    public function release(int $id, ResourceAllocationService $svc)
    {
        $a = DB::table('tbl_resource_allocations')->where('allocationId', $id)->first();
        abort_if(!$a, 404);
        if (!$svc->release($id)) {
            return back()->with('error', 'Phân bổ này đã được trả trước đó.');
        }
        AuditLog::record('resource.release', 'resource_allocation', $id, 'Trả tài nguyên #' . $a->resourceId . ' của ' . $a->ownerType . ' #' . $a->ownerId);

        return back()->with('success', 'Đã trả tài nguyên, khung giờ này được giải phóng.');
    }

    /** POST /admin/resources: đăng ký xe / HDV mới. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'kind' => 'required|in:' . implode(',', array_keys(self::KINDS)),
            'capacity' => 'required|integer|min:1|max:10000',
        ], [], ['name' => 'tên', 'kind' => 'loại', 'capacity' => 'sức chứa']);
        $id = DB::table('tbl_rescue_resources')->insertGetId($data + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('resource.create', 'rescue_resource', $id, 'Đăng ký ' . self::KINDS[$data['kind']] . ': ' . $data['name']);

        return back()->with('success', 'Đã thêm “' . $data['name'] . '”.');
    }

    /** POST /admin/resources/{id}/toggle: ngừng / dùng lại (phân bổ đã có giữ nguyên; ngừng thì không xếp mới được). */
    public function toggle(int $id)
    {
        $r = DB::table('tbl_rescue_resources')->where('resourceId', $id)->first();
        abort_if(!$r, 404);
        DB::table('tbl_rescue_resources')->where('resourceId', $id)->update(['active' => !$r->active, 'updated_at' => now()]);
        AuditLog::record('resource.toggle', 'rescue_resource', $id, ($r->active ? 'Ngừng dùng ' : 'Dùng lại ') . $r->name);

        return back()->with('success', ($r->active ? 'Đã ngừng dùng “' : 'Đã dùng lại “') . $r->name . '”.');
    }

    private function date(?string $v): ?Carbon
    {
        if (!$v || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return Carbon::parse($v)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
