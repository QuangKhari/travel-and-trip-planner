<?php

namespace App\Services\Planner;

use App\Services\Planner\Health\TripHealth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trip Planner quản lý trọn vòng đời chuyến (Tầng 3):
 *  - TRƯỚC chuyến: điểm "mức sẵn sàng" 0–100 + danh sách việc cần làm có HẠN CHÓT (tính lùi từ ngày đi): ngày đi, chỗ ở từng đêm,
 *    vé đi / về, lỗi cứng của lịch, giờ mở cửa chưa biết, ngân sách, ngày còn trống. Mỗi việc có trọng số; điểm = tổng trọng số việc đã xong.
 *  - SAU chuyến: so dự toán với chi tiêu thực tế (Chi tiêu nhóm), số nơi đã ghé / bỏ qua / chưa đánh dấu, nơi đã ghé chưa đánh giá
 *    (đánh giá quay lại cải thiện dữ liệu địa điểm).
 * Chỉ ĐỌC: không đổi lịch. Dùng lại BookingPlan, TripHealth, TripExpenses, OpeningHours có sẵn.
 */
final class TripLifecycle
{
    public function __construct(private TripPlanner $planner, private TripHealth $health) {}

    /** @return array{phase:string, start:?string, end:?string, readiness:?array, recap:?array} */
    public function forTrip(object $trip, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $start = $trip->startDate ? Carbon::parse($trip->startDate)->startOfDay() : null;
        $end = $start ? $start->copy()->addDays(max(1, (int) $trip->days) - 1) : null;
        $phase = !$start || $today->lt($start) ? 'before' : ($today->gt($end) || ($trip->status ?? '') === 'done' ? 'after' : 'during');

        return ['phase' => $phase, 'start' => $start?->toDateString(), 'end' => $end?->toDateString(),
            'readiness' => $phase === 'after' ? null : $this->readiness($trip, $today, $start),
            'recap' => $phase === 'after' ? $this->recap($trip) : null];
    }

    /** @return array{score:int, level:string, done:int, total:int, tasks:list<array{key:string,ok:bool,weight:int,title:string,detail:?string,due:?string,overdue:bool,action:?string}>} */
    public function readiness(object $trip, Carbon $today, ?Carbon $start): array
    {
        $payload = $this->planner->payload($trip);
        $tasks = [];
        $due = fn(int $daysBefore) => $start ? $start->copy()->subDays($daysBefore)->toDateString() : null;
        $add = function (string $key, bool $ok, int $w, string $title, ?string $detail = null, ?string $dueDate = null, ?string $action = null) use (&$tasks, $today) {
            $tasks[] = ['key' => $key, 'ok' => $ok, 'weight' => $w, 'title' => $title, 'detail' => $detail, 'due' => $ok ? null : $dueDate,
                'overdue' => !$ok && $dueDate !== null && $today->gt(Carbon::parse($dueDate)), 'action' => $action];
        };

        $add('dates', (bool) $start, 15, $start ? 'Đã chọn ngày đi' : 'Chọn ngày đi', $start ? null : 'Có ngày đi mới tính được giờ mở cửa, thời tiết và hạn đặt chỗ.', null, 'info');

        // chỗ ở / vé (BookingPlan: chỉ ra đêm chưa có chỗ ở, chưa có vé đi / về; người dùng có thể đánh dấu "không cần")
        $needs = BookingPlan::ready() ? (app(BookingPlan::class)->forTrip($trip)['needs'] ?? []) : [];
        $stay = array_values(array_filter($needs, fn($n) => $n['key'] === 'stay'));
        $move = array_values(array_filter($needs, fn($n) => in_array($n['key'], ['go', 'back'], true)));
        if ($start && (int) $trip->days >= 2) {
            $add('stay', !$stay, 20, $stay ? 'Đặt chỗ ở: ' . mb_strtolower($stay[0]['title']) : 'Đã có chỗ ở cho các đêm', $stay ? (count($stay) > 1 ? 'Còn ' . count($stay) . ' khoảng đêm chưa có chỗ ở.' : null) : null, $due(14), 'bookings');
        }
        if ($start) {
            $add('transport', !$move, 15, $move ? implode(', ', array_map(fn($n) => $n['title'], $move)) : 'Đã có vé đi / về (hoặc tự đi)', null, $due(21), 'bookings');
        }

        // lỗi cứng của lịch
        $h = $this->health->forTrip($trip);
        $hard = array_values(array_filter($h['checks'] ?? [], fn($c) => ($c['severity'] ?? '') === 'hard' && in_array($c['level'] ?? '', ['bad', 'warn'], true)));
        $add('health', !$hard, 20, $hard ? 'Sửa ' . count($hard) . ' chỗ vướng trong lịch' : 'Lịch không có chỗ vướng (trùng giờ, đóng cửa, không kịp)', $hard ? $hard[0]['text'] : null, $due(1), 'health');

        // giờ mở cửa: mục địa điểm trong các ngày mà Travela chưa biết giờ
        $places = [];
        foreach ($payload['days'] as $d) {
            foreach ($d['items'] as $it) {
                if (in_array($it['type'] ?? '', ['place', 'experience'], true)) {
                    $places[] = $it;
                }
            }
        }
        $unknown = array_values(array_filter($places, fn($it) => empty($it['hours'])));
        if ($places) {
            $add(
                'hours',
                count($unknown) <= intdiv(count($places), 5),
                10,
                $unknown ? 'Kiểm tra giờ mở cửa của ' . count($unknown) . '/' . count($places) . ' nơi' : 'Đã biết giờ mở cửa các nơi trong lịch',
                $unknown ? implode(', ', array_slice(array_map(fn($x) => (string) $x['name'], $unknown), 0, 4)) . (count($unknown) > 4 ? '…' : '') : null,
                $due(3),
                'plan',
            );
        }

        // ngân sách
        $cost = (float) ($payload['totals']['cost'] ?? 0);
        $budget = $payload['totals']['budget'] ?? null;
        if ($budget === null) {
            $add('budget', false, 5, 'Đặt ngân sách cho chuyến', 'Có ngân sách thì Travela cảnh báo khi dự toán vượt mức.', null, 'info');
        } else {
            $add(
                'budget',
                $cost <= (float) $budget,
                10,
                $cost <= (float) $budget ? 'Dự toán trong ngân sách' : 'Dự toán vượt ngân sách ' . number_format($cost - (float) $budget, 0, ',', '.') . 'đ',
                null,
                $due(7),
                'cost',
            );
        }

        // ngày trống
        $empty = array_values(array_map(fn($d) => (int) $d['day'], array_filter($payload['days'], fn($d) => !$d['items'])));
        $add('days', !$empty, 10, $empty ? 'Còn ' . count($empty) . ' ngày chưa có hoạt động (ngày ' . implode(', ', $empty) . ')' : 'Ngày nào cũng có kế hoạch', null, $due(3), 'plan');

        $total = array_sum(array_column($tasks, 'weight'));
        $done = array_sum(array_map(fn($t) => $t['ok'] ? $t['weight'] : 0, $tasks));
        $score = $total ? (int) round(100 * $done / $total) : 0;
        // việc chưa xong lên trước, theo hạn chót gần nhất
        usort($tasks, fn($a, $b) => [$a['ok'], $a['due'] ?? '9999'] <=> [$b['ok'], $b['due'] ?? '9999']);

        return ['score' => $score, 'level' => $score >= 85 ? 'ready' : ($score >= 60 ? 'almost' : 'todo'),
            'done' => count(array_filter($tasks, fn($t) => $t['ok'])), 'total' => count($tasks), 'tasks' => $tasks];
    }

    /** @return array{planned:int, actual:int, diff:int, diffPct:?float, byCategory:array, visited:int, skipped:int, unmarked:int, toReview:list<array{type:string,refId:int,name:string}>} */
    public function recap(object $trip): array
    {
        $payload = $this->planner->payload($trip);
        $planned = (int) round((float) ($payload['totals']['cost'] ?? 0));
        $exp = TripExpenses::ready() ? app(TripExpenses::class)->summary($trip) : ['total' => 0, 'byCategory' => []];
        $actual = (int) $exp['total'];
        $visited = $skipped = $unmarked = 0;
        $visitedRefs = [];
        foreach ($payload['days'] as $d) {
            foreach ($d['items'] as $it) {
                if (!empty($it['visitedAt'])) {
                    $visited++;
                    if (in_array($it['type'] ?? '', ['place', 'experience', 'food'], true) && !empty($it['refId'])) {
                        $visitedRefs[$it['type'] . ':' . $it['refId']] = ['type' => $it['type'], 'refId' => (int) $it['refId'], 'name' => (string) $it['name']];
                    }
                } elseif (!empty($it['skippedAt'])) {
                    $skipped++;
                } else {
                    $unmarked++;
                }
            }
        }
        if ($visitedRefs && Schema::hasTable('tbl_place_reviews')) {
            foreach (DB::table('tbl_place_reviews')->where('tripId', $trip->tripId)->get(['refType', 'refId']) as $r) {
                unset($visitedRefs[$r->refType . ':' . $r->refId]);
            }
        }

        return ['planned' => $planned, 'actual' => $actual, 'diff' => $actual - $planned, 'diffPct' => $planned > 0 ? round(100 * ($actual - $planned) / $planned, 1) : null,
            'byCategory' => $exp['byCategory'] ?? [], 'visited' => $visited, 'skipped' => $skipped, 'unmarked' => $unmarked,
            'toReview' => array_slice(array_values($visitedRefs), 0, 6)];
    }
}
