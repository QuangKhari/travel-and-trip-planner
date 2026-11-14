<?php

namespace App\Http\Controllers\clients;

use App\Http\Controllers\Controller;
use App\Services\Planner\TripExpenses;
use App\Services\Planner\TripSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Chia chi phí nhóm (tab Chi phí > Chi tiêu nhóm). Mọi phản hồi là JSON tóm tắt mới nhất để giao diện vẽ lại. */
class TripExpenseController extends Controller
{
    private function uid(): int
    {
        return (int) session('userId');
    }

    /** @return array{trip:object,role:string} */
    private function access(int $id, bool $edit = false): array
    {
        abort_unless(TripExpenses::ready(), 503, 'Cần chạy php artisan migrate.');
        $sh = app(TripSharing::class);
        $a = $edit ? $sh->editable($this->uid(), $id) : $sh->access($this->uid(), $id);

        return $a ?? abort($edit && $sh->access($this->uid(), $id) ? 403 : 404);
    }

    private function out(object $trip, string $role, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json($extra + app(TripExpenses::class)->summary($trip) + ['me' => 'u:' . $this->uid(), 'canEdit' => TripSharing::canEdit($role), 'isOwner' => $role === 'owner'], $status);
    }

    public function index(int $id): JsonResponse
    {
        $a = $this->access($id);

        return $this->out($a['trip'], $a['role']);
    }

    public function store(Request $r, int $id, ?int $expenseId = null): JsonResponse
    {
        $a = $this->access($id, true);
        $v = $r->validate([
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'amount' => ['required', 'integer', 'between:1,' . TripExpenses::MAX_AMOUNT],
            'category' => ['nullable', 'in:' . implode(',', array_keys(TripExpenses::CATEGORIES))],
            'paidBy' => ['required', 'string', 'regex:/^[ug]:\d{1,10}$/'],
            'splitMode' => ['required', 'in:equal,custom'],
            'spentOn' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'who' => ['nullable', 'array', 'max:40'],
            'who.*' => ['string', 'regex:/^[ug]:\d{1,10}$/'],
            'shares' => ['nullable', 'array', 'max:40'],
            'shares.*' => ['integer', 'min:0', 'max:' . TripExpenses::MAX_AMOUNT],
        ], ['title.required' => 'Nhập tên khoản chi.', 'amount.required' => 'Nhập số tiền.', 'amount.between' => 'Số tiền không hợp lệ.']);
        $res = app(TripExpenses::class)->save($a['trip'], $this->uid(), $v, $expenseId);

        return $this->out($a['trip'], $a['role'], ['ok' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    public function update(Request $r, int $id, int $expenseId): JsonResponse
    {
        return $this->store($r, $id, $expenseId);
    }

    public function destroy(int $id, int $expenseId): JsonResponse
    {
        $a = $this->access($id, true);
        $ok = app(TripExpenses::class)->delete($a['trip'], $this->uid(), $expenseId);

        return $this->out($a['trip'], $a['role'], ['ok' => $ok, 'message' => $ok ? 'Đã xóa khoản chi.' : 'Chỉ người ghi khoản này hoặc chủ chuyến mới xóa được.'], $ok ? 200 : 403);
    }

    public function addGuest(Request $r, int $id): JsonResponse
    {
        $a = $this->access($id, true);
        $v = $r->validate(['name' => ['required', 'string', 'max:60']]);
        $res = app(TripExpenses::class)->addGuest($a['trip'], $v['name']);

        return $this->out($a['trip'], $a['role'], $res, $res['ok'] ? 200 : 422);
    }

    public function removeGuest(int $id, int $guestId): JsonResponse
    {
        $a = $this->access($id, true);
        $res = app(TripExpenses::class)->removeGuest($a['trip'], $guestId);

        return $this->out($a['trip'], $a['role'], $res, $res['ok'] ? 200 : 422);
    }
}
