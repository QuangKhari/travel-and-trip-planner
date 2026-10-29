<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;

/**
 * Nối Planner với tour Travela đang bán: với chuyến đang lập, tìm tour cùng điểm đến và cho biết KHỚP ở đâu, LỆCH ở đâu
 * (ngày khởi hành, số ngày, số chỗ, giá so với ngân sách) để người dùng quyết định, thay vì chỉ liệt kê tour.
 *
 * score() và tourDays() là hàm thuần để kiểm thử. Điểm chỉ để xếp hạng.
 */
final class TourFit
{
    /** Số ngày của tour từ chuỗi `time` (vd "3 ngày 2 đêm" => 3). null nếu không đọc được. */
    public static function tourDays(?string $time, ?string $start = null, ?string $end = null): ?int
    {
        if ($time !== null && preg_match('/(\d{1,2})\s*ng[àa]y/iu', $time, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 30) {
            return (int) $m[1];
        }
        if ($start && $end) {
            $d = (int) round((strtotime($end) - strtotime($start)) / 86400) + 1;

            return $d >= 1 && $d <= 30 ? $d : null;
        }

        return null;
    }

    /**
     * @param array{startDate:?string,month:?int,days:int,groupSize:int,budget:?float} $trip
     * @param array{startDate:string,endDate:?string,time:?string,quantity:int,priceAdult:float} $tour
     * @param array<string,mixed> $cfg  config('planner.tour_fit')
     * @return array{score:int,reasons:list<string>,warns:list<string>}
     */
    public static function score(array $trip, array $tour, array $cfg): array
    {
        $reasons = ['Cùng điểm đến với chuyến đi của bạn'];
        $warns = [];
        $score = 40;
        $maxGap = (int) ($cfg['max_day_gap'] ?? 14);

        // Ngày khởi hành
        if (!empty($trip['startDate'])) {
            $gap = (int) round((strtotime($tour['startDate']) - strtotime($trip['startDate'])) / 86400);
            $abs = abs($gap);
            if ($abs === 0) {
                $score += 30;
                $reasons[] = 'Khởi hành đúng ngày bạn chọn';
            } elseif ($abs <= 3) {
                $score += 20;
                $reasons[] = 'Khởi hành ' . ($gap > 0 ? 'sau' : 'trước') . " ngày bạn chọn {$abs} ngày";
            } elseif ($abs <= $maxGap) {
                $score += 8;
                $warns[] = 'Khởi hành lệch ' . $abs . ' ngày so với ngày bạn chọn';
            } else {
                $warns[] = 'Khởi hành lệch ' . $abs . ' ngày, khá xa ngày bạn chọn';
            }
        } elseif (!empty($trip['month'])) {
            if ((int) date('n', strtotime($tour['startDate'])) === (int) $trip['month']) {
                $score += 20;
                $reasons[] = 'Khởi hành trong tháng ' . (int) $trip['month'] . ' như bạn dự định';
            } else {
                $warns[] = 'Khởi hành vào tháng ' . (int) date('n', strtotime($tour['startDate'])) . ', không phải tháng ' . (int) $trip['month'];
            }
        }

        // Số ngày
        $td = self::tourDays($tour['time'] ?? null, $tour['startDate'], $tour['endDate'] ?? null);
        if ($td !== null) {
            $diff = abs($td - (int) $trip['days']);
            if ($diff === 0) {
                $score += 15;
                $reasons[] = "Cùng {$td} ngày";
            } elseif ($diff === 1) {
                $score += 8;
                $warns[] = "Tour {$td} ngày, chuyến của bạn {$trip['days']} ngày";
            } else {
                $warns[] = "Tour {$td} ngày, chuyến của bạn {$trip['days']} ngày";
            }
        }

        // Chỗ
        if ((int) $tour['quantity'] >= (int) $trip['groupSize']) {
            $score += 10;
            $reasons[] = 'Còn đủ chỗ cho ' . (int) $trip['groupSize'] . ' người';
        } else {
            $warns[] = 'Chỉ còn ' . (int) $tour['quantity'] . ' chỗ, chưa đủ cho ' . (int) $trip['groupSize'] . ' người';
        }

        // Giá so với ngân sách (giá người lớn nhân số người, chưa tính trẻ em)
        $total = (float) $tour['priceAdult'] * (int) $trip['groupSize'];
        if (!empty($trip['budget'])) {
            if ($total <= (float) $trip['budget']) {
                $score += 5;
                $reasons[] = 'Giá cho nhóm ' . number_format($total, 0, ',', '.') . 'đ, nằm trong ngân sách';
            } else {
                $warns[] = 'Giá cho nhóm ' . number_format($total, 0, ',', '.') . 'đ, vượt ngân sách ' . number_format($total - (float) $trip['budget'], 0, ',', '.') . 'đ';
            }
        }

        return ['score' => (int) max(0, min(100, $score)), 'reasons' => $reasons, 'warns' => $warns];
    }

    /**
     * Tour còn chỗ, chưa khởi hành, ở các điểm đến của chuyến; xếp theo độ khớp.
     *
     * @param list<int> $destIds
     * @return list<array<string,mixed>>
     */
    public function forTrip(object $trip, array $destIds): array
    {
        $destIds = array_values(array_unique(array_filter(array_map('intval', $destIds))));
        if (!$destIds) {
            return [];
        }
        $cfg = (array) config('planner.tour_fit');
        $tripArr = [
            'startDate' => $trip->startDate ?: null, 'month' => $trip->month ? (int) $trip->month : null,
            'days' => max(1, (int) $trip->days), 'groupSize' => max(1, (int) $trip->groupSize), 'budget' => $trip->budget !== null ? (float) $trip->budget : null,
        ];
        $q = DB::table('tbl_tours as t')->join('tbl_destinations as d', 'd.destinationId', '=', 't.destinationId')
            ->whereIn('t.destinationId', $destIds)->where('t.availability', 1)->where('t.quantity', '>', 0)->whereDate('t.startDate', '>=', date('Y-m-d'));
        if ($trip->startDate) {
            $q->orderByRaw('ABS(DATEDIFF(t.startDate, ?))', [$trip->startDate]);
        } else {
            $q->orderBy('t.startDate');
        }
        $out = [];
        foreach ($q->limit(30)->get(['t.tourId', 't.title', 't.time', 't.startDate', 't.endDate', 't.quantity', 't.priceAdult', 'd.name as destName']) as $t) {
            $s = self::score($tripArr, ['startDate' => $t->startDate, 'endDate' => $t->endDate, 'time' => $t->time, 'quantity' => (int) $t->quantity, 'priceAdult' => (float) $t->priceAdult], $cfg);
            if ($s['score'] < (int) ($cfg['min_score'] ?? 30)) {
                continue;
            }
            $out[] = ['tourId' => (int) $t->tourId, 'title' => $t->title, 'destName' => $t->destName, 'time' => (string) $t->time,
                'start' => date('d/m/Y', strtotime($t->startDate)), 'priceAdult' => (float) $t->priceAdult, 'forGroup' => (float) $t->priceAdult * $tripArr['groupSize'],
                'url' => route('tour-detail', ['id' => $t->tourId]), 'score' => $s['score'], 'reasons' => $s['reasons'], 'warns' => $s['warns']];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, (int) ($cfg['limit'] ?? 3));
    }
}
