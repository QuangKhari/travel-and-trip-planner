<?php

namespace App\Services\Ai\Explain;

/**
 * Ghép lời kể F2 từ JSON của LLM, qua hai lớp bảo vệ (tài liệu v2.2, mục 5.2):
 *   KeyGuard   : mọi chỗ giữ {{iN}} / key phải thuộc chuyến (và đúng ngày); tên riêng viết thẳng (không qua chỗ giữ) bị loại
 *   NumberGuard: mọi con số phải có trong dữ kiện
 * Đoạn nào không qua thì thay bằng CÂU MẪU dựng từ dữ kiện (người dùng không bao giờ thấy ô trống).
 * Kết quả ở dạng "đoạn" (segments) để trình duyệt vẽ bằng textContent: tên riêng do máy chủ chèn, không phải chữ của LLM.
 */
final class ExplainComposer
{
    /**
     * @param array $raw JSON của LLM: {headline, days:[{day, summary, items:[{key, why}]}], tips:[{text, basedOn:[]}]}
     * @param array $facts ExplainFacts::build()
     * @return array{headline:list<array>,days:list<array>,tips:list<array>,guards:array{passed:int,replaced:int,flags:list<string>}}
     */
    public function compose(array $raw, array $facts): array
    {
        $stat = ['passed' => 0, 'replaced' => 0, 'flags' => []];
        $check = function (mixed $text, ?int $day) use ($facts, &$stat): ?array {
            $why = $this->problem(is_string($text) ? $text : '', $facts, $day);
            if ($why === null) {
                $stat['passed']++;

                return $this->segments((string) $text, $facts);
            }
            $stat['replaced']++;
            $stat['flags'][] = $why;

            return null;
        };

        $headline = $check(mb_substr((string) ($raw['headline'] ?? ''), 0, 120), null) ?? $this->segments($this->templateHeadline($facts), $facts);

        $rawDays = [];
        foreach ((array) ($raw['days'] ?? []) as $d) {
            if (is_array($d) && isset($d['day'])) {
                $rawDays[(int) $d['day']] = $d;
            }
        }
        $days = [];
        foreach ($facts['days'] as $dn => $fd) {
            if (!$fd['keys']) {
                continue;
            }
            $rd = $rawDays[$dn] ?? [];
            $summary = $check(mb_substr((string) ($rd['summary'] ?? ''), 0, 500), $dn) ?? $this->segments($this->templateDay($fd, $facts), $facts);
            $whyBy = [];
            foreach ((array) ($rd['items'] ?? []) as $x) {
                $k = is_array($x) ? ($x['key'] ?? null) : null;
                if (!is_string($k) || !in_array($k, $fd['keys'], true)) {
                    $stat['flags'][] = 'key_not_in_day';
                    continue;
                }
                $seg = $check(mb_substr((string) ($x['why'] ?? ''), 0, 240), $dn);
                if ($seg !== null) {
                    $whyBy[$k] = $seg;
                }
            }
            $items = [];
            foreach ($fd['keys'] as $k) {
                $why = $whyBy[$k] ?? ($facts['keys'][$k]['reasons'] ? [['t' => 'Được chọn vì: ' . implode('; ', $facts['keys'][$k]['reasons']) . '.']] : null);
                if ($why !== null) {
                    $items[] = ['key' => $k, 'name' => $facts['keys'][$k]['name'], 'why' => $why, 'ai' => isset($whyBy[$k])];
                }
            }
            $days[] = ['day' => $dn, 'label' => 'Ngày ' . $dn . ($fd['dow'] ? ' · ' . $fd['dow'] : ''), 'summary' => $summary, 'items' => $items];
        }

        $codes = array_column($facts['issues'], 'code');
        $tips = [];
        foreach (array_slice((array) ($raw['tips'] ?? []), 0, 5) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $basis = array_values(array_filter((array) ($t['basedOn'] ?? []), fn($b) => is_string($b) && $this->validBasis($b, $codes, $facts)));
            if (!$basis) {
                $stat['flags'][] = 'tip_without_basis';
                continue;   // lưu ý không có căn cứ thì bỏ hẳn, không thay câu mẫu
            }
            $seg = $check(mb_substr((string) ($t['text'] ?? ''), 0, 240), null);
            if ($seg !== null) {
                $tips[] = ['text' => $seg, 'basedOn' => array_slice($basis, 0, 2)];
            }
        }
        if (!$tips) {
            $tips = $this->templateTips($facts);
        }
        $stat['flags'] = array_values(array_unique($stat['flags']));

        return ['headline' => $headline, 'days' => $days, 'tips' => array_slice($tips, 0, 4), 'guards' => $stat];
    }

    /** Toàn bộ bằng câu mẫu (AI tắt / lỗi / hết lượt). */
    public function template(array $facts): array
    {
        $days = [];
        foreach ($facts['days'] as $dn => $fd) {
            if (!$fd['keys']) {
                continue;
            }
            $items = [];
            foreach ($fd['keys'] as $k) {
                if ($facts['keys'][$k]['reasons']) {
                    $items[] = ['key' => $k, 'name' => $facts['keys'][$k]['name'], 'why' => [['t' => 'Được chọn vì: ' . implode('; ', $facts['keys'][$k]['reasons']) . '.']], 'ai' => false];
                }
            }
            $days[] = ['day' => $dn, 'label' => 'Ngày ' . $dn . ($fd['dow'] ? ' · ' . $fd['dow'] : ''), 'summary' => $this->segments($this->templateDay($fd, $facts), $facts), 'items' => $items];
        }

        return ['headline' => $this->segments($this->templateHeadline($facts), $facts), 'days' => $days, 'tips' => $this->templateTips($facts),
            'guards' => ['passed' => 0, 'replaced' => 0, 'flags' => []]];
    }

    /** null = qua; còn lại là mã lỗi. */
    public function problem(string $text, array $facts, ?int $day): ?string
    {
        if (trim($text) === '') {
            return 'empty';
        }
        if (preg_match('/<[a-z\/!]|https?:|www\./iu', $text)) {
            return 'markup';
        }
        preg_match_all('/\{\{\s*([^}]*)\s*\}\}/u', $text, $m);
        foreach ($m[1] as $k) {
            $k = trim($k);
            if ($k === 'dest') {
                continue;
            }
            if (!isset($facts['keys'][$k])) {
                return 'unknown_key';
            }
        }
        // còn dấu ngoặc nhọn lẻ = chỗ giữ hỏng
        $stripped = preg_replace('/\{\{\s*[^}]*\s*\}\}/u', ' ', $text) ?? '';
        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            return 'broken_placeholder';
        }
        // tên riêng của mục viết thẳng, không qua chỗ giữ (máy chủ mới là nơi chèn tên)
        $low = mb_strtolower($stripped);
        foreach ($facts['keys'] as $x) {
            if (mb_strlen($x['name']) >= 4 && str_contains($low, mb_strtolower($x['name']))) {
                return 'raw_name';
            }
        }
        if (!$facts['guard']->passes($stripped)) {
            return 'number';
        }

        return null;
    }

    private function validBasis(string $b, array $codes, array $facts): bool
    {
        if (str_starts_with($b, 'health:')) {
            return in_array(substr($b, 7), $codes, true);
        }

        return match ($b) {
            'month' => $facts['month'] !== null,
            'note' => $facts['notes'] !== null,
            'reasons', 'schedule' => true,
            default => false,
        };
    }

    /** "Bắt đầu ở {{i1}} rồi tới {{dest}}" -> [{t},{k,name,day},{t}] */
    public function segments(string $text, array $facts): array
    {
        $out = [];
        $parts = preg_split('/(\{\{\s*[^}]*\s*\}\})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $p) {
            if (preg_match('/^\{\{\s*([^}]*)\s*\}\}$/u', $p, $m)) {
                $k = trim($m[1]);
                if ($k === 'dest') {
                    $out[] = ['t' => $facts['dest']];
                } elseif (isset($facts['keys'][$k])) {
                    $out[] = ['k' => $k, 'name' => $facts['keys'][$k]['name'], 'day' => $facts['keys'][$k]['day']];
                }
                continue;
            }
            $out[] = ['t' => strip_tags($p)];
        }

        return $out;
    }

    private function templateHeadline(array $facts): string
    {
        return '{{dest}} · ' . count($facts['days']) . ' ngày';
    }

    private function templateDay(array $fd, array $facts): string
    {
        $names = array_map(fn($k) => '{{' . $k . '}}', array_slice($fd['keys'], 0, 3));
        $more = count($fd['keys']) - count($names);

        return 'Ngày có ' . count($fd['keys']) . ' mục: ' . implode(', ', $names) . ($more > 0 ? ' và ' . $more . ' mục khác' : '') . '.'
            . ($fd['loadPct'] !== null ? ' Độ dày ' . $fd['loadPct'] . '% (' . mb_strtolower((string) $fd['loadLabel']) . ').' : '');
    }

    private function templateTips(array $facts): array
    {
        $out = [];
        foreach (array_slice($facts['issues'], 0, 3) as $c) {
            $out[] = ['text' => [['t' => $c['text']]], 'basedOn' => ['health:' . $c['code']]];
        }

        return $out;
    }
}
