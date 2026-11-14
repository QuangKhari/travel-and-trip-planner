<?php

namespace App\Services\Planner;

use App\Console\Commands\PlannerCoverage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Số liệu vận hành của Trip Planner và La bàn cho trang quản trị "Phân tích Trip Planner".
 * Mọi số đếm trên dữ liệu thật trong CSDL (không ước lượng). Khoảng thời gian: N ngày gần nhất.
 */
final class PlannerAnalytics
{
    public function build(int $days = 30): array
    {
        $since = now()->subDays($days);
        $trips = DB::table('tbl_trips')->where('kind', 'trip');

        // 1. Chuyến đi
        $made = (clone $trips)->where('createdAt', '>=', $since);
        $bySource = (clone $made)->select('source', DB::raw('count(*) n'))->groupBy('source')->pluck('n', 'source')->all();
        $active = (clone $trips)->whereNotNull('startDate')->where('startDate', '<=', now()->toDateString())
            ->whereRaw('DATE_ADD(startDate, INTERVAL days - 1 DAY) >= ?', [now()->toDateString()])->count();
        $upcoming = (clone $trips)->whereNotNull('startDate')->whereBetween('startDate', [now()->addDay()->toDateString(), now()->addDays(16)->toDateString()])->count();

        // 2. Từ chuyến tự lên tới đặt tour (đơn có tripId: đặt từ banner "có tour giống lịch trình này")
        $fromTrip = Schema::hasColumn('tbl_booking', 'tripId')
            ? DB::table('tbl_booking as b')->leftJoin('tbl_checkout as c', 'c.bookingId', '=', 'b.bookingId')
                ->whereNotNull('b.tripId')->where('b.bookingDate', '>=', $since)
                ->selectRaw("count(*) n, sum(b.bookingStatus <> 'c') live, avg(b.matchScore) score, sum(case when c.paymentStatus = 'y' then b.totalPrice else 0 end) paid")->first()
            : null;
        $allBookings = DB::table('tbl_booking')->where('bookingDate', '>=', $since)->count();

        // 3. La bàn: tỷ lệ chấp nhận đề xuất, độ trễ, luật vs LLM
        $ai = [];
        if (Schema::hasTable('tbl_ai_runs')) {
            foreach (DB::table('tbl_ai_runs')->where('createdAt', '>=', $since)->select('feature', 'engine',
                DB::raw('count(*) n'), DB::raw("sum(outcome in ('proposed','applied','skipped')) proposed"), DB::raw("sum(outcome = 'applied') applied"), DB::raw("sum(outcome = 'skipped') skipped"),
                DB::raw("sum(outcome in ('error','fallback')) failed"), DB::raw('avg(latencyMs) ms'))->groupBy('feature', 'engine')->get() as $r) {
                $ai[] = ['feature' => $r->feature, 'engine' => $r->engine, 'n' => (int) $r->n, 'proposed' => (int) $r->proposed, 'applied' => (int) $r->applied,
                    'skipped' => (int) $r->skipped, 'accept' => ($r->applied + $r->skipped) ? round(100 * $r->applied / ($r->applied + $r->skipped)) : null,
                    'failed' => (int) $r->failed, 'ms' => (int) round((float) $r->ms)];
            }
        }

        // 4. Theo dõi chuyến (TripWatch)
        $watch = Schema::hasTable('tbl_trip_events')
            ? DB::table('tbl_trip_events')->where('createdAt', '>=', $since)->select('kind', DB::raw('count(*) n'), DB::raw('count(distinct tripId) trips'))->groupBy('kind')->get()
                ->mapWithKeys(fn($r) => [$r->kind => ['n' => (int) $r->n, 'trips' => (int) $r->trips]])->all()
            : [];

        // 5. Điểm đến thiếu dữ liệu nhất = nhiều người lên kế hoạch / hỏi tới mà độ phủ thấp
        $demand = (clone $trips)->whereNotNull('destinationId')->where('createdAt', '>=', now()->subDays(max($days, 90)))
            ->select('destinationId', DB::raw('count(*) n'))->groupBy('destinationId')->pluck('n', 'destinationId')->all();
        if (Schema::hasTable('tbl_content_requests')) {
            foreach (DB::table('tbl_content_requests')->whereNotNull('nearestDestinationId')->where('resolved', 0)
                ->select('nearestDestinationId', DB::raw('sum(hits) h'))->groupBy('nearestDestinationId')->get() as $r) {
                $demand[$r->nearestDestinationId] = ($demand[$r->nearestDestinationId] ?? 0) + (int) $r->h;
            }
        }
        arsort($demand);
        $gaps = [];
        foreach (array_slice($demand, 0, 12, true) as $id => $n) {
            $c = PlannerCoverage::measure((int) $id);
            $gaps[] = ['id' => (int) $id, 'name' => $c['name'], 'demand' => (int) $n, 'ok' => $c['ok'], 'hoursPct' => $c['hoursPct'], 'pricePct' => $c['pricePct'],
                'sights' => $c['sights'], 'eat' => $c['eat'], 'gaps' => $c['gaps'], 'score' => (int) $n * (count($c['gaps']) + 1)];
        }
        usort($gaps, fn($a, $b) => $b['score'] <=> $a['score']);

        $reports = Schema::hasTable('tbl_place_reports') ? DB::table('tbl_place_reports')->where('status', 'open')->count() : 0;

        return ['days' => $days, 'trips' => ['made' => (clone $made)->count(), 'bySource' => $bySource, 'active' => $active, 'upcoming' => $upcoming,
            'users' => (clone $made)->distinct('userId')->count('userId')],
            'funnel' => ['fromTrip' => (int) ($fromTrip->n ?? 0), 'live' => (int) ($fromTrip->live ?? 0), 'score' => $fromTrip && $fromTrip->score !== null ? round((float) $fromTrip->score * 100) : null,
                'paid' => (float) ($fromTrip->paid ?? 0), 'all' => $allBookings],
            'ai' => $ai, 'watch' => $watch, 'gaps' => array_slice($gaps, 0, 8), 'reports' => $reports];
    }
}
