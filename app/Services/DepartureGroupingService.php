<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ghép các lịch khởi hành vào một đoàn vận hành chung, KHÔNG thay đổi booking, giá hoặc hạn mức bán riêng.
 * Hai chế độ so khớp lịch trình:
 *   strict (mặc định): tiêu đề + mô tả từng ngày phải giống hệt.
 *   titles: chỉ cần tiêu đề từng ngày giống nhau (cùng số ngày, cùng thứ tự). Admin phải xác nhận đã tự kiểm tra phần mô tả.
 * Hai tour chỉ cùng điểm đến không đủ điều kiện ghép.
 */
class DepartureGroupingService
{
    public const MODES = ['strict', 'titles'];

    public function ready(): bool
    {
        return Schema::hasTable('tbl_departure_groups') && Schema::hasTable('tbl_departure_group_members');
    }

    private function hasModeColumn(): bool
    {
        return Schema::hasColumn('tbl_departure_groups', 'fingerprintMode');
    }

    private static function norm(?string $s): string
    {
        return trim(mb_strtolower(preg_replace('/\s+/u', ' ', strip_tags((string) $s))));
    }

    public function fingerprint(int $tourId, string $mode = 'strict'): ?string
    {
        $timeline = DB::table('tbl_timeline')->where('tourId', $tourId)->orderBy('timeLineId')
            ->get(['title', 'description']);
        if ($timeline->isEmpty()) {
            return null; // Không được suy đoán giống nhau khi không có lịch trình.
        }
        $normalized = $timeline->map(fn ($r) => $mode === 'titles'
            ? [self::norm($r->title)]
            : [self::norm($r->title), self::norm($r->description)])->all();

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Số khách của cả đoàn so với mức tối thiểu của đoàn. Đoàn chạy chung một đoàn nên tính TỔNG khách của các lịch thành viên;
     * mức tối thiểu của đoàn là mức CAO NHẤT trong các lịch (thận trọng: đoàn đủ khi đạt yêu cầu của tour khắt khe nhất).
     * @return array{booked:int, minimum:int, short:int, members:int, open:int}
     */
    public function groupStats(int $groupId): array
    {
        $m = DB::table('tbl_departure_group_members as gm')->join('tbl_tour_availability as a', 'a.availabilityId', '=', 'gm.availabilityId')
            ->where('gm.groupId', $groupId)
            ->selectRaw('COALESCE(SUM(a.bookedSlots), 0) as booked, COALESCE(MAX(a.minParticipants), 1) as minimum, COUNT(*) as members, COALESCE(SUM(CASE WHEN a.status = ? THEN 1 ELSE 0 END), 0) as openMembers', ['open'])
            ->first();
        $booked = (int) ($m->booked ?? 0);
        $min = (int) ($m->minimum ?? 1);

        return ['booked' => $booked, 'minimum' => $min, 'short' => max(0, $min - $booked), 'members' => (int) ($m->members ?? 0), 'open' => (int) ($m->openMembers ?? 0)];
    }

    public function candidates(int $availabilityId, string $mode = 'strict'): array
    {
        if (!$this->ready()) return [];
        $mode = in_array($mode, self::MODES, true) ? $mode : 'strict';
        $base = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->where('a.availabilityId', $availabilityId)->first(['a.*', 't.destination', 't.domain', 't.durationDays']);
        if (!$base || $base->departureDate <= now()->toDateString()) return [];
        $hash = $this->fingerprint((int) $base->tourId, $mode);
        if (!$hash) return [];
        $rows = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->leftJoin('tbl_departure_group_members as m', 'm.availabilityId', '=', 'a.availabilityId')
            ->whereNull('m.memberId')->where('a.availabilityId', '!=', $availabilityId)
            ->where('a.tourId', '!=', $base->tourId)->where('a.departureDate', $base->departureDate)
            ->where('a.status', 'open')->where('t.destination', $base->destination)
            ->where('t.domain', $base->domain)->where('t.durationDays', $base->durationDays)
            ->get(['a.*', 't.title']);
        return $rows->filter(fn ($r) => $this->fingerprint((int) $r->tourId, $mode) === $hash)->values()->all();
    }

    public function setMinimum(int $availabilityId, int $minimum): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Chưa chạy migration ghép đoàn.'];
        return DB::transaction(function () use ($availabilityId, $minimum) {
            $a = DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->lockForUpdate()->first();
            if (!$a) return ['ok' => false, 'message' => 'Không tìm thấy lịch khởi hành.'];
            if ($minimum < 1 || $minimum > (int) $a->totalSlots) return ['ok' => false, 'message' => 'Số khách tối thiểu phải từ 1 đến tổng số chỗ.'];
            DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->update(['minParticipants' => $minimum, 'updatedAt' => now()]);
            return ['ok' => true, 'message' => 'Đã lưu số khách tối thiểu.'];
        });
    }

    public function merge(int $firstId, int $secondId, ?string $note = null, string $mode = 'strict'): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Chưa chạy migration ghép đoàn.'];
        if ($firstId === $secondId) return ['ok' => false, 'message' => 'Phải chọn hai ngày khởi hành khác nhau.'];
        $mode = in_array($mode, self::MODES, true) ? $mode : 'strict';
        return DB::transaction(function () use ($firstId, $secondId, $note, $mode) {
            $ids = [$firstId, $secondId];
            sort($ids, SORT_NUMERIC);
            // Thứ tự khóa thống nhất với DepartureRescueService::apply: dòng tour (id tăng dần) rồi mới tới dòng lịch (id tăng dần).
            $peek = DB::table('tbl_tour_availability')->whereIn('availabilityId', $ids)->get(['availabilityId', 'tourId']);
            if ($peek->count() !== 2) return ['ok' => false, 'message' => 'Không tìm thấy đủ hai ngày khởi hành.'];
            $tourIds = $peek->pluck('tourId')->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
            DB::table('tbl_tours')->whereIn('tourId', $tourIds)->orderBy('tourId')->lockForUpdate()->get();
            $rows = DB::table('tbl_tour_availability')->whereIn('availabilityId', $ids)->orderBy('availabilityId')->lockForUpdate()->get();
            if ($rows->count() !== 2) return ['ok' => false, 'message' => 'Không tìm thấy đủ hai ngày khởi hành.'];
            if (DB::table('tbl_departure_group_members')->whereIn('availabilityId', $ids)->exists()) {
                return ['ok' => false, 'message' => 'Một ngày đã thuộc đoàn chung.'];
            }
            [$a, $b] = [$rows[0], $rows[1]];
            if ((int) $a->tourId === (int) $b->tourId || $a->departureDate !== $b->departureDate || $a->departureDate <= now()->toDateString()) {
                return ['ok' => false, 'message' => 'Tour phải khác nhau, cùng ngày khởi hành trong tương lai.'];
            }
            if ($a->status !== 'open' || $b->status !== 'open') {
                return ['ok' => false, 'message' => 'Cả hai ngày phải đang mở bán.'];
            }
            $tourA = DB::table('tbl_tours')->where('tourId', $a->tourId)->first();
            $tourB = DB::table('tbl_tours')->where('tourId', $b->tourId)->first();
            if (!$tourA || !$tourB || !$tourA->availability || !$tourB->availability
                || $tourA->destination !== $tourB->destination || $tourA->domain !== $tourB->domain
                || DepartureService::durationDays($tourA) !== DepartureService::durationDays($tourB)) {
                return ['ok' => false, 'message' => 'Điểm đến, vùng miền hoặc thời lượng không tương thích.'];
            }
            $hash = $this->fingerprint((int) $a->tourId, $mode);
            if (!$hash || $hash !== $this->fingerprint((int) $b->tourId, $mode)) {
                return ['ok' => false, 'message' => $mode === 'titles'
                    ? 'Tiêu đề các ngày của hai tour khác nhau hoặc chưa có lịch trình; không được ghép.'
                    : 'Lịch trình chưa có hoặc khác nhau; không được ghép toàn bộ.'];
            }
            $capacity = (int) $a->totalSlots + (int) $b->totalSlots;
            $group = [
                'departureDate' => $a->departureDate, 'status' => 'active', 'capacity' => $capacity,
                'itineraryFingerprint' => $hash, 'note' => $note, 'createdAt' => now(), 'updatedAt' => now(),
            ];
            if ($this->hasModeColumn()) {
                $group['fingerprintMode'] = $mode;
            }
            $groupId = DB::table('tbl_departure_groups')->insertGetId($group);
            DB::table('tbl_departure_group_members')->insert([
                ['groupId' => $groupId, 'availabilityId' => $a->availabilityId, 'createdAt' => now()],
                ['groupId' => $groupId, 'availabilityId' => $b->availabilityId, 'createdAt' => now()],
            ]);
            // Lịch vào đoàn thì được xét thiếu khách theo CẢ ĐOÀN (departures:check-shortfall), bỏ cờ riêng lẻ nếu có.
            if (Schema::hasColumn('tbl_tour_availability', 'shortfallFlaggedAt')) {
                DB::table('tbl_tour_availability')->whereIn('availabilityId', $ids)->update(['shortfallFlaggedAt' => null]);
            }
            return ['ok' => true, 'message' => 'Đã tạo đoàn chung #' . $groupId . '. Booking và giá tour không thay đổi.', 'id' => $groupId,
                'availabilityIds' => [(int) $a->availabilityId, (int) $b->availabilityId]];
        }, 3);
    }

    public function disband(int $groupId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Chưa chạy migration ghép đoàn.'];
        return DB::transaction(function () use ($groupId) {
            $group = DB::table('tbl_departure_groups')->where('groupId', $groupId)->lockForUpdate()->first();
            if (!$group || $group->status !== 'active') return ['ok' => false, 'message' => 'Đoàn không còn hoạt động.'];
            if ($group->departureDate <= now()->toDateString()) return ['ok' => false, 'message' => 'Không thể tách đoàn đã khởi hành.'];
            $members = DB::table('tbl_departure_group_members')->where('groupId', $groupId)->pluck('availabilityId')->map(fn ($v) => (int) $v)->all();
            DB::table('tbl_departure_group_members')->where('groupId', $groupId)->delete();
            DB::table('tbl_departure_groups')->where('groupId', $groupId)->update(['status' => 'disbanded', 'updatedAt' => now()]);
            return ['ok' => true, 'message' => 'Đã tách đoàn. Các booking gốc vẫn được giữ nguyên.', 'availabilityIds' => $members];
        });
    }

    /**
     * Gỡ một lịch khỏi đoàn (dùng khi Travela hủy lịch đó). Gọi TRONG transaction của người gọi.
     * Đoàn chỉ còn một thành viên không còn ý nghĩa nên được giải thể luôn.
     * @return array{groupId:int, others:int[]}|null null nếu lịch không thuộc đoàn nào
     */
    public function releaseMembership(int $availabilityId): ?array
    {
        if (!$this->ready()) return null;
        $groupId = DB::table('tbl_departure_group_members')->where('availabilityId', $availabilityId)->value('groupId');
        if (!$groupId) return null;
        $groupId = (int) $groupId;
        DB::table('tbl_departure_groups')->where('groupId', $groupId)->lockForUpdate()->first();
        $others = DB::table('tbl_departure_group_members')->where('groupId', $groupId)
            ->where('availabilityId', '!=', $availabilityId)->pluck('availabilityId')->map(fn ($v) => (int) $v)->all();
        DB::table('tbl_departure_group_members')->where('groupId', $groupId)->delete();
        DB::table('tbl_departure_groups')->where('groupId', $groupId)->update(['status' => 'disbanded', 'updatedAt' => now()]);

        return ['groupId' => $groupId, 'others' => $others];
    }
}
