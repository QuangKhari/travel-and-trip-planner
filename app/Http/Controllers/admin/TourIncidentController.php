<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplateMail;
use App\Services\IncidentService as I;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use App\Support\BookingPii;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Quản lý sự cố tour: ghi nhận, phân loại, theo dõi xử lý, báo khách và nối với phương án Departure Rescue khi sự cố làm thay đổi lịch trình đã cam kết.
 * Sự cố sức khỏe / an toàn mặc định "bảo mật": nội dung chỉ người báo và quản trị viên cấp cao xem được.
 */
class TourIncidentController extends Controller
{
    private function canSee(object $inc): bool
    {
        return !$inc->confidential || session('adminRole') === 'admin' || (int) $inc->reportedBy === (int) session('adminId');
    }

    public function index(Request $request)
    {
        $title = 'Sự cố tour';
        $ready = I::ready();
        $status = in_array($request->query('status'), ['active', 'resolved', 'closed', 'all'], true) ? $request->query('status') : 'active';
        $rows = collect();
        $departures = collect();
        if ($ready) {
            $q = DB::table('tbl_tour_incidents as i')->leftJoin('tbl_tours as t', 't.tourId', '=', 'i.tourId')->leftJoin('tbl_tour_availability as a', 'a.availabilityId', '=', 'i.availabilityId')
                ->select('i.*', 't.title as tourTitle', 'a.departureDate')
                ->orderByRaw("FIELD(i.severity, 'critical', 'high', 'medium', 'low')")->orderByDesc('i.createdAt');
            if ($status === 'active') {
                $q->whereIn('i.status', ['open', 'handling']);
            } elseif ($status !== 'all') {
                $q->where('i.status', $status);
            }
            $rows = $q->paginate(25)->withQueryString();
            foreach ($rows->items() as $r) {
                $r->visible = $this->canSee($r);
            }
            $departures = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
                ->whereBetween('a.departureDate', [today()->subDays(30)->toDateString(), today()->addDays(120)->toDateString()])
                ->orderBy('a.departureDate')->limit(300)->get(['a.availabilityId', 'a.departureDate', 't.title']);
        }

        $suppliers = Schema::hasTable('tbl_suppliers') ? DB::table('tbl_suppliers')->where('active', true)->orderBy('name')->get(['supplierId', 'name']) : collect();

        return view('admin.incidents.index', ['title' => $title, 'ready' => $ready, 'rows' => $rows, 'status' => $status, 'departures' => $departures, 'suppliers' => $suppliers,
            'types' => I::TYPES, 'severities' => I::SEVERITIES, 'statuses' => I::STATUSES]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'availabilityId' => 'nullable|integer|exists:tbl_tour_availability,availabilityId',
            'type' => 'required|in:' . implode(',', array_keys(I::TYPES)),
            'severity' => 'required|in:' . implode(',', array_keys(I::SEVERITIES)),
            'title' => 'required|string|min:5|max:200',
            'description' => 'nullable|string|max:3000',
            'confidential' => 'nullable|boolean',
            'supplierId' => 'nullable|integer|exists:tbl_suppliers,supplierId',
        ]);
        $tourId = !empty($d['availabilityId']) ? DB::table('tbl_tour_availability')->where('availabilityId', $d['availabilityId'])->value('tourId') : null;
        $admin = (int) session('adminId');
        $id = DB::table('tbl_tour_incidents')->insertGetId([
            'availabilityId' => $d['availabilityId'] ?? null, 'tourId' => $tourId, 'type' => $d['type'], 'severity' => $d['severity'], 'status' => 'open',
            'title' => $d['title'], 'description' => $d['description'] ?? null, 'confidential' => $d['type'] === 'health' || (bool) ($d['confidential'] ?? false),
            'reportedBy' => $admin, 'assignedTo' => $admin, 'createdAt' => now(), 'updatedAt' => now(),
        ] + (Schema::hasColumn('tbl_tour_incidents', 'supplierId') ? ['supplierId' => $d['supplierId'] ?? null] : []));
        I::log($id, $admin, 'created', 'Ghi nhận sự cố ' . mb_strtolower(I::TYPES[$d['type']]) . ', mức ' . mb_strtolower(I::SEVERITIES[$d['severity']]) . '.');
        AuditLog::record('incident.create', 'incident', $id, 'Ghi nhận sự cố: ' . $d['title']);
        AdminBadges::forget();

        return redirect()->route('admin.incidents.show', $id)->with('success', 'Đã ghi nhận sự cố #' . $id . '.');
    }

    public function show(int $id)
    {
        $inc = DB::table('tbl_tour_incidents')->where('incidentId', $id)->first() ?? abort(404);
        $title = 'Sự cố #' . $id;
        $visible = $this->canSee($inc);
        $tour = $inc->tourId ? DB::table('tbl_tours')->where('tourId', $inc->tourId)->first(['tourId', 'title']) : null;
        $dep = $inc->availabilityId ? DB::table('tbl_tour_availability')->where('availabilityId', $inc->availabilityId)->first() : null;
        $events = DB::table('tbl_incident_events as e')->leftJoin('tbl_admin as a', 'a.adminId', '=', 'e.adminId')->where('e.incidentId', $id)
            ->orderBy('e.eventId')->get(['e.*', 'a.userName']);
        $affected = collect();
        $people = 0;
        if ($dep) {
            $affected = DB::table('tbl_booking')->where('availabilityId', $dep->availabilityId)->whereIn('bookingStatus', ['n', 'y'])->orderBy('bookingId')->limit(100)
                ->get(['bookingId', 'bookingCode', 'fullName', 'phoneNumber', 'email', 'numAdults', 'numChildren', 'bookingStatus']);
            BookingPii::decryptAll($affected->all());
            $people = (int) DB::table('tbl_booking')->where('availabilityId', $dep->availabilityId)->whereIn('bookingStatus', ['n', 'y'])->sum(DB::raw('numAdults + numChildren'));
        }
        $plan = $inc->rescuePlanId ? DB::table('tbl_departure_rescue_plans')->where('planId', $inc->rescuePlanId)->first(['planId', 'type', 'status']) : null;

        return view('admin.incidents.show', ['title' => $title, 'inc' => $inc, 'visible' => $visible, 'tour' => $tour, 'dep' => $dep, 'events' => $events,
            'affected' => $affected, 'people' => $people, 'plan' => $plan, 'types' => I::TYPES, 'severities' => I::SEVERITIES, 'statuses' => I::STATUSES, 'flow' => I::FLOW]);
    }

    public function status(Request $request, int $id)
    {
        $d = $request->validate(['status' => 'required|in:handling,resolved,closed', 'note' => 'nullable|string|max:1500']);
        $admin = (int) session('adminId');
        $res = DB::transaction(function () use ($id, $d, $admin) {
            $inc = DB::table('tbl_tour_incidents')->where('incidentId', $id)->lockForUpdate()->first();
            if (!$inc || !in_array($d['status'], I::FLOW[$inc->status] ?? [], true)) {
                return ['ok' => false, 'message' => 'Không chuyển được sang trạng thái này từ "' . (I::STATUSES[$inc->status ?? ''] ?? '') . '".'];
            }
            if ($d['status'] === 'resolved' && mb_strlen(trim((string) ($d['note'] ?? ''))) < 10) {
                return ['ok' => false, 'message' => 'Khi đánh dấu đã xử lý phải ghi kết quả (tối thiểu 10 ký tự).'];
            }
            $upd = ['status' => $d['status'], 'updatedAt' => now()];
            if ($d['status'] === 'resolved') {
                $upd += ['resolution' => trim($d['note']), 'resolvedAt' => now()];
            }
            DB::table('tbl_tour_incidents')->where('incidentId', $id)->update($upd);
            I::log($id, $admin, 'status', 'Chuyển sang "' . I::STATUSES[$d['status']] . '"' . (!empty($d['note']) ? ': ' . trim($d['note']) : '.'));

            return ['ok' => true, 'message' => 'Đã cập nhật trạng thái.'];
        });
        if ($res['ok']) {
            AuditLog::record('incident.status', 'incident', $id, 'Sự cố → ' . I::STATUSES[$d['status']]);
            AdminBadges::forget();
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function note(Request $request, int $id)
    {
        $d = $request->validate(['note' => 'required|string|min:3|max:1500']);
        abort_unless(DB::table('tbl_tour_incidents')->where('incidentId', $id)->exists(), 404);
        I::log($id, (int) session('adminId'), 'note', trim($d['note']));

        return back()->with('success', 'Đã ghi nhận.');
    }

    /** Gửi email thông báo sự cố cho khách của lịch khởi hành (đơn giữ chỗ và đã xác nhận). */
    public function notify(Request $request, int $id)
    {
        $d = $request->validate(['message' => 'required|string|min:10|max:1500']);
        $inc = DB::table('tbl_tour_incidents')->where('incidentId', $id)->first() ?? abort(404);
        if (!$inc->availabilityId) {
            return back()->with('error', 'Sự cố chưa gắn lịch khởi hành nên không biết gửi cho khách nào.');
        }
        $rows = DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')->where('b.availabilityId', $inc->availabilityId)
            ->whereIn('b.bookingStatus', ['n', 'y'])->get(['b.bookingId', 'b.bookingCode', 'b.email', 'b.departureDate', 't.title']);
        $sent = 0;
        foreach ($rows as $row) {
            $row = BookingPii::decrypt($row);
            if (!filter_var($row->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            try {
                Mail::to($row->email)->queue(new TemplateMail('admin.emails.tour-incident', [
                    'bookingCode' => $row->bookingCode, 'tourTitle' => $row->title, 'departureDate' => $row->departureDate,
                    'body' => trim($d['message']), 'url' => route('my-tours'),
                ], 'Travela — thông báo về chuyến đi ' . $row->bookingCode));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        I::log($id, (int) session('adminId'), 'notify', 'Đã gửi email cho ' . $sent . '/' . $rows->count() . ' khách: ' . trim($d['message']));
        AuditLog::record('incident.notify', 'incident', $id, 'Báo sự cố cho ' . $sent . ' khách');

        return back()->with('success', 'Đã gửi email cho ' . $sent . ' khách.');
    }
}
