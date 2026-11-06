<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplateMail;
use App\Services\DepartureGroupingService;
use App\Support\AdminBadges;
use App\Support\AuditLog;
use App\Support\BookingPii;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DepartureGroupingController extends Controller
{
    public function __construct(private DepartureGroupingService $groups)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $title = 'Ghép đoàn khởi hành';
        $ready = $this->groups->ready();
        $departures = collect();
        $groups = collect();
        $candidates = [];
        $selected = (int) $request->query('availabilityId', 0);
        $mode = in_array($request->query('mode'), DepartureGroupingService::MODES, true) ? $request->query('mode') : 'strict';
        if ($ready) {
            $departures = DB::table('tbl_tour_availability as a')
                ->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
                ->leftJoin('tbl_departure_group_members as m', 'm.availabilityId', '=', 'a.availabilityId')
                ->where('a.departureDate', '>', now()->toDateString())
                ->select('a.*', 't.title', 'm.groupId')->orderBy('a.departureDate')->limit(200)->get();
            $groups = DB::table('tbl_departure_groups as g')
                ->where('g.status', 'active')->orderBy('g.departureDate')->get();
            foreach ($groups as $group) {
                $group->members = DB::table('tbl_departure_group_members as m')
                    ->join('tbl_tour_availability as a', 'a.availabilityId', '=', 'm.availabilityId')
                    ->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
                    ->where('m.groupId', $group->groupId)->get(['a.*', 't.title']);
                $gm = $group->fingerprintMode ?? 'strict';
                $group->itineraryChanged = $group->members->contains(fn ($m) => $this->groups->fingerprint((int) $m->tourId, $gm) !== $group->itineraryFingerprint);
            }
            if ($selected) $candidates = $this->groups->candidates($selected, $mode);
        }
        return view('admin.departures.grouping', compact('title', 'ready', 'departures', 'groups', 'candidates', 'selected', 'mode'));
    }

    public function minimum(Request $request, int $id)
    {
        $d = $request->validate(['minParticipants' => 'required|integer|min:1|max:500']);
        $result = $this->groups->setMinimum($id, (int) $d['minParticipants']);
        if ($result['ok']) {
            AuditLog::record('departure.minimum', 'departure', $id, 'Cập nhật số khách tối thiểu', null, ['minParticipants' => (int) $d['minParticipants']]);
            AdminBadges::forget();
        }
        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function merge(Request $request)
    {
        $d = $request->validate([
            'firstId' => 'required|integer|different:secondId|exists:tbl_tour_availability,availabilityId',
            'secondId' => 'required|integer|exists:tbl_tour_availability,availabilityId',
            'note' => 'nullable|string|max:1000',
            'mode' => 'nullable|in:strict,titles',
        ]);
        $mode = $d['mode'] ?? 'strict';
        if ($mode === 'titles' && !$request->boolean('confirmTitles')) {
            return back()->with('error', 'Chế độ chỉ so tiêu đề: phải tích xác nhận đã tự đối chiếu nội dung từng ngày của hai tour.');
        }
        $result = $this->groups->merge((int) $d['firstId'], (int) $d['secondId'], $d['note'] ?? null, $mode);
        if ($result['ok']) {
            AuditLog::record('departure.group.merge', 'departure_group', $result['id'], 'Ghép hai ngày khởi hành (' . $mode . ')', null, ['availabilityIds' => [(int) $d['firstId'], (int) $d['secondId']], 'mode' => $mode]);
            $this->notifyMembers($result['availabilityIds'], 'merged', $mode);
            AdminBadges::forget();
        }
        return redirect()->route('admin.departures.grouping', ['availabilityId' => $d['firstId'], 'mode' => $mode])->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function disband(int $id)
    {
        $result = $this->groups->disband($id);
        if ($result['ok']) {
            AuditLog::record('departure.group.disband', 'departure_group', $id, 'Tách đoàn chung');
            $this->notifyMembers($result['availabilityIds'] ?? [], 'disbanded', 'strict');
            AdminBadges::forget();
        }
        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Báo cho khách của các lịch trong đoàn (đơn đang giữ chỗ và đã xác nhận) khi đoàn được ghép / tách. */
    private function notifyMembers(array $availabilityIds, string $event, string $mode): void
    {
        if ($availabilityIds === []) return;
        try {
            $rows = DB::table('tbl_booking as b')->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
                ->whereIn('b.availabilityId', $availabilityIds)->whereIn('b.bookingStatus', ['n', 'y'])
                ->get(['b.bookingId', 'b.bookingCode', 'b.email', 'b.departureDate', 't.title']);
            foreach ($rows as $row) {
                $row = BookingPii::decrypt($row);
                if (!filter_var($row->email, FILTER_VALIDATE_EMAIL)) continue;
                Mail::to($row->email)->queue(new TemplateMail('admin.emails.departure-group', [
                    'event' => $event, 'mode' => $mode, 'bookingCode' => $row->bookingCode, 'tourTitle' => $row->title,
                    'departureDate' => $row->departureDate, 'url' => route('my-tours'),
                ], 'Travela — thông tin đoàn khởi hành ' . $row->bookingCode));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
