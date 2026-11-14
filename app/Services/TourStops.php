<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch trình tour CÓ CẤU TRÚC: mỗi dòng là một điểm dừng (ngày, giờ, loại, tên, ghi chú, vị trí / nơi trong kho).
 * Thay cho đoạn chữ của tbl_timeline khi cần: khách thấy giờ tập trung, giờ trả khách; về sau là nền để ghép tour vào lịch chuyến đi an toàn.
 */
final class TourStops
{
    public const KINDS = ['meet' => 'Tập trung / đón khách', 'visit' => 'Tham quan', 'meal' => 'Ăn uống', 'transfer' => 'Di chuyển', 'hotel' => 'Nhận phòng / nghỉ', 'free' => 'Tự do', 'drop' => 'Trả khách'];
    public const ICONS = ['meet' => 'fa-users', 'visit' => 'fa-camera', 'meal' => 'fa-utensils', 'transfer' => 'fa-bus', 'hotel' => 'fa-bed', 'free' => 'fa-mug-hot', 'drop' => 'fa-flag-checkered'];

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_tour_stops');
    }

    /** @return array<int,list<object>> ngày => các điểm dừng theo thứ tự */
    public function forTour(int $tourId): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('tbl_tour_stops')->where('tourId', $tourId)->orderBy('dayNo')->orderBy('orderNo')->get()->groupBy('dayNo')->map->values()->all();
    }

    /** @param list<array{dayNo:int,start?:?string,end?:?string,kind:string,title:string,note?:?string,refType?:?string,refId?:?int}> $rows */
    public function save(int $tourId, array $rows): int
    {
        $m = fn(?string $t) => $t ? (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2) : null;
        $out = [];
        $order = [];
        foreach ($rows as $r) {
            $lat = $lng = null;
            if (!empty($r['refType']) && !empty($r['refId']) && $r['refType'] === 'place') {
                $p = DB::table('tbl_external_places')->where('placeId', (int) $r['refId'])->first(['latitude', 'longitude']);
                [$lat, $lng] = $p ? [$p->latitude, $p->longitude] : [null, null];
            }
            $d = (int) $r['dayNo'];
            $out[] = ['tourId' => $tourId, 'dayNo' => $d, 'startMin' => $m($r['start'] ?? null), 'endMin' => $m($r['end'] ?? null), 'kind' => $r['kind'],
                'title' => mb_substr(strip_tags($r['title']), 0, 160), 'note' => isset($r['note']) ? mb_substr(strip_tags((string) $r['note']), 0, 400) : null,
                'refType' => $r['refType'] ?? null, 'refId' => $r['refId'] ?? null, 'latitude' => $lat, 'longitude' => $lng, 'orderNo' => $order[$d] = ($order[$d] ?? -1) + 1];
        }
        usort($out, fn($a, $b) => [$a['dayNo'], $a['startMin'] ?? 9999, $a['orderNo']] <=> [$b['dayNo'], $b['startMin'] ?? 9999, $b['orderNo']]);
        $o = [];
        foreach ($out as &$r) {
            $r['orderNo'] = $o[$r['dayNo']] = ($o[$r['dayNo']] ?? -1) + 1;
        }
        unset($r);
        DB::transaction(function () use ($tourId, $out) {
            DB::table('tbl_tour_stops')->where('tourId', $tourId)->delete();
            if ($out) {
                DB::table('tbl_tour_stops')->insert($out);
            }
        });

        return count($out);
    }

    /** Ngày tour theo thời lượng ("4 ngày 3 đêm" / durationDays / startDate-endDate). */
    public static function tourDays(object $tour): ?int
    {
        if (!empty($tour->durationDays)) {
            return (int) $tour->durationDays;
        }

        return \App\Services\Planner\TourFit::tourDays($tour->time ?? null, $tour->startDate ?? null, $tour->endDate ?? null);
    }

    /** Kiểm tra lịch trình: lệch số ngày, ngày trống, chồng giờ, thiếu điểm tập trung / trả khách. @return list<string> */
    public function check(object $tour): array
    {
        $issues = [];
        $days = self::tourDays($tour);
        $text = DB::table('tbl_timeline')->where('tourId', $tour->tourId)->count();
        if ($days && $text && $text !== $days) {
            $issues[] = 'Lịch trình chữ có ' . $text . ' ngày nhưng tour ' . $days . ' ngày.';
        }
        $stops = $this->forTour((int) $tour->tourId);
        if (!$stops) {
            return $issues;
        }
        if ($days) {
            foreach (range(1, $days) as $d) {
                if (empty($stops[$d])) {
                    $issues[] = 'Ngày ' . $d . ' chưa có điểm dừng.';
                }
            }
            foreach (array_keys($stops) as $d) {
                if ($d > $days) {
                    $issues[] = 'Có điểm dừng ở ngày ' . $d . ' nhưng tour chỉ ' . $days . ' ngày.';
                }
            }
        }
        $first = $stops[min(array_keys($stops))][0] ?? null;
        if ($first && $first->kind !== 'meet') {
            $issues[] = 'Ngày đầu chưa có điểm tập trung / đón khách (khách không biết đi đâu, mấy giờ).';
        }
        $lastDay = $stops[max(array_keys($stops))];
        if (!collect($lastDay)->contains(fn($s) => $s->kind === 'drop')) {
            $issues[] = 'Ngày cuối chưa có giờ trả khách (khách khó đặt vé về).';
        }
        foreach ($stops as $d => $list) {
            $prev = null;
            foreach ($list as $s) {
                if ($prev && $prev->endMin !== null && $s->startMin !== null && $s->startMin < $prev->endMin) {
                    $issues[] = 'Ngày ' . $d . ': "' . $s->title . '" bắt đầu trước khi "' . $prev->title . '" kết thúc.';
                }
                $prev = $s;
            }
        }

        return $issues;
    }
}
