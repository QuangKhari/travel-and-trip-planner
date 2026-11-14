<?php

namespace App\Services\Planner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chia chi phí nhóm của một chuyến đi (khác "Chi phí dự kiến" của từng mục lịch):
 * ghi khoản ĐÃ CHI (ai trả, bao nhiêu, chia cho ai), tính mỗi người đã trả / phải trả / còn chênh,
 * rồi đề xuất ít lần chuyển tiền nhất để cả nhóm hòa nhau.
 *
 * Người trong nhóm: chủ chuyến + thành viên (u:{userId}) + người đi cùng không có tài khoản (g:{guestId}).
 * Tiền là số nguyên VND; chia đều thì phần dư (vài đồng) dồn cho những người đầu danh sách để tổng luôn khớp.
 * Quyền: ai có quyền sửa chuyến thì thêm / sửa khoản chi; xóa = người tạo khoản hoặc chủ chuyến; người "chỉ xem" chỉ xem.
 */
final class TripExpenses
{
    public const CATEGORIES = ['food' => 'Ăn uống', 'stay' => 'Chỗ ở', 'move' => 'Di chuyển', 'ticket' => 'Vé, tham quan', 'shop' => 'Mua sắm', 'other' => 'Khác'];
    public const MAX_AMOUNT = 100000000000;
    public const MAX_EXPENSES = 300;
    public const MAX_GUESTS = 20;

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_trip_expenses') && Schema::hasTable('tbl_trip_guests');
    }

    /** @return list<array{who:string,name:string,kind:string}> */
    public function people(object $trip): array
    {
        $out = [];
        foreach (app(TripSharing::class)->members($trip, false) as $m) {
            $out[] = ['who' => 'u:' . $m['userId'], 'name' => $m['name'] !== '' ? $m['name'] : 'Thành viên', 'kind' => 'member'];
        }
        foreach (DB::table('tbl_trip_guests')->where('tripId', $trip->tripId)->orderBy('guestId')->get() as $g) {
            $out[] = ['who' => 'g:' . $g->guestId, 'name' => $g->name, 'kind' => 'guest'];
        }

        return $out;
    }

    /** Toàn bộ dữ liệu cho tab Chi phí: người, khoản chi, số dư, cách chuyển tiền. */
    public function summary(object $trip): array
    {
        $people = $this->people($trip);
        $names = array_column($people, 'name', 'who');
        $rows = DB::table('tbl_trip_expenses')->where('tripId', $trip->tripId)->orderByDesc('spentOn')->orderByDesc('expenseId')->get();
        $shares = $rows->isEmpty() ? collect() : DB::table('tbl_trip_expense_shares')->whereIn('expenseId', $rows->pluck('expenseId'))->get()->groupBy('expenseId');
        $paid = array_fill_keys(array_keys($names), 0);
        $owe = $paid;
        $byCat = [];
        $list = [];
        foreach ($rows as $r) {
            $sh = [];
            foreach ($shares[$r->expenseId] ?? [] as $s) {
                $sh[$s->who] = (int) $s->amount;
                $owe[$s->who] = ($owe[$s->who] ?? 0) + (int) $s->amount;
                $names[$s->who] ??= 'Người đã rời nhóm';
            }
            $paid[$r->paidBy] = ($paid[$r->paidBy] ?? 0) + (int) $r->amount;
            $names[$r->paidBy] ??= 'Người đã rời nhóm';
            $byCat[$r->category] = ($byCat[$r->category] ?? 0) + (int) $r->amount;
            $list[] = ['id' => (int) $r->expenseId, 'title' => $r->title, 'category' => $r->category, 'amount' => (int) $r->amount, 'paidBy' => $r->paidBy,
                'splitMode' => $r->splitMode, 'spentOn' => $r->spentOn, 'note' => $r->note, 'shares' => $sh, 'createdBy' => $r->createdBy !== null ? (int) $r->createdBy : null];
        }
        $balance = [];
        foreach ($names as $who => $n) {
            $balance[$who] = ($paid[$who] ?? 0) - ($owe[$who] ?? 0);
        }

        return [
            'people' => $people,
            'names' => $names,
            'expenses' => $list,
            'total' => array_sum(array_column($list, 'amount')),
            'byCategory' => $byCat,
            'paid' => $paid,
            'owe' => $owe,
            'balance' => $balance,
            'settle' => self::settle($balance),
            'categories' => self::CATEGORIES,
        ];
    }

    /**
     * Ít lần chuyển nhất (tham lam: người nợ nhiều nhất trả người được nhận nhiều nhất), đủ tốt cho nhóm ≤ 30 người.
     *
     * @param array<string,int> $balance  dương = được nhận lại, âm = còn phải trả
     * @return list<array{from:string,to:string,amount:int}>
     */
    public static function settle(array $balance): array
    {
        $cred = [];
        $debt = [];
        foreach ($balance as $who => $b) {
            if ($b > 0) {
                $cred[$who] = $b;
            } elseif ($b < 0) {
                $debt[$who] = -$b;
            }
        }
        $out = [];
        while ($cred && $debt) {
            arsort($cred);
            arsort($debt);
            $c = array_key_first($cred);
            $d = array_key_first($debt);
            $x = min($cred[$c], $debt[$d]);
            $out[] = ['from' => $d, 'to' => $c, 'amount' => $x];
            $cred[$c] -= $x;
            $debt[$d] -= $x;
            if ($cred[$c] === 0) {
                unset($cred[$c]);
            }
            if ($debt[$d] === 0) {
                unset($debt[$d]);
            }
        }

        return $out;
    }

    /**
     * Chia đều $amount cho $who (giữ thứ tự): phần dư dồn mỗi người 1 đồng từ đầu danh sách.
     *
     * @param list<string> $who
     * @return array<string,int>
     */
    public static function splitEqual(int $amount, array $who): array
    {
        $n = count($who);
        if ($n === 0) {
            return [];
        }
        $base = intdiv($amount, $n);
        $rest = $amount - $base * $n;
        $out = [];
        foreach (array_values($who) as $i => $w) {
            $out[$w] = $base + ($i < $rest ? 1 : 0);
        }

        return $out;
    }

    /**
     * Thêm (expenseId null) hoặc sửa một khoản chi.
     *
     * @param array{title:string,amount:int,category?:string,paidBy:string,splitMode:string,spentOn?:?string,note?:?string,who?:list<string>,shares?:array<string,int>} $d
     * @return array{ok:bool,message:string,id?:int}
     */
    public function save(object $trip, int $actorId, array $d, ?int $expenseId = null): array
    {
        $valid = array_column($this->people($trip), 'who');
        $amount = (int) $d['amount'];
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            return ['ok' => false, 'message' => 'Số tiền không hợp lệ.'];
        }
        if (!in_array($d['paidBy'], $valid, true)) {
            return ['ok' => false, 'message' => 'Người trả tiền không còn trong nhóm.'];
        }
        if (($d['splitMode'] ?? 'equal') === 'custom') {
            $shares = [];
            foreach ((array) ($d['shares'] ?? []) as $who => $v) {
                $v = (int) $v;
                if ($v < 0 || !in_array($who, $valid, true)) {
                    return ['ok' => false, 'message' => 'Phần chia có người không thuộc nhóm hoặc số âm.'];
                }
                if ($v > 0) {
                    $shares[$who] = $v;
                }
            }
            if (!$shares || array_sum($shares) !== $amount) {
                return ['ok' => false, 'message' => 'Tổng các phần chia (' . number_format(array_sum($shares), 0, ',', '.') . 'đ) phải bằng số tiền (' . number_format($amount, 0, ',', '.') . 'đ).'];
            }
        } else {
            $who = array_values(array_unique(array_filter((array) ($d['who'] ?? []), fn($w) => in_array($w, $valid, true))));
            if (!$who) {
                return ['ok' => false, 'message' => 'Chọn ít nhất một người cùng chia khoản này.'];
            }
            $shares = self::splitEqual($amount, $who);
        }
        if ($expenseId === null && DB::table('tbl_trip_expenses')->where('tripId', $trip->tripId)->count() >= self::MAX_EXPENSES) {
            return ['ok' => false, 'message' => 'Mỗi chuyến tối đa ' . self::MAX_EXPENSES . ' khoản chi.'];
        }
        $row = [
            'title' => mb_substr(trim($d['title']), 0, 120), 'category' => array_key_exists($d['category'] ?? 'other', self::CATEGORIES) ? ($d['category'] ?? 'other') : 'other',
            'amount' => $amount, 'paidBy' => $d['paidBy'], 'splitMode' => ($d['splitMode'] ?? 'equal') === 'custom' ? 'custom' : 'equal',
            'spentOn' => $d['spentOn'] ?? null, 'note' => isset($d['note']) ? mb_substr(trim((string) $d['note']), 0, 255) : null, 'updatedAt' => now(),
        ];

        return DB::transaction(function () use ($trip, $actorId, $row, $shares, $expenseId) {
            if ($expenseId === null) {
                $expenseId = (int) DB::table('tbl_trip_expenses')->insertGetId($row + ['tripId' => $trip->tripId, 'createdBy' => $actorId, 'createdAt' => now()]);
            } elseif (!DB::table('tbl_trip_expenses')->where(['tripId' => $trip->tripId, 'expenseId' => $expenseId])->update($row)
                && !DB::table('tbl_trip_expenses')->where(['tripId' => $trip->tripId, 'expenseId' => $expenseId])->exists()) {
                return ['ok' => false, 'message' => 'Khoản chi không còn tồn tại.'];
            }
            DB::table('tbl_trip_expense_shares')->where('expenseId', $expenseId)->delete();
            DB::table('tbl_trip_expense_shares')->insert(array_map(fn($w, $a) => ['expenseId' => $expenseId, 'who' => $w, 'amount' => $a], array_keys($shares), $shares));

            return ['ok' => true, 'message' => 'Đã lưu khoản chi "' . $row['title'] . '".', 'id' => $expenseId];
        });
    }

    /** Xóa: người tạo khoản hoặc chủ chuyến. */
    public function delete(object $trip, int $actorId, int $expenseId): bool
    {
        $e = DB::table('tbl_trip_expenses')->where(['tripId' => $trip->tripId, 'expenseId' => $expenseId])->first();
        if (!$e || ((int) $trip->userId !== $actorId && (int) $e->createdBy !== $actorId)) {
            return false;
        }
        DB::transaction(function () use ($expenseId) {
            DB::table('tbl_trip_expense_shares')->where('expenseId', $expenseId)->delete();
            DB::table('tbl_trip_expenses')->where('expenseId', $expenseId)->delete();
        });

        return true;
    }

    /** @return array{ok:bool,message:string,who?:string} */
    public function addGuest(object $trip, string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 1 || mb_strlen($name) > 60) {
            return ['ok' => false, 'message' => 'Tên từ 1 đến 60 ký tự.'];
        }
        $taken = array_map(fn($p) => mb_strtolower($p['name']), $this->people($trip));
        if (in_array(mb_strtolower($name), $taken, true)) {
            return ['ok' => false, 'message' => 'Trong nhóm đã có người tên "' . $name . '". Thêm chữ để phân biệt, ví dụ "' . $name . ' (bạn Lan)".'];
        }
        if (DB::table('tbl_trip_guests')->where('tripId', $trip->tripId)->count() >= self::MAX_GUESTS) {
            return ['ok' => false, 'message' => 'Tối đa ' . self::MAX_GUESTS . ' người đi cùng không có tài khoản.'];
        }
        $id = DB::table('tbl_trip_guests')->insertGetId(['tripId' => $trip->tripId, 'name' => $name, 'createdAt' => now()]);

        return ['ok' => true, 'message' => 'Đã thêm ' . $name . '.', 'who' => 'g:' . $id];
    }

    /** Không xóa được người đã có trong khoản chi (tránh lệch sổ). */
    public function removeGuest(object $trip, int $guestId): array
    {
        $who = 'g:' . $guestId;
        if (!DB::table('tbl_trip_guests')->where(['tripId' => $trip->tripId, 'guestId' => $guestId])->exists()) {
            return ['ok' => false, 'message' => 'Không tìm thấy người này.'];
        }
        $used = DB::table('tbl_trip_expenses')->where('tripId', $trip->tripId)->where('paidBy', $who)->exists()
            || DB::table('tbl_trip_expense_shares as s')->join('tbl_trip_expenses as e', 'e.expenseId', '=', 's.expenseId')->where('e.tripId', $trip->tripId)->where('s.who', $who)->exists();
        if ($used) {
            return ['ok' => false, 'message' => 'Người này đang có trong khoản chi. Sửa hoặc xóa các khoản đó trước.'];
        }
        DB::table('tbl_trip_guests')->where('guestId', $guestId)->delete();

        return ['ok' => true, 'message' => 'Đã gỡ.'];
    }
}
