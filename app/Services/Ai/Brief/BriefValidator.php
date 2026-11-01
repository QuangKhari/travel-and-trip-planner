<?php

namespace App\Services\Ai\Brief;

/**
 * Kiểm tra kết quả F1 (tài liệu v2.2, mục 5.1). KHÔNG tin LLM kể cả khi đã ràng schema.
 * Hàm thuần: không đọc CSDL, không gọi mạng (mọi dữ liệu cần thiết nằm trong $ctx) nên kiểm thử đơn vị được.
 *
 * Ba trạng thái của một trường: said (người dùng nói rõ) | guess (AI suy ra, phải xem lại) | miss (không có căn cứ).
 * Ba trường bắt buộc (điểm đến, số ngày, thời gian) KHÔNG BAO GIỜ được đoán: LLM khai "inferred" cho chúng thì coi như thiếu.
 *
 * @phpstan-type Ctx array{dests:array<string,array{id:int,name:string,ready:bool}>,maxDays:int,interests:list<string>,
 *     dislikes:list<string>,paces:list<string>,difficulties:list<string>,today:string}
 */
final class BriefValidator
{
    public const REQUIRED = ['destination', 'days', 'when'];
    public const FIELDS = ['destination', 'when', 'days', 'people', 'budget', 'pace', 'maxDifficulty', 'interests', 'dislikes'];
    public const SPAN_FIELDS = ['destination', 'when', 'days', 'people', 'budget', 'pace', 'maxDifficulty', 'interests', 'dislikes', 'extra'];
    public const MAPS_TO = ['pace:relaxed', 'maxDifficulty:easy', 'note', 'unsupported'];

    /** tên trường LLM khai trong inferred/missing -> nhóm trường của giao diện */
    private const GROUP = ['destination' => 'destination', 'month' => 'when', 'startDate' => 'when', 'when' => 'when', 'days' => 'days',
        'adults' => 'people', 'children' => 'people', 'people' => 'people', 'budget' => 'budget', 'pace' => 'pace',
        'maxDifficulty' => 'maxDifficulty', 'interests' => 'interests', 'dislikes' => 'dislikes'];

    /**
     * @param array<string,mixed> $raw  JSON mô hình trả về
     * @param string $text câu người dùng (đã làm sạch) để kiểm tra các đoạn trích (spans)
     * @param Ctx $ctx
     * @return array{refused:bool,fields:array<string,array{state:string,value:mixed,note:?string}>,prefill:array<string,mixed>,
     *     needs:list<array{field:string,optional:bool}>,warnings:list<array{type:string,text:string}>,notes:list<string>,
     *     spans:list<array{text:string,field:string,state:string}>,flags:list<string>,mention:?string,others:list<string>}
     */
    public function validate(array $raw, string $text, array $ctx): array
    {
        $flags = [];
        $inferred = $this->groups((array) ($raw['inferred'] ?? []));
        $missingDecl = $this->groups((array) ($raw['missing'] ?? []));
        $guess = fn(string $g) => isset($inferred[$g]) ? 'guess' : 'said';

        // ---------------- điểm đến: chỉ slug trong danh sách
        $slug = is_array($raw['destination'] ?? null) ? ($raw['destination']['slug'] ?? null) : null;
        $mention = is_array($raw['destination'] ?? null) ? self::str($raw['destination']['mention'] ?? null, 80) : null;
        $dest = null;
        if (is_string($slug) && $slug !== '') {
            if (isset($ctx['dests'][$slug])) {
                $dest = ['slug' => $slug] + $ctx['dests'][$slug];
            } else {
                $flags[] = 'unknown_slug:' . mb_substr($slug, 0, 40);
                $mention ??= $slug;
            }
        }
        if ($dest && isset($inferred['destination'])) {
            $flags[] = 'inferred_required:destination';
            $dest = null;
        }

        // ---------------- thời gian: startDate thắng month (một nguồn)
        $start = self::str($raw['startDate'] ?? null, 10);
        if ($start !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !checkdate((int) substr($start, 5, 2), (int) substr($start, 8, 2), (int) substr($start, 0, 4)))) {
            $flags[] = 'bad_date';
            $start = null;
        }
        $warnings = [];
        if ($start !== null && $start < $ctx['today']) {
            $warnings[] = ['type' => 'past_date', 'text' => 'Ngày đi ' . self::dmy($start) . ' đã qua nên chưa được điền. Hãy chọn ngày khác.'];
            $start = null;
        }
        $month = self::int($raw['month'] ?? null);
        if ($month !== null && ($month < 1 || $month > 12)) {
            $flags[] = 'bad_month';
            $month = null;
        }
        if ($start !== null) {
            $month = (int) substr($start, 5, 2);
        }
        if (($start !== null || $month !== null) && isset($inferred['when'])) {
            $flags[] = 'inferred_required:when';
            $start = $month = null;
        }

        // ---------------- số ngày: vượt trần thì cắt và BÁO
        $days = self::int($raw['days'] ?? null);
        if ($days !== null && $days < 1) {
            $days = null;
        }
        if ($days !== null && $days > $ctx['maxDays']) {
            $warnings[] = ['type' => 'days_capped', 'text' => 'Planner xếp tối đa ' . $ctx['maxDays'] . ' ngày một chuyến, nên số ngày đã được đặt là ' . $ctx['maxDays'] . '.'];
            $days = $ctx['maxDays'];
        }
        if ($days !== null && isset($inferred['days'])) {
            $flags[] = 'inferred_required:days';
            $days = null;
        }

        // ---------------- người đi
        $adults = self::int($raw['adults'] ?? null);
        $children = self::int($raw['children'] ?? null);
        $adults = $adults !== null && $adults >= 1 && $adults <= 50 ? $adults : null;
        $children = $children !== null && $children >= 0 && $children <= 30 ? $children : null;
        if ($adults === null && $children !== null && $children > 0) {
            $flags[] = 'children_without_adults';
        }

        // ---------------- ngân sách: quy đổi theo người làm ở PHP, không để LLM tính nhẩm
        $amount = is_array($raw['budget'] ?? null) ? ($raw['budget']['amountVnd'] ?? null) : null;
        $scope = is_array($raw['budget'] ?? null) ? ($raw['budget']['scope'] ?? null) : null;
        $amount = is_numeric($amount) && (float) $amount > 0 && (float) $amount <= 1e11 ? (float) $amount : null;
        $scope = in_array($scope, ['total', 'perPerson'], true) ? $scope : null;
        $budget = null;
        $budgetNote = null;
        $budgetState = 'miss';
        if ($amount !== null) {
            $people = max(1, ($adults ?? 1) + ($children ?? 0));
            $budget = $scope === 'perPerson' ? $amount * $people : $amount;
            $budgetState = $scope === null ? 'guess' : $guess('budget');
            $budgetNote = $scope === 'perPerson' ? self::vnd($amount) . ' mỗi người × ' . $people . ' người' : ($scope === null ? 'Tạm hiểu là cho cả nhóm' : 'cho cả nhóm');
        }

        // ---------------- enum: giá trị lạ bị bỏ và ghi lại
        $pick = function (mixed $v, array $allowed, string $name) use (&$flags): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (is_string($v) && in_array($v, $allowed, true)) {
                return $v;
            }
            $flags[] = 'bad_enum:' . $name;

            return null;
        };
        $pace = $pick($raw['pace'] ?? null, $ctx['paces'], 'pace');
        $diff = $pick($raw['maxDifficulty'] ?? null, $ctx['difficulties'], 'maxDifficulty');
        $list = function (mixed $v, array $allowed, string $name) use (&$flags): array {
            $v = array_values(array_unique(array_filter((array) $v, 'is_string')));
            $ok = array_values(array_intersect($v, $allowed));
            if (count($ok) < count($v)) {
                $flags[] = 'bad_enum:' . $name;
            }

            return array_slice($ok, 0, 6);
        };
        $interests = $list($raw['interests'] ?? [], $ctx['interests'], 'interests');
        $dislikes = $list($raw['dislikes'] ?? [], $ctx['dislikes'], 'dislikes');

        // ---------------- extras: điều ngoài enum (nguyên tắc P8: không giả vờ đã lọc)
        $notes = [];
        $paceFromExtra = false;
        $diffFromExtra = false;
        foreach (array_slice((array) ($raw['extras'] ?? []), 0, 6) as $x) {
            $t = is_array($x) ? self::str($x['text'] ?? null, 80) : null;
            $to = is_array($x) ? ($x['mapsTo'] ?? null) : null;
            if ($t === null || !in_array($to, self::MAPS_TO, true)) {
                continue;
            }
            if (self::looksLikeInstruction($t)) {
                $flags[] = 'injection_like';   // lớp phòng thủ thứ hai: câu lệnh lọt vào extras không được thành ghi chú của chuyến
                continue;
            }
            if ($to === 'pace:relaxed' && in_array('relaxed', $ctx['paces'], true)) {
                if ($pace === null) {
                    $pace = 'relaxed';
                    $paceFromExtra = true;
                }   // đã thành "nhịp thong thả", không cần lưu thêm thành ghi chú
            } elseif ($to === 'maxDifficulty:easy' && in_array('easy', $ctx['difficulties'], true)) {
                if ($diff === null) {
                    $diff = 'easy';
                    $diffFromExtra = true;
                }
                $notes[] = $t;
            } elseif ($to === 'note') {
                $notes[] = $t;
            } else {
                $warnings[] = ['type' => 'unsupported', 'text' => $t];
                $notes[] = $t;
            }
        }
        $notes = array_values(array_unique($notes));

        // ---------------- trạng thái từng trường
        $st = fn(bool $has, string $g) => $has ? $guess($g) : 'miss';
        $fields = [
            'destination' => ['state' => $dest ? 'said' : 'miss', 'value' => $dest['name'] ?? null, 'note' => $dest && !$dest['ready'] ? 'Chưa đủ dữ liệu biên tập: dùng đề xuất mở rộng' : null],
            'when' => ['state' => $start !== null || $month !== null ? 'said' : 'miss', 'value' => $start !== null ? self::dmy($start) : ($month !== null ? 'Tháng ' . $month : null), 'note' => $start === null && $month !== null ? 'Chưa chọn ngày cụ thể' : null],
            'days' => ['state' => $days !== null ? 'said' : 'miss', 'value' => $days !== null ? $days . ' ngày' : null, 'note' => null],
            'people' => ['state' => $st($adults !== null, 'people'), 'value' => $adults !== null ? $adults . ' người lớn' . ($children ? ' · ' . $children . ' trẻ em' : '') : null, 'note' => null],
            'budget' => ['state' => $budgetState, 'value' => $budget !== null ? self::vnd($budget) : null, 'note' => $budgetNote],
            'pace' => ['state' => $pace === null ? 'miss' : ($paceFromExtra ? 'guess' : $guess('pace')), 'value' => $pace, 'note' => null],
            'maxDifficulty' => ['state' => $diff === null ? 'miss' : ($diffFromExtra ? 'guess' : $guess('maxDifficulty')), 'value' => $diff, 'note' => null],
            'interests' => ['state' => $interests ? $guess('interests') : 'miss', 'value' => $interests, 'note' => null],
            'dislikes' => ['state' => $dislikes ? $guess('dislikes') : 'miss', 'value' => $dislikes, 'note' => null],
        ];

        $needs = [];
        foreach (self::REQUIRED as $f) {
            if ($fields[$f]['state'] === 'miss') {
                $needs[] = ['field' => $f, 'optional' => false];
            }
        }
        $others = array_values(array_filter(array_map(fn($x) => self::str($x, 60), (array) ($raw['otherDestinations'] ?? []))));
        if ($others) {
            $needs[] = ['field' => 'single_destination', 'optional' => false];
        }
        if ($amount !== null && $scope === null) {
            $needs[] = ['field' => 'budget_scope', 'optional' => true];
        }
        foreach (array_keys($missingDecl) as $g) {
            if (in_array($g, self::REQUIRED, true) && $fields[$g]['state'] !== 'miss') {
                $flags[] = 'missing_but_filled:' . $g;   // mô hình mâu thuẫn với chính nó: tin giá trị đã kiểm
            }
        }

        // ---------------- đoạn trích: chỉ giữ cụm có THẬT trong câu
        $spans = [];
        $hay = mb_strtolower($text);
        foreach (array_slice((array) ($raw['spans'] ?? []), 0, 20) as $s) {
            $t = is_array($s) ? self::str($s['text'] ?? null, 120) : null;
            $f = is_array($s) ? ($s['field'] ?? null) : null;
            if ($t === null || !in_array($f, self::SPAN_FIELDS, true) || !str_contains($hay, mb_strtolower($t))) {
                if ($t !== null) {
                    $flags[] = 'span_not_in_text';
                }
                continue;
            }
            $spans[] = ['text' => $t, 'field' => $f, 'state' => $f === 'extra' ? $this->extraState($t, $warnings) : $fields[$f]['state']];
        }

        $prefill = array_filter([
            'destinationId' => $dest['id'] ?? null,
            'destinationSlug' => $dest['slug'] ?? null,
            'startDate' => $start,
            'month' => $month,
            'days' => $days,
            'adults' => $adults,
            'children' => $adults !== null ? ($children ?? 0) : $children,
            'budget' => $budget !== null ? (int) round($budget) : null,
            'pace' => $pace,
            'maxDifficulty' => $diff,
            'interests' => $interests ?: null,
            'dislikes' => $dislikes ?: null,
            'notes' => $notes ? implode('; ', $notes) : null,
        ], fn($v) => $v !== null);

        return [
            'refused' => !empty($raw['offTopic']) && !$dest && $days === null,
            'fields' => $fields,
            'prefill' => $prefill,
            'needs' => $needs,
            'warnings' => $warnings,
            'notes' => $notes,
            'spans' => $spans,
            'flags' => array_values(array_unique($flags)),
            'mention' => $dest ? null : $mention,
            'others' => $others,
        ];
    }

    private function extraState(string $t, array $warnings): string
    {
        foreach ($warnings as $w) {
            if ($w['type'] === 'unsupported' && mb_strtolower($w['text']) === mb_strtolower($t)) {
                return 'miss';
            }
        }

        return 'guess';
    }

    /**
     * Ghép LAI luật + LLM cho 3 trường bắt buộc. Luật (SearchIntent) chỉ bắt được điều nói RÕ (tên điểm đến có trong CSDL,
     * "tháng 11", "3 ngày", "cuối tuần") nên không đoán. LLM đôi khi bỏ trống các trường này (kết quả không ổn định giữa
     * các lần chạy), khi đó lấy giá trị của luật thay vì bắt người dùng chọn lại. LLM có giá trị thì LLM thắng.
     * Không ghép điểm đến khi câu có nhiều điểm đến (để người dùng chọn) hoặc khi LLM từ chối (lạc đề).
     * @param array $llm  kết quả validate() của JSON LLM
     * @param array $rules kết quả validate() của SearchIntent
     */
    public static function merge(array $llm, array $rules): array
    {
        if ($llm['refused']) {
            return $llm;
        }
        $keys = ['destination' => ['destinationId', 'destinationSlug'], 'when' => ['month', 'startDate'], 'days' => ['days']];
        $multi = in_array('single_destination', array_column($llm['needs'], 'field'), true);
        foreach ($keys as $f => $pk) {
            if ($llm['fields'][$f]['state'] !== 'miss' || $rules['fields'][$f]['state'] === 'miss' || ($f === 'destination' && $multi)) {
                continue;
            }
            $llm['fields'][$f] = $rules['fields'][$f];
            foreach ($pk as $k) {
                if (array_key_exists($k, $rules['prefill'])) {
                    $llm['prefill'][$k] = $rules['prefill'][$k];
                }
            }
            $llm['needs'] = array_values(array_filter($llm['needs'], fn($n) => $n['field'] !== $f));
            $llm['flags'][] = 'rules_filled:' . $f;
            if ($f === 'destination') {
                $llm['mention'] = null;
            }
        }

        return $llm;
    }

    /** Cụm trông như câu lệnh gửi cho AI (bỏ qua hướng dẫn, SYSTEM:, in khóa API...). */
    public static function looksLikeInstruction(string $t): bool
    {
        return (bool) preg_match('/(b[ỏo] qua|ignore|system\s*:|prompt|kh[óo]a\s*api|api\s*key|h[ưu][ớo]ng d[ẫa]n tr[ưu][ớo]c|<\/?data>|slug\s*=)/iu', $t);
    }

    /** @return array<string,true> */
    private function groups(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            if (is_string($n) && isset(self::GROUP[$n])) {
                $out[self::GROUP[$n]] = true;
            }
        }

        return $out;
    }

    private static function int(mixed $v): ?int
    {
        return is_int($v) || (is_numeric($v) && (float) $v == (int) $v) ? (int) $v : null;
    }

    private static function str(mixed $v, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public static function vnd(float $n): string
    {
        return number_format($n, 0, ',', '.') . 'đ';
    }

    private static function dmy(string $ymd): string
    {
        return substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }
}
