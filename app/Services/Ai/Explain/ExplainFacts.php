<?php

namespace App\Services\Ai\Explain;

/**
 * Dựng "bảng dữ kiện" của chuyến cho F2 từ read model (TripPlanner::payload) và chẩn đoán (TripHealth::forTrip).
 * Hàm thuần: nhận mảng, trả mảng, không đọc CSDL.
 *
 * KHÓA MỤC TẠM: i1, i2, ... theo thứ tự (ngày, vị trí), "Đã lưu" xếp cuối. Cùng một rev luôn cho cùng bảng khóa
 * (tài liệu v2.2, mục 5.3), nên F3 (Copilot) dùng lại được đúng quy ước này.
 */
final class ExplainFacts
{
    private const TYPE = ['place' => 'địa điểm', 'food' => 'món ăn', 'experience' => 'trải nghiệm', 'event' => 'lễ hội', 'tour' => 'tour',
        'destination' => 'điểm đến', 'note' => 'mục tự thêm', 'transport' => 'di chuyển'];
    private const DOW = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ nhật'];

    /**
     * @param array $payload TripPlanner::payload()
     * @param array $health  TripHealth::forTrip()
     * @return array{dest:string,days:array<int,array>,keys:array<string,array>,issues:list<array>,month:?string,notes:?string,
     *     group:int,table:string,guard:NumberGuard}
     */
    public static function build(array $payload, array $health, ?string $destName, ?string $monthText, ?string $notes): array
    {
        $trip = $payload['trip'];
        $rows = [];
        foreach ($health['days'] ?? [] as $hd) {
            $rows[(int) $hd['day']] = $hd;
        }
        $keys = [];
        $days = [];
        $n = 0;
        foreach ($payload['days'] as $d) {
            $dn = (int) $d['day'];
            $h = $rows[$dn] ?? [];
            $dk = [];
            foreach (array_values($d['items']) as $i => $it) {
                $k = 'i' . (++$n);
                $r = $h['rows'][$i] ?? null;
                $keys[$k] = self::item($it, $dn, $r);
                $dk[] = $k;
            }
            $days[$dn] = [
                'day' => $dn,
                'date' => $d['date'] ?? null,
                'dow' => isset($d['dow']) && $d['dow'] ? self::DOW[(int) $d['dow']] ?? null : null,
                'keys' => $dk,
                'loadPct' => $h['loadPct'] ?? null,
                'loadLabel' => $h['loadLabel'] ?? null,
                'travelKm' => $h['travelKm'] ?? null,
                'travelMin' => $h['travelMin'] ?? null,
                'start' => $h['start'] ?? null,
                'end' => $h['end'] ?? null,
            ];
        }
        foreach ($payload['unassigned'] as $it) {
            $keys['i' . (++$n)] = self::item($it, null, null);
        }

        $issues = [];
        foreach ($health['checks'] ?? [] as $c) {
            if (in_array($c['level'] ?? '', ['bad', 'warn'], true)) {
                $issues[] = ['code' => (string) $c['code'], 'day' => $c['day'] ?? null, 'text' => (string) $c['text']];
            }
        }
        $issues = array_slice($issues, 0, 8);

        $dest = $destName ?: (string) ($trip['title'] ?? 'chuyến đi');
        $group = max(1, (int) ($trip['groupSize'] ?? 1));
        $table = self::table($dest, $trip, $days, $keys, $issues, $monthText, $notes);

        // NumberGuard: chỉ số có trong bảng dữ kiện + thứ tự ngày, số người, số ngày
        $guard = new NumberGuard([$table], array_merge(range(1, max(1, count($days))), [$group, count($days)]));
        foreach ($keys as $x) {
            if ($x['dur']) {
                $guard->allowMinutes((int) $x['dur']);
            }
        }

        return ['dest' => $dest, 'days' => $days, 'keys' => $keys, 'issues' => $issues, 'month' => $monthText, 'notes' => $notes,
            'group' => $group, 'table' => $table, 'guard' => $guard];
    }

    private static function item(array $it, ?int $day, ?array $r): array
    {
        return [
            'name' => self::clean((string) ($it['name'] ?? '')),
            'type' => self::TYPE[$it['type'] ?? ''] ?? 'mục',
            'sub' => self::clean((string) ($it['sub'] ?? '')),
            'day' => $day,
            'start' => $r['start'] ?? null,
            'dur' => $r['durationMin'] ?? ($it['durationMin'] ?? null),
            'fixed' => (bool) ($r['fixed'] ?? false),
            'reasons' => array_slice(array_map(fn($x) => self::clean((string) $x), (array) ($it['reasons'] ?? [])), 0, 4),
            'cost' => isset($it['cost']) && $it['cost'] !== null ? (float) $it['cost'] : null,
        ];
    }

    /** Tên địa điểm (nhất là nguồn OSM) là dữ liệu ngoài: bỏ ký tự điều khiển, dấu ngoặc nhọn, cắt độ dài (P7). */
    public static function clean(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F<>{}`]+/u', ' ', strip_tags($s)) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, 90);
    }

    private static function table(string $dest, array $trip, array $days, array $keys, array $issues, ?string $month, ?string $notes): string
    {
        $L = [];
        $L[] = 'Điểm đến: ' . $dest . ' | Số ngày: ' . count($days) . ' | Số người: ' . max(1, (int) ($trip['groupSize'] ?? 1))
            . ($trip['budget'] !== null ? ' | Ngân sách: ' . number_format((float) $trip['budget'], 0, ',', '.') . 'đ' : '');
        foreach ($days as $d) {
            $L[] = '';
            $L[] = '# Ngày ' . $d['day'] . ($d['dow'] ? ' (' . $d['dow'] . ($d['date'] ? ' ' . date('d/m', strtotime($d['date'])) : '') . ')' : '')
                . ($d['loadPct'] !== null ? ' | độ dày ' . $d['loadPct'] . '% (' . $d['loadLabel'] . ')' : '')
                . ($d['travelKm'] !== null ? ' | đi lại ~' . str_replace('.', ',', (string) $d['travelKm']) . ' km, ' . $d['travelMin'] . ' phút' : '')
                . ($d['start'] ? ' | ' . $d['start'] . '–' . $d['end'] : '');
            foreach ($d['keys'] as $k) {
                $x = $keys[$k];
                $L[] = $k . ' | ' . $x['name'] . ' | ' . $x['type'] . ($x['sub'] ? ' (' . $x['sub'] . ')' : '')
                    . ' | ' . ($x['start'] ?? '?') . ($x['fixed'] ? ' (giờ cố định)' : '') . ' | ' . ($x['dur'] ? $x['dur'] . ' phút' : '?')
                    . ' | lý do: ' . ($x['reasons'] ? implode('; ', $x['reasons']) : 'người dùng tự thêm');
            }
        }
        if ($issues) {
            $L[] = '';
            $L[] = '# Sức khỏe chuyến đi (mã | ngày | nội dung)';
            foreach ($issues as $c) {
                $L[] = $c['code'] . ' | ' . ($c['day'] ?? '-') . ' | ' . $c['text'];
            }
        }
        if ($month) {
            $L[] = '';
            $L[] = '# Mùa / thời tiết theo tháng (khí hậu trung bình, không phải dự báo)';
            $L[] = $month;
        }
        if ($notes) {
            $L[] = '';
            $L[] = '# Ghi chú của người dùng';
            $L[] = $notes;
        }

        return implode("\n", $L);
    }
}
