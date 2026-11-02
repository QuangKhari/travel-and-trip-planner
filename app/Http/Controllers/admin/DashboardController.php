<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\admin\DashboardModel;
use App\Support\AdminBadges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    private DashboardModel $dashboard;

    public function __construct()
    {
        parent::__construct();
        $this->dashboard = new DashboardModel();
    }

    public function index(Request $request)
    {
        $title = 'Tổng quan';

        // Năm của biểu đồ doanh thu: ?year=2026, giới hạn trong 3 năm gần nhất để không nhập bậy
        $thisYear = (int) now()->year;
        $year = (int) $request->query('year', $thisYear);
        if ($year < $thisYear - 2 || $year > $thisYear) {
            $year = $thisYear;
        }

        $summary = $this->dashboard->getSummary();
        $rev = $this->dashboard->getRevenue($year);
        $revenue = $rev['net'];
        $prev = $this->dashboard->getRevenue($year - 1)['net'];
        $m = (int) now()->month - 1;
        $monthNow = $year === $thisYear ? $revenue[$m] : null;
        $monthPrev = $year === $thisYear ? ($m > 0 ? $revenue[$m - 1] : $prev[11]) : null;
        $valueTour = $this->dashboard->getValueDomain();
        $regions = [
            ['label' => 'Miền Bắc', 'n' => (int) ($valueTour['b'] ?? 0)],
            ['label' => 'Miền Trung', 'n' => (int) ($valueTour['t'] ?? 0)],
            ['label' => 'Miền Nam', 'n' => (int) ($valueTour['n'] ?? 0)],
        ];

        // Người dùng thật (trước đây ô này ghi cứng "2,500"): không tính tài khoản đã xóa (status = 'd')
        $users = [
            'total' => (int) DB::table('tbl_users')->where(fn($q) => $q->whereNull('status')->orWhere('status', '!=', 'd'))->count(),
            'new30' => (int) DB::table('tbl_users')->where('createdDate', '>=', now()->subDays(30))->count(),
        ];

        $trips = Schema::hasColumn('tbl_trips', 'kind')
            ? (int) DB::table('tbl_trips')->where('kind', 'trip')->count()
            : (int) DB::table('tbl_trips')->count();

        $todo = AdminBadges::counts() + [
            'toursNoDest' => (int) DB::table('tbl_tours')->whereNull('destinationId')->count(),
            'toursHidden' => (int) DB::table('tbl_tours')->where('availability', 0)->count(),
        ];

        return view('admin.dashboard', [
            'title' => $title,
            'summary' => $summary,
            'users' => $users,
            'trips' => $trips,
            'revenue' => $revenue,
            'revenueGross' => $rev['gross'],
            'revenueRefund' => $rev['refund'],
            'revenuePrevYear' => array_sum($prev),
            'monthNow' => $monthNow,
            'monthPrev' => $monthPrev,
            'statusCounts' => $this->dashboard->statusCounts(),
            'fill' => $this->dashboard->fillRate(),
            'ratings' => $this->dashboard->ratings(),
            'new24' => $this->dashboard->newIn24h(),
            'upcoming' => $this->dashboard->upcomingDepartures(),
            'isMgr' => in_array(session('adminRole'), ['admin', 'manager'], true),
            'year' => $year,
            'years' => range($thisYear, $thisYear - 2),
            'regions' => $regions,
            'toursBooked' => $this->dashboard->getMostTourBooked(),
            'newBooking' => $this->dashboard->getNewBooking(),
            'todo' => $todo,
        ]);
    }
}
