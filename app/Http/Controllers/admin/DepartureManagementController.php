<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Services\DepartureService;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lịch khởi hành (D-02): xem mọi ngày khởi hành sắp tới và tỷ lệ lấp đầy; thêm / sửa / ngừng bán / xóa ngày của từng tour.
 */
class DepartureManagementController extends Controller
{
    public function __construct(private DepartureService $departures)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $title = 'Lịch khởi hành';
        $tourId = (int) $request->query('tour', 0);
        $when = in_array($request->query('when'), ['upcoming', 'past', 'all'], true) ? $request->query('when') : 'upcoming';
        $today = now()->toDateString();

        $tours = DB::table('tbl_tours')->orderBy('title')->get(['tourId', 'title', 'availability', 'durationDays', 'startDate', 'endDate']);
        $tour = $tourId ? $tours->firstWhere('tourId', $tourId) : null;
        if ($tour) {
            $this->departures->ensureForTour($tourId);
        }

        $q = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->select('a.*', 't.title', 't.durationDays', 't.startDate as tStart', 't.endDate as tEnd', 't.availability');
        if ($tour) {
            $q->where('a.tourId', $tourId);
        }
        if ($when === 'upcoming') {
            $q->where('a.departureDate', '>', $today)->orderBy('a.departureDate');
        } elseif ($when === 'past') {
            $q->where('a.departureDate', '<=', $today)->orderByDesc('a.departureDate');
        } else {
            $q->orderBy('a.departureDate');
        }
        $rows = $q->paginate(25)->withQueryString();
        $bookingCounts = DB::table('tbl_booking')->whereIn('availabilityId', collect($rows->items())->pluck('availabilityId'))
            ->where('bookingStatus', '!=', 'c')->selectRaw('availabilityId, COUNT(*) n')->groupBy('availabilityId')->pluck('n', 'availabilityId');
        foreach ($rows->items() as $r) {
            $days = DepartureService::durationDays((object) ['durationDays' => $r->durationDays, 'startDate' => $r->tStart, 'endDate' => $r->tEnd]);
            $r->endDate = DepartureService::endDate($r->departureDate, $days);
            $r->remaining = DepartureService::remaining($r);
            $r->fill = $r->totalSlots > 0 ? (int) round(($r->bookedSlots + $r->heldSlots) / $r->totalSlots * 100) : 0;
            $r->bookings = (int) ($bookingCounts[$r->availabilityId] ?? 0);
            $r->isPast = $r->departureDate <= $today;
        }

        // Tỷ lệ lấp đầy trung bình các chuyến sắp tới (§12.3)
        $up = DB::table('tbl_tour_availability')->where('departureDate', '>', $today)->where('status', 'open')
            ->selectRaw('COUNT(*) n, COALESCE(SUM(totalSlots),0) total, COALESCE(SUM(bookedSlots),0) booked, COALESCE(SUM(heldSlots),0) held')->first();
        $soon = DB::table('tbl_tour_availability')->where('status', 'open')->whereBetween('departureDate', [now()->addDay()->toDateString(), now()->addDays(14)->toDateString()])->count();
        $full = DB::table('tbl_tour_availability')->where('departureDate', '>', $today)->whereRaw('bookedSlots + heldSlots >= totalSlots')->count();

        return view('admin.departures.index', compact('title', 'rows', 'tours', 'tour', 'when', 'up', 'soon', 'full'));
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'tourId' => 'required|integer|exists:tbl_tours,tourId',
            'departureDate' => 'required|date_format:Y-m-d',
            'totalSlots' => 'required|integer|min:1|max:500',
            'note' => 'nullable|string|max:255',
            'repeat' => 'nullable|integer|min:0|max:12',
            'every' => 'nullable|integer|in:7,14,30',
        ], [
            'departureDate.*' => 'Ngày khởi hành không hợp lệ.',
            'totalSlots.*' => 'Số chỗ phải từ 1 đến 500.',
        ]);

        // Thêm nhiều ngày một lần: lặp lại mỗi 7 / 14 / 30 ngày
        $dates = [$d['departureDate']];
        for ($i = 1; $i <= (int) ($d['repeat'] ?? 0); $i++) {
            $dates[] = Carbon::parse($d['departureDate'])->addDays($i * (int) ($d['every'] ?? 7))->toDateString();
        }
        $ok = [];
        $fail = [];
        foreach ($dates as $date) {
            $res = $this->departures->add((int) $d['tourId'], $date, (int) $d['totalSlots'], $d['note'] ?? null);
            if ($res['ok']) {
                $ok[] = Carbon::parse($date)->format('d/m/Y');
                AuditLog::record('departure.create', 'departure', $res['id'], 'Thêm ngày ' . Carbon::parse($date)->format('d/m/Y') . ' (' . $d['totalSlots'] . ' chỗ) cho tour #' . $d['tourId'],
                    null, ['tourId' => (int) $d['tourId'], 'departureDate' => $date, 'totalSlots' => (int) $d['totalSlots']]);
            } else {
                $fail[] = $res['message'];
            }
        }

        $back = redirect()->route('admin.departures', ['tour' => $d['tourId']]);
        if ($ok) {
            $back->with('success', 'Đã thêm ' . count($ok) . ' ngày khởi hành: ' . implode(', ', $ok) . '.');
        }
        if ($fail) {
            $back->with('error', implode(' ', array_unique($fail)));
        }

        return $back;
    }

    public function update(Request $request, int $id)
    {
        $d = $request->validate([
            'totalSlots' => 'required|integer|min:1|max:500',
            'departureDate' => 'nullable|date_format:Y-m-d',
            'note' => 'nullable|string|max:255',
        ]);
        $res = $this->departures->update($id, (int) $d['totalSlots'], $d['departureDate'] ?? null, $d['note'] ?? null);
        if ($res['ok']) {
            [$b, $a] = AuditLog::diff(
                array_intersect_key($res['before'], array_flip(['departureDate', 'totalSlots', 'note'])),
                array_intersect_key($res['after'], array_flip(['departureDate', 'totalSlots', 'note']))
            );
            if ($a) {
                AuditLog::record('departure.update', 'departure', $id, 'Sửa ngày khởi hành ' . Carbon::parse($res['after']['departureDate'])->format('d/m/Y') . ' (tour #' . $res['tourId'] . ')', $b, $a);
            }
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function toggle(int $id)
    {
        $res = $this->departures->toggle($id);
        if ($res['ok']) {
            AuditLog::record('departure.close', 'departure', $id, ($res['status'] === 'open' ? 'Mở bán lại' : 'Ngừng bán') . ' ngày ' . Carbon::parse($res['date'])->format('d/m/Y') . ' (tour #' . $res['tourId'] . ')',
                ['status' => $res['status'] === 'open' ? 'closed' : 'open'], ['status' => $res['status']]);
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function destroy(int $id)
    {
        $res = $this->departures->delete($id);
        if ($res['ok']) {
            AuditLog::record('departure.delete', 'departure', $id, 'Xóa ngày ' . Carbon::parse($res['row']['departureDate'])->format('d/m/Y') . ' (tour #' . $res['row']['tourId'] . ')', $res['row'], null);
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }
}
