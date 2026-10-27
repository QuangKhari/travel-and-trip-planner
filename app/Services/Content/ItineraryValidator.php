<?php

namespace App\Services\Content;

/**
 * Kiểm tra bộ điểm dừng của một lịch trình (kế hoạch v6, mục 6).
 *  - errors: chặn lưu (dữ liệu sai cấu trúc).
 *  - warnings: vẫn lưu nhưng báo biên tập viên (lịch quá dày, thứ tự không theo khung giờ, ngày trống...).
 * Thuần PHP, không đụng DB: dễ kiểm thử; admin và test dùng chung.
 */
final class ItineraryValidator
{
    /**
     * @param list<array{dayNo:int,refType:?string,refId:?int,label:?string,slot:?string,durationMin:?int}> $items theo thứ tự lưu
     * @return array{errors:list<string>,warnings:list<string>,days:array<int,array{stops:int,minutes:int,estimated:bool}>}
     */
    public static function check(array $items, int $days, array $refTypes): array
    {
        $slots = (array) config('itinerary.slots');
        $minM = (int) config('itinerary.min_minutes', 5);
        $maxM = (int) config('itinerary.max_minutes', 1440);
        $budget = (int) config('planner.day_budget_min', 480);
        $maxStops = (int) config('itinerary.max_stops_per_day', 6);
        $defaults = (array) config('itinerary.default_minutes');

        $errors = [];
        $warnings = [];
        $per = [];
        for ($d = 1; $d <= $days; $d++) {
            $per[$d] = ['items' => 0, 'stops' => 0, 'minutes' => 0, 'estimated' => false, 'lastRank' => 0, 'rankBroken' => false, 'noSlot' => 0, 'transportLast' => false];
        }

        foreach (array_values($items) as $i => $it) {
            $row = 'Dòng ' . ($i + 1);
            $day = (int) ($it['dayNo'] ?? 0);
            if ($day < 1 || $day > $days) {
                $errors[] = $row . ": ngày {$day} nằm ngoài khoảng 1 đến {$days}.";
                continue;
            }
            $type = $it['refType'] ?? null;
            $slot = $it['slot'] ?? null;
            $min = $it['durationMin'] ?? null;

            if ($type !== null && $type !== '' && $type !== 'transport' && !in_array($type, $refTypes, true)) {
                $errors[] = $row . ': loại mục "' . $type . '" không hợp lệ.';
            }
            if ($type === 'transport' && !empty($it['refId'])) {
                $errors[] = $row . ': mục "Di chuyển" không gắn với bản ghi có sẵn (bỏ chọn mục, chỉ ghi tên và ghi chú).';
            }
            if ($type && $type !== 'transport' && empty($it['refId'])) {
                $errors[] = $row . ': chọn một mục trong danh sách gợi ý hoặc đổi loại thành "Chữ tự do".';
            }
            if ($slot !== null && $slot !== '' && !isset($slots[$slot])) {
                $errors[] = $row . ': khung giờ "' . $slot . '" không hợp lệ (sáng, trưa, chiều, tối).';
            }
            if ($min !== null && ($min < $minM || $min > $maxM)) {
                $errors[] = $row . ": thời lượng phải từ {$minM} đến {$maxM} phút.";
            }
            if (trim((string) ($it['label'] ?? '')) === '' && empty($it['refId'])) {
                $errors[] = $row . ': nhập tên điểm dừng hoặc chọn một mục có sẵn.';
            }

            $isTransport = $type === 'transport';
            $per[$day]['items']++;
            if (!$isTransport) {
                $per[$day]['stops']++;
            }
            if ($min !== null) {
                $per[$day]['minutes'] += (int) $min;
            } else {
                $per[$day]['minutes'] += (int) ($defaults[$type ?: 'free'] ?? $defaults['free'] ?? 60);
                $per[$day]['estimated'] = $per[$day]['estimated'] || !$isTransport;
            }
            $per[$day]['transportLast'] = $isTransport;

            if ($slot === null || $slot === '') {
                $per[$day]['noSlot']++;
            } else {
                $rank = (int) ($slots[$slot][1] ?? 0);
                if ($rank < $per[$day]['lastRank']) {
                    $per[$day]['rankBroken'] = true;
                }
                $per[$day]['lastRank'] = max($per[$day]['lastRank'], $rank);
            }
        }

        foreach ($per as $d => $p) {
            if ($p['stops'] === 0) {
                $warnings[] = "Ngày {$d} chưa có điểm dừng.";
                continue;
            }
            if ($p['stops'] > $maxStops) {
                $warnings[] = "Ngày {$d} có {$p['stops']} điểm dừng, nhiều hơn mức gợi ý {$maxStops}: lịch có thể quá dày.";
            }
            if ($p['minutes'] > $budget) {
                $warnings[] = "Ngày {$d} dự kiến khoảng {$p['minutes']} phút" . ($p['estimated'] ? ' (có mục dùng thời lượng ước lượng)' : '') . ", vượt quỹ {$budget} phút mỗi ngày.";
            }
            if ($p['rankBroken']) {
                $warnings[] = "Ngày {$d}: thứ tự các dòng chưa theo khung giờ (sáng → trưa → chiều → tối). Dùng nút \"Sắp theo khung giờ\".";
            }
            if ($p['noSlot'] > 0 && $p['noSlot'] < $p['items']) {
                $warnings[] = "Ngày {$d}: {$p['noSlot']} mục chưa chọn khung giờ trong khi các mục khác đã chọn.";
            }
            if ($p['transportLast']) {
                $warnings[] = "Ngày {$d} kết thúc bằng mục di chuyển; kiểm tra xem còn thiếu điểm đến cuối ngày không.";
            }
        }
        $summary = array_map(fn($p) => ['stops' => $p['stops'], 'minutes' => $p['minutes'], 'estimated' => $p['estimated']], $per);

        return ['errors' => $errors, 'warnings' => $warnings, 'days' => $summary];
    }

    public static function slotLabel(?string $slot): ?string
    {
        return $slot !== null && $slot !== '' ? (config('itinerary.slots')[$slot][0] ?? null) : null;
    }

    public static function slotRank(?string $slot): int
    {
        return $slot !== null && $slot !== '' ? (int) (config('itinerary.slots')[$slot][1] ?? 99) : 99;
    }
}
