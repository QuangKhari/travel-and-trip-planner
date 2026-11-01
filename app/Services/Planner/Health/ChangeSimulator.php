<?php

namespace App\Services\Planner\Health;

use InvalidArgumentException;

/**
 * MỘT nguồn cho ngữ nghĩa thao tác `changes[]` phía máy chủ (tài liệu AI v2.2, mục 6.5).
 * TripResolver (Resolution Engine) và La bàn Copilot cùng gọi hàm này; trình duyệt dùng planner-resolve.js với ĐÚNG ngữ nghĩa.
 *
 * Mục được chỉ bằng (ngày, vị trí) trên BẢN ĐÃ LƯU (vị trí gốc, không dịch sau các thao tác trước). Bên trong hàm gán cho mỗi mục
 * một mã tạm theo vị trí gốc rồi thao tác theo mã, nên nhiều thao tác liên tiếp không trỏ nhầm mục.
 *
 *   time     {day, index, startMin|null}        đặt / bỏ giờ cố định
 *   move     {day, index, toDay|null}           bỏ giờ cố định, đưa xuống CUỐI ngày nhận (null = về "Đã lưu")
 *   reorder  {day, order:[index...]}            xếp lại các mục được liệt kê trong các chỗ chúng đang đứng
 *   replace  {day, index, with:{type,refId}}    thay bằng nơi khác ngay tại chỗ, giữ giờ cố định + buổi; mục cũ về "Đã lưu"
 *   rainplan {day, items:[...]}                 lưu phương án dự phòng khi mưa (lịch không đổi)
 *   add      {with:{type,refId}, toDay|null, after:index|null}   MỚI (La bàn): thêm mục mới vào ngày (ngay sau mục `after`
 *                                               của ngày đó trên bản đã lưu, hoặc cuối ngày) hoặc vào "Đã lưu"
 *
 * Hàm thuần: phần cần dữ liệu (dựng mục mới từ type/refId) truyền vào qua $resolve(type, refId, ?array $old).
 */
final class ChangeSimulator
{
    public const OPS = ['time', 'move', 'reorder', 'replace', 'rainplan', 'add'];

    /**
     * @param array<int,list<array>> $days      các ngày của bản đã lưu (mỗi ngày một danh sách mục)
     * @param list<array>            $unassigned "Đã lưu"
     * @param array                  $payload   read model (phương án mưa nằm ở rain.plan)
     * @param callable(string,int,?array):array $resolve
     * @return array{0:array<int,list<array>>,1:list<array>,2:array}
     * @throws InvalidArgumentException khi thao tác trỏ tới vị trí không còn trên bản đã lưu
     */
    public static function apply(array $days, array $unassigned, array $payload, array $changes, callable $resolve): array
    {
        $store = [];   // mã -> mục
        $cur = [];     // ngày -> danh sách mã theo thứ tự hiện tại
        foreach ($days as $d => $list) {
            $cur[$d] = [];
            foreach (array_values($list) as $i => $it) {
                $id = $d . '#' . $i;
                $store[$id] = $it;
                $cur[$d][] = $id;
            }
        }
        $orig = function (int $day, int $index) use ($store): string {
            $id = $day . '#' . $index;
            if (!array_key_exists($id, $store)) {
                throw new InvalidArgumentException("Không có mục ở ngày $day vị trí $index.");
            }

            return $id;
        };
        $detach = function (string $id) use (&$cur): void {
            foreach ($cur as $d => $ids) {
                $k = array_search($id, $ids, true);
                if ($k !== false) {
                    array_splice($cur[$d], $k, 1);

                    return;
                }
            }
        };
        $n = 0;
        foreach ($changes as $c) {
            $op = $c['op'] ?? '';
            if ($op === 'time') {
                $store[$orig((int) $c['day'], (int) $c['index'])]['startMin'] = $c['startMin'];
            } elseif ($op === 'move') {
                $id = $orig((int) $c['day'], (int) $c['index']);
                $detach($id);
                $store[$id]['startMin'] = null;
                $store[$id]['day'] = $c['toDay'];
                if ($c['toDay'] !== null) {
                    $cur[(int) $c['toDay']][] = $id;
                } else {
                    $unassigned[] = $store[$id];
                }
            } elseif ($op === 'reorder') {
                $d = (int) $c['day'];
                $ids = array_map(fn($i) => $orig($d, (int) $i), $c['order']);
                $slots = [];
                foreach ($ids as $id) {
                    $k = array_search($id, $cur[$d] ?? [], true);
                    if ($k !== false) {
                        $slots[] = $k;
                    }
                }
                sort($slots);
                foreach (array_values(array_filter($ids, fn($id) => in_array($id, $cur[$d] ?? [], true))) as $j => $id) {
                    $cur[$d][$slots[$j]] = $id;
                }
            } elseif ($op === 'replace') {
                $d = (int) $c['day'];
                $id = $orig($d, (int) $c['index']);
                $old = $store[$id];
                $nid = 'n#' . (++$n);
                $store[$nid] = $resolve((string) $c['with']['type'], (int) $c['with']['refId'], $old);
                $k = array_search($id, $cur[$d], true);
                $cur[$d][$k] = $nid;
                $old['startMin'] = null;
                $old['day'] = null;
                $unassigned[] = $old;
                unset($store[$id]);
            } elseif ($op === 'rainplan') {
                $payload['rain']['plan'][(int) $c['day']] = $c['items'];
            } elseif ($op === 'add') {
                $to = $c['toDay'] ?? null;
                $new = $resolve((string) $c['with']['type'], (int) $c['with']['refId'], ['day' => $to, 'startMin' => null, 'slot' => null]);
                $new['day'] = $to;
                $new['startMin'] = null;
                if ($to === null) {
                    $unassigned[] = $new;
                    continue;
                }
                $nid = 'a#' . (++$n);
                $store[$nid] = $new;
                $to = (int) $to;
                $cur[$to] ??= [];
                if (isset($c['after']) && $c['after'] !== null) {
                    $k = array_search($orig($to, (int) $c['after']), $cur[$to], true);
                    if ($k === false) {
                        throw new InvalidArgumentException('Mục mốc của thao tác add đã rời ngày ' . $to . '.');
                    }
                    array_splice($cur[$to], $k + 1, 0, [$nid]);
                } else {
                    $cur[$to][] = $nid;
                }
            } else {
                throw new InvalidArgumentException('Thao tác không hỗ trợ: ' . $op);
            }
        }
        $out = [];
        foreach ($cur as $d => $ids) {
            $out[$d] = array_map(fn($id) => $store[$id], $ids);
        }
        ksort($out);

        return [$out, $unassigned, $payload];
    }

    /** Các ngày bị thao tác chạm tới (để tính effects trước / sau). @return list<int> */
    public static function touchedDays(array $changes): array
    {
        $t = [];
        foreach ($changes as $c) {
            foreach (['day', 'toDay'] as $k) {
                if (isset($c[$k]) && $c[$k] !== null) {
                    $t[(int) $c[$k]] = true;
                }
            }
        }
        ksort($t);

        return array_keys($t);
    }
}
