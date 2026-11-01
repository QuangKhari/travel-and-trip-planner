<?php

namespace App\Services\Ai\Copilot;

/**
 * Biên dịch thao tác DẠNG KHÓA của La bàn sang dạng (ngày, vị trí) mà ChangeSimulator / planner-resolve.js dùng
 * (tài liệu AI v2.2, mục 5.3). Hàm thuần.
 *
 *   iN            khóa mục trong chuyến theo rev đang xem (ngày tăng dần, vị trí, "Đã lưu" cuối cùng)
 *   c:<type>:<id> khóa ỨNG VIÊN, chỉ hợp lệ nếu đã xuất hiện trong kết quả search_places / around_route của chính lượt này
 *
 * Từ chối (trả lỗi bằng lời để LLM tự sửa, tối đa 2 vòng):
 *   - khóa lạ, ứng viên không có trong kết quả tìm, ứng viên đã có trong chuyến
 *   - đụng mục có giờ cố định / đã khóa mà người dùng KHÔNG nêu tên mục đó
 *   - một mục bị di chuyển / thay hai lần; xếp lại ngày đang có mục bị chuyển đi; xếp lại làm xê dịch mục cố định, mục đầu ngày
 *   - mục trong "Đã lưu" (chưa có vị trí trên lịch)
 */
final class KeyedChangeCompiler
{
    public const MAX_OPS = 8;

    /**
     * @param array<string,array{day:?int,index:?int,name:string,type:string,refId:?int,fixed:bool,locked:bool}> $keys
     * @param array<string,array> $cands khóa ứng viên => mục dạng itemForClient (type, refId, name...)
     * @param list<string> $explicit khóa mục người dùng đã nêu đích danh (được phép đụng dù cố định / khóa)
     * @return array{ok:bool,errors:list<string>,changes:list<array>,human:list<array{op:string,text:string}>,ghost:list<array>}
     */
    public function compile(array $keyed, array $keys, array $cands, int $tripDays, array $explicit = []): array
    {
        $err = [];
        $out = [];
        $human = [];
        $ghost = [];
        $touched = [];   // khóa mục đã bị move/replace
        $added = [];
        $reorderDays = [];
        $have = [];
        foreach ($keys as $k) {
            if ($k['refId'] !== null) {
                $have[$k['type'] . ':' . $k['refId']] = true;
            }
        }
        if (count($keyed) > self::MAX_OPS) {
            return ['ok' => false, 'errors' => ['Tối đa ' . self::MAX_OPS . ' thao tác trong một đề xuất.'], 'changes' => [], 'human' => [], 'ghost' => []];
        }
        $item = function (mixed $k, string $op) use ($keys, &$err): ?array {
            if (!is_string($k) || !isset($keys[$k])) {
                $err[] = "$op: khóa mục '" . (is_string($k) ? $k : '?') . "' không có trong chuyến.";

                return null;
            }
            if ($keys[$k]['day'] === null) {
                $err[] = "$op: $k ({$keys[$k]['name']}) đang ở Đã lưu, chưa có vị trí trên lịch nên không thao tác được.";

                return null;
            }

            return $keys[$k] + ['key' => $k];
        };
        $cand = function (mixed $k, string $op) use ($cands, $have, &$err): ?array {
            if (!is_string($k) || !isset($cands[$k])) {
                $err[] = "$op: ứng viên '" . (is_string($k) ? $k : '?') . "' không có trong kết quả tìm của lượt này. Hãy gọi search_places trước.";

                return null;
            }
            $c = $cands[$k];
            if (isset($have[$c['type'] . ':' . $c['refId']])) {
                $err[] = "$op: {$c['name']} đã có trong chuyến.";

                return null;
            }

            return $c;
        };
        $guard = function (array $it, string $op) use ($explicit, &$err): bool {
            if (($it['fixed'] || $it['locked']) && !in_array($it['key'], $explicit, true)) {
                $err[] = "$op: {$it['key']} ({$it['name']}) " . ($it['locked'] ? 'đã khóa' : 'có giờ cố định') . ', người dùng không nhắc tới nên không được đụng.';

                return false;
            }

            return true;
        };
        $dayOk = fn($d) => $d === null || (is_int($d) && $d >= 1 && $d <= $tripDays);

        foreach ($keyed as $c) {
            $op = is_array($c) ? (string) ($c['op'] ?? '') : '';
            switch ($op) {
                case 'move':
                    $it = $item($c['item'] ?? null, 'move');
                    $to = $c['toDay'] ?? null;
                    if (!$it || !$guard($it, 'move')) {
                        break;
                    }
                    if (!$dayOk($to) || $to === $it['day']) {
                        $err[] = "move: ngày nhận không hợp lệ cho {$it['key']}.";
                        break;
                    }
                    if (isset($touched[$it['key']])) {
                        $err[] = "move: {$it['key']} đã bị thao tác ở bước trước.";
                        break;
                    }
                    $touched[$it['key']] = $it['day'];
                    $out[] = ['op' => 'move', 'day' => $it['day'], 'index' => $it['index'], 'toDay' => $to];
                    $human[] = ['op' => 'move', 'text' => $it['name'] . ' → ' . ($to === null ? 'Đã lưu' : 'Ngày ' . $to)];
                    $ghost[] = ['kind' => 'out', 'name' => $it['name'], 'day' => $it['day'], 'toDay' => $to];
                    break;

                case 'time':
                    $it = $item($c['item'] ?? null, 'time');
                    $sm = $c['startMin'] ?? null;
                    if (!$it) {
                        break;
                    }
                    if ($it['locked'] && !in_array($it['key'], $explicit, true)) {
                        $err[] = "time: {$it['key']} ({$it['name']}) đã khóa.";
                        break;
                    }
                    if ($sm !== null && (!is_numeric($sm) || $sm < 300 || $sm > 1380)) {
                        $err[] = 'time: giờ bắt đầu phải trong 05:00–23:00.';
                        break;
                    }
                    $sm = $sm === null ? null : (int) (round($sm / 5) * 5);
                    $out[] = ['op' => 'time', 'day' => $it['day'], 'index' => $it['index'], 'startMin' => $sm];
                    $human[] = ['op' => 'time', 'text' => $it['name'] . ($sm === null ? ': bỏ giờ cố định' : ' → ' . self::hhmm($sm))];
                    $ghost[] = ['kind' => 'time', 'name' => $it['name'], 'day' => $it['day'], 'startMin' => $sm];
                    break;

                case 'replace':
                    $it = $item($c['item'] ?? null, 'replace');
                    $w = $cand($c['with'] ?? null, 'replace');
                    if (!$it || !$w || !$guard($it, 'replace')) {
                        break;
                    }
                    if (isset($touched[$it['key']])) {
                        $err[] = "replace: {$it['key']} đã bị thao tác ở bước trước.";
                        break;
                    }
                    $touched[$it['key']] = $it['day'];
                    $added[$w['type'] . ':' . $w['refId']] = true;
                    $out[] = ['op' => 'replace', 'day' => $it['day'], 'index' => $it['index'], 'with' => $w];
                    $human[] = ['op' => 'replace', 'text' => $it['name'] . ' → thay bằng ' . $w['name']];
                    $ghost[] = ['kind' => 'replace', 'name' => $it['name'], 'day' => $it['day'], 'withName' => $w['name']];
                    break;

                case 'add':
                    $w = $cand($c['with'] ?? null, 'add');
                    $to = $c['toDay'] ?? null;
                    if (!$w) {
                        break;
                    }
                    if (isset($added[$w['type'] . ':' . $w['refId']])) {
                        $err[] = "add: {$w['name']} đã được thêm ở bước trước.";
                        break;
                    }
                    if (!$dayOk($to)) {
                        $err[] = 'add: ngày nhận không hợp lệ.';
                        break;
                    }
                    $after = null;
                    $afterName = null;
                    if (($c['after'] ?? null) !== null) {
                        $a = $item($c['after'], 'add.after');
                        if (!$a) {
                            break;
                        }
                        if ($a['day'] !== $to || isset($touched[$a['key']])) {
                            $err[] = "add: mục mốc {$a['key']} phải nằm ở ngày $to và không bị chuyển đi.";
                            break;
                        }
                        $after = $a['index'];
                        $afterName = $a['name'];
                    }
                    $added[$w['type'] . ':' . $w['refId']] = true;
                    $out[] = ['op' => 'add', 'with' => $w, 'toDay' => $to, 'after' => $after];
                    $human[] = ['op' => 'add', 'text' => 'Thêm ' . $w['name'] . ($to === null ? ' vào Đã lưu' : ' vào ngày ' . $to . ($afterName ? ', sau ' . $afterName : ''))];
                    $ghost[] = ['kind' => 'add', 'name' => $w['name'], 'toDay' => $to, 'afterName' => $afterName, 'sub' => $w['sub'] ?? ''];
                    break;

                case 'reorder':
                    $d = $c['day'] ?? null;
                    $order = array_values((array) ($c['order'] ?? []));
                    if (!is_int($d) || $d < 1 || $d > $tripDays) {
                        $err[] = 'reorder: ngày không hợp lệ.';
                        break;
                    }
                    $dayKeys = array_keys(array_filter($keys, fn($k) => $k['day'] === $d));
                    usort($dayKeys, fn($a, $b) => $keys[$a]['index'] <=> $keys[$b]['index']);
                    $sa = $order;
                    $sb = $dayKeys;
                    sort($sa);
                    sort($sb);
                    if ($sa !== $sb) {
                        $err[] = "reorder: order phải liệt kê ĐỦ và ĐÚNG các mục của ngày $d: " . implode(', ', $dayKeys) . '.';
                        break;
                    }
                    $reorderDays[$d] = true;
                    $bad = null;
                    foreach ($dayKeys as $pos => $k) {
                        $x = $keys[$k];
                        $anchor = $pos === 0 || $x['fixed'] || $x['locked'];
                        if ($anchor && $order[$pos] !== $k) {
                            $bad = "reorder: {$k} ({$x['name']}) là mục " . ($pos === 0 ? 'đầu ngày' : 'cố định / đã khóa') . ', phải giữ nguyên vị trí.';
                            break;
                        }
                    }
                    if ($bad) {
                        $err[] = $bad;
                        break;
                    }
                    $out[] = ['op' => 'reorder', 'day' => $d, 'order' => array_map(fn($k) => $keys[$k]['index'], $order)];
                    $human[] = ['op' => 'reorder', 'text' => 'Ngày ' . $d . ': ' . implode(' → ', array_map(fn($k) => $keys[$k]['name'], $order))];
                    $ghost[] = ['kind' => 'reorder', 'day' => $d, 'names' => array_map(fn($k) => $keys[$k]['name'], $order)];
                    break;

                case 'rainplan':
                    $d = $c['day'] ?? null;
                    $list = [];
                    foreach (array_slice((array) ($c['items'] ?? []), 0, 3) as $k) {
                        $w = $cand($k, 'rainplan');
                        if ($w) {
                            $list[] = ['type' => $w['type'], 'refId' => $w['refId'], 'name' => $w['name'], 'sub' => $w['sub'] ?? '', 'url' => $w['url'] ?? null, 'lat' => $w['lat'] ?? null, 'lng' => $w['lng'] ?? null];
                        }
                    }
                    if (!is_int($d) || $d < 1 || $d > $tripDays || !$list) {
                        $err[] = 'rainplan: cần ngày hợp lệ và ít nhất một ứng viên trong nhà.';
                        break;
                    }
                    $out[] = ['op' => 'rainplan', 'day' => $d, 'items' => $list];
                    $human[] = ['op' => 'rainplan', 'text' => 'Nếu mưa ngày ' . $d . ': ' . implode(', ', array_column($list, 'name'))];
                    $ghost[] = ['kind' => 'rainplan', 'day' => $d];
                    break;

                default:
                    $err[] = "Thao tác không hỗ trợ: '$op'.";
            }
        }
        // xếp lại một ngày mà cũng có mục bị chuyển / thay khỏi ngày đó: vị trí không còn rõ nghĩa
        foreach ($touched as $k => $d) {
            if (isset($reorderDays[$d])) {
                $err[] = "reorder: ngày $d vừa có mục bị chuyển / thay, không xếp lại cùng một đề xuất.";
                break;
            }
        }
        if (!$out && !$err) {
            $err[] = 'Đề xuất rỗng.';
        }

        return ['ok' => !$err, 'errors' => array_values(array_unique($err)), 'changes' => $out, 'human' => $human, 'ghost' => $ghost];
    }

    public static function hhmm(int $m): string
    {
        return str_pad((string) intdiv($m, 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }
}
