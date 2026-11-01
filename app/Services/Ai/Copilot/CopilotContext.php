<?php

namespace App\Services\Ai\Copilot;

use App\Services\Ai\Explain\ExplainFacts;

/**
 * Bối cảnh một lượt La bàn Copilot, dựng từ bản ĐÃ LƯU (payload + TripHealth). Hàm thuần.
 *
 * Dùng lại bảng dữ kiện và quy ước khóa của F2 (ExplainFacts: i1..iN theo ngày, vị trí; "Đã lưu" cuối), rồi bổ sung cho mỗi khóa
 * phần Copilot cần: vị trí gốc (index), loại / mã nội dung, khóa (locked), tọa độ. "Danh tính" của mục (type:refId:tên) dùng để
 * ánh xạ lại đề xuất cũ sang rev mới khi bấm "Kiểm tra lại" mà không phải gọi lại LLM.
 */
final class CopilotContext
{
    /**
     * @return array{facts:array,table:string,keys:array<string,array>,dows:array<int,?int>,days:int,quick:list<array{label:string,prompt:string}>}
     */
    public static function build(array $payload, array $health, ?string $dest, ?string $notes): array
    {
        $facts = ExplainFacts::build($payload, $health, $dest, null, $notes);
        $keys = [];
        $dows = [];
        $n = 0;
        foreach ($payload['days'] as $d) {
            $dn = (int) $d['day'];
            $dows[$dn] = isset($d['dow']) && $d['dow'] ? (int) $d['dow'] : null;
            foreach (array_values($d['items']) as $i => $it) {
                $k = 'i' . (++$n);
                $keys[$k] = self::key($it, $dn, $i, $facts['keys'][$k] ?? []);
            }
        }
        foreach ($payload['unassigned'] as $it) {
            $k = 'i' . (++$n);
            $keys[$k] = self::key($it, null, null, $facts['keys'][$k] ?? []);
        }

        // bổ sung cho bảng của F2 những gì Copilot cần biết để không đề xuất sai luật: mục đã khóa, mục trong "Đã lưu"
        $extra = [];
        $locked = array_keys(array_filter($keys, fn($k) => $k['locked'] && $k['day'] !== null));
        if ($locked) {
            $extra[] = '';
            $extra[] = '# Mục đã khóa (không đụng trừ khi người dùng nêu tên): ' . implode(', ', $locked);
        }
        $saved = array_filter($keys, fn($k) => $k['day'] === null);
        if ($saved) {
            $extra[] = '';
            $extra[] = '# Đã lưu, chưa xếp ngày (chỉ để tham khảo, không thao tác được)';
            foreach (array_slice($saved, 0, 12, true) as $k => $x) {
                $extra[] = $k . ' | ' . $x['name'];
            }
        }

        return ['facts' => $facts, 'table' => $facts['table'] . ($extra ? "\n" . implode("\n", $extra) : ''), 'keys' => $keys, 'dows' => $dows,
            'days' => count($payload['days']), 'quick' => self::quick($facts)];
    }

    private static function key(array $it, ?int $day, ?int $index, array $f): array
    {
        $type = (string) ($it['type'] ?? '');

        return [
            'day' => $day, 'index' => $index,
            'name' => $f['name'] ?? ExplainFacts::clean((string) ($it['name'] ?? '')),
            'type' => $type,
            'refId' => isset($it['refId']) && $it['refId'] !== null ? (int) $it['refId'] : null,
            'kind' => $it['kind'] ?? null,
            'fixed' => (bool) ($f['fixed'] ?? false),
            'locked' => !empty($it['locked']),
            'lat' => isset($it['lat']) && $it['lat'] !== null ? (float) $it['lat'] : null,
            'lng' => isset($it['lng']) && $it['lng'] !== null ? (float) $it['lng'] : null,
            'outdoor' => $it['outdoor'] ?? null,
            'destinationId' => isset($it['destinationId']) ? (int) $it['destinationId'] : null,
        ];
    }

    public static function identity(array $k): string
    {
        return $k['type'] . ':' . ($k['refId'] ?? '') . ':' . mb_strtolower($k['name']);
    }

    /**
     * Mục người dùng NÊU ĐÍCH DANH (được phép đụng dù cố định / khóa): chọn bằng @ trên lịch, hoặc tên xuất hiện nguyên văn trong câu.
     * @param list<array{name?:string,day?:?int}> $mentions
     * @return list<string>
     */
    public static function explicit(string $message, array $mentions, array $keys): array
    {
        $out = [];
        $msg = mb_strtolower($message);
        foreach ($keys as $k => $x) {
            $name = mb_strtolower($x['name']);
            if (mb_strlen($name) >= 4 && str_contains($msg, $name)) {
                $out[$k] = true;
            }
            foreach ($mentions as $m) {
                if (mb_strtolower(trim((string) ($m['name'] ?? ''))) === $name && (($m['day'] ?? null) === null || (int) $m['day'] === $x['day'])) {
                    $out[$k] = true;
                }
            }
        }

        return array_keys($out);
    }

    /** Gợi ý bắt đầu lấy từ vấn đề THẬT của chuyến (TripHealth), không phải câu chung chung. */
    private static function quick(array $facts): array
    {
        $out = [];
        $seen = [];
        foreach ($facts['issues'] as $c) {
            $d = $c['day'];
            $code = $c['code'];
            [$label, $prompt] = match (true) {
                $code === 'capacity.overload' || $code === 'capacity.tight' => ["Ngày $d quá dày", "Ngày $d đang quá dày, chuyển bớt một mục sang ngày nhẹ hơn."],
                str_starts_with($code, 'hours.') => ["Giờ mở cửa ngày $d", "Ngày $d có mục không khớp giờ mở cửa, sửa giúp tôi."],
                $code === 'time.overlap' || $code === 'time.travel' => ["Trùng giờ ngày $d", "Ngày $d bị chồng giờ, sắp xếp lại giúp tôi."],
                $code === 'route.long_day' || $code === 'route.long_leg' => ["Đi lại nhiều ngày $d", "Ngày $d đi lại nhiều quá, sắp lại cho gọn."],
                $code === 'context.rain' => ["Mưa ngày $d", "Ngày $d nếu mưa thì có nơi nào trong nhà gần đó?"],
                default => [null, null],
            };
            if ($label && $d !== null && !isset($seen[$label])) {
                $seen[$label] = true;
                $out[] = ['label' => $label, 'prompt' => $prompt];
            }
        }
        $first = null;
        foreach ($facts['keys'] as $k => $x) {
            if ($x['day'] !== null) {
                $first = $x;
                break;
            }
        }
        if ($first && count($out) < 3) {
            $out[] = ['label' => 'Cà phê gần ' . mb_substr($first['name'], 0, 22), 'prompt' => 'Thêm một quán cà phê gần ' . $first['name'] . ' vào ngày ' . $first['day'] . '.'];
        }
        if (count($out) < 4) {
            $out[] = ['label' => 'Ngày nào còn trống?', 'prompt' => 'Ngày nào còn trống, gợi ý thêm một hoạt động hợp với chuyến.'];
        }

        return array_slice($out, 0, 4);
    }
}
