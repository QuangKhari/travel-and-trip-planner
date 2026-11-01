<?php

namespace App\Services\Ai\Ask;

use App\Services\Ai\Explain\ExplainFacts;
use App\Services\Planner\OpeningHours;

/**
 * F5 "Hỏi về chuyến": "dữ kiện chuyến" đưa vào AskService như nguồn DU_KIEN (tài liệu v2.2 mục 5.5).
 * Mỗi ngày một nguồn (giờ, giờ mở cửa ĐÚNG thứ của ngày đó, trong nhà / ngoài trời), cộng sức khỏe chuyến, mùa theo tháng,
 * ghi chú và "Đã lưu". Khóa nguồn "trip:day:N" để giao diện biến trích dẫn thành nút "xem Ngày N trong lịch". Hàm thuần.
 */
final class TripFacts
{
    private const DOW = [1 => 'Thứ Hai', 2 => 'Thứ Ba', 3 => 'Thứ Tư', 4 => 'Thứ Năm', 5 => 'Thứ Sáu', 6 => 'Thứ Bảy', 7 => 'Chủ nhật'];
    private const TYPE = ['place' => 'địa điểm', 'food' => 'món ăn', 'experience' => 'trải nghiệm', 'event' => 'lễ hội', 'tour' => 'tour',
        'destination' => 'điểm đến', 'note' => 'mục tự thêm', 'transport' => 'di chuyển'];

    /**
     * @param array $payload TripPlanner::payload()
     * @param array $health  TripHealth::forTrip()
     * @return list<array{key:string,type:string,title:string,text:string,url:string,updatedOn:null,day:?int}>
     */
    public static function build(array $payload, array $health, string $url, ?string $dest, ?string $monthText, ?string $notes): array
    {
        $rows = [];
        foreach ($health['days'] ?? [] as $hd) {
            $rows[(int) $hd['day']] = $hd;
        }
        $f = fn(string $key, string $title, string $text, ?int $day = null) => ['key' => $key, 'type' => 'fact', 'title' => $title, 'text' => $text,
            'url' => $url . ($day ? '#ngay-' . $day : ''), 'updatedOn' => null, 'day' => $day];
        $out = [];
        $where = $dest ? ' ở ' . $dest : '';
        foreach ($payload['days'] as $d) {
            $dn = (int) $d['day'];
            $dow = isset($d['dow']) && $d['dow'] ? (int) $d['dow'] : null;
            $label = 'Ngày ' . $dn . ($dow ? ' (' . self::DOW[$dow] . (!empty($d['date']) ? ' ' . date('d/m/Y', strtotime($d['date'])) : '') . ')' : '');
            $h = $rows[$dn] ?? [];
            $parts = [];
            foreach (array_values($d['items']) as $i => $it) {
                $r = $h['rows'][$i] ?? null;
                $bits = [self::TYPE[$it['type'] ?? ''] ?? 'mục'];
                if (!empty($it['sub'])) {
                    $bits[] = ExplainFacts::clean((string) $it['sub']);
                }
                if (($it['outdoor'] ?? null) === true) {
                    $bits[] = 'ngoài trời';
                } elseif (($it['outdoor'] ?? null) === false) {
                    $bits[] = 'trong nhà';
                }
                $open = OpeningHours::describe($it['hours'] ?? null, $dow);
                if ($open !== null) {
                    $bits[] = 'giờ mở cửa hôm đó: ' . $open;
                }
                $parts[] = ($r ? $r['start'] . '–' . $r['end'] . ' ' : '') . ExplainFacts::clean((string) $it['name']) . ' (' . implode(', ', $bits) . ')';
            }
            $text = $label . $where . ': ' . ($parts ? implode('; ', $parts) . '.' : 'chưa có mục nào.')
                . (isset($h['travelKm']) && $parts ? ' Đi lại khoảng ' . str_replace('.', ',', (string) $h['travelKm']) . ' km.' : '');
            $out[] = $f('trip:day:' . $dn, 'Lịch ' . $label, $text, $dn);
        }
        $issues = [];
        foreach ($health['checks'] ?? [] as $c) {
            if (in_array($c['level'] ?? '', ['bad', 'warn'], true)) {
                $issues[] = (string) $c['text'];
            }
        }
        if ($issues) {
            $out[] = $f('trip:health', 'Sức khỏe chuyến đi', 'Các điểm cần xem trong lịch: ' . implode(' ', array_slice($issues, 0, 6)));
        }
        if ($monthText) {
            $out[] = $f('trip:month', 'Mùa và thời tiết tháng đi', $monthText . ' (khí hậu trung bình, không phải dự báo).');
        }
        if ($notes) {
            $out[] = $f('trip:notes', 'Ghi chú của bạn', $notes);
        }
        if (!empty($payload['unassigned'])) {
            $names = array_map(fn($x) => ExplainFacts::clean((string) $x['name']), array_slice($payload['unassigned'], 0, 10));
            $out[] = $f('trip:saved', 'Đã lưu, chưa xếp ngày', 'Các mục đã lưu nhưng chưa xếp vào ngày nào: ' . implode(', ', $names) . '.');
        }

        return $out;
    }

    /** Ngày trong lịch mà một nguồn trỏ tới (null nếu không phải nguồn theo ngày). */
    public static function dayOf(?string $key): ?int
    {
        return $key && preg_match('/^trip:day:(\d+)$/', $key, $m) ? (int) $m[1] : null;
    }
}
