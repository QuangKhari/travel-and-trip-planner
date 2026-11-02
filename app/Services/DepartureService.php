<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch khởi hành (tbl_tour_availability) — nguồn sự thật về số chỗ của từng ngày đi (A3 / D-02, đặc tả §6.3, §7.2).
 *
 * Số chỗ một ngày khởi hành:  còn lại = totalSlots − bookedSlots − heldSlots
 *   heldSlots   chỗ của đơn đang chờ (n)
 *   bookedSlots chỗ của đơn đã xác nhận / hoàn tất (y, f)
 * Mọi thay đổi chỗ là MỘT câu UPDATE có điều kiện (không đọc-rồi-ghi), thêm CHECK ở CSDL, nên hai người đặt
 * cùng lúc không thể bán vượt.
 *
 * tbl_tours.startDate / endDate / quantity được syncTour() ghi lại thành "chuyến gần nhất còn bán" để mọi
 * trang và truy vấn cũ (danh sách tour, lọc, trang chủ, Trip Planner) dùng tiếp mà không phải sửa.
 */
class DepartureService
{
    private static ?bool $ready = null;

    public static function enabled(): bool
    {
        return self::$ready ??= Schema::hasTable('tbl_tour_availability') && Schema::hasColumn('tbl_booking', 'availabilityId');
    }

    /** Số ngày của tour (cả ngày đầu và cuối). */
    public static function durationDays(object $tour): int
    {
        $d = (int) ($tour->durationDays ?? 0);
        if ($d <= 0 && !empty($tour->startDate) && !empty($tour->endDate)) {
            $d = Carbon::parse($tour->startDate)->diffInDays(Carbon::parse($tour->endDate), false) + 1;
        }

        return max(1, (int) $d);
    }

    public static function endDate(string $departureDate, int $days): string
    {
        return Carbon::parse($departureDate)->addDays(max(1, $days) - 1)->toDateString();
    }

    public static function remaining(object $a): int
    {
        return max(0, (int) $a->totalSlots - (int) $a->bookedSlots - (int) $a->heldSlots);
    }

    /** Tour chưa có dòng lịch khởi hành nào (dữ liệu cũ, tour vừa tạo bằng wizard): tạo 1 dòng từ startDate + quantity. */
    public function ensureForTour(int $tourId): void
    {
        if (!self::enabled() || DB::table('tbl_tour_availability')->where('tourId', $tourId)->exists()) {
            return;
        }
        $tour = DB::table('tbl_tours')->where('tourId', $tourId)->first(['tourId', 'startDate', 'quantity']);
        if (!$tour || !$tour->startDate) {
            return;
        }
        $people = fn(array $st) => (int) DB::table('tbl_booking')->where('tourId', $tourId)->whereNull('availabilityId')
            ->whereIn('bookingStatus', $st)->sum(DB::raw('numAdults + numChildren'));
        $held = $people(['n']);
        $booked = $people(['y', 'f']);
        $id = DB::table('tbl_tour_availability')->insertGetId([
            'tourId' => $tourId,
            'departureDate' => $tour->startDate,
            'totalSlots' => max(0, (int) $tour->quantity) + $held + $booked,
            'heldSlots' => $held,
            'bookedSlots' => $booked,
            'status' => 'open',
            'createdAt' => now(),
        ]);
        DB::table('tbl_booking')->where('tourId', $tourId)->whereNull('availabilityId')
            ->update(['availabilityId' => $id, 'departureDate' => $tour->startDate]);
    }

    /** Các ngày khởi hành của một tour (cũ → mới), kèm số chỗ còn lại và ngày về. */
    public function forTour(int $tourId, bool $onlyBookable = false): array
    {
        if (!self::enabled()) {
            return [];
        }
        $tour = DB::table('tbl_tours')->where('tourId', $tourId)->first();
        if (!$tour) {
            return [];
        }
        $days = self::durationDays($tour);
        $today = now()->toDateString();
        $q = DB::table('tbl_tour_availability')->where('tourId', $tourId)->orderBy('departureDate');
        if ($onlyBookable) {
            $q->where('status', 'open')->where('departureDate', '>', $today);
        }

        return $q->get()->map(function ($a) use ($days, $today) {
            $a->remaining = self::remaining($a);
            $a->endDate = self::endDate($a->departureDate, $days);
            $a->isPast = $a->departureDate <= $today;
            $a->bookable = $a->status === 'open' && !$a->isPast && $a->remaining > 0;
            $a->fill = $a->totalSlots > 0 ? round(($a->bookedSlots + $a->heldSlots) / $a->totalSlots * 100) : 0;

            return $a;
        })->all();
    }

    /**
     * Chọn và KHÓA ngày khởi hành cho một lần đặt (gọi trong transaction).
     * Không truyền id: lấy ngày trùng tbl_tours.startDate (chuyến đang hiển thị), không có thì chuyến mở gần nhất.
     * @throws BookingException
     */
    public function lockForBooking(object $tour, ?int $availabilityId): object
    {
        $this->ensureForTour((int) $tour->tourId);
        $q = DB::table('tbl_tour_availability')->where('tourId', $tour->tourId);
        if ($availabilityId) {
            $a = (clone $q)->where('availabilityId', $availabilityId)->lockForUpdate()->first();
            if (!$a) {
                throw new BookingException('Ngày khởi hành không thuộc tour này.');
            }
        } else {
            $a = (clone $q)->where('departureDate', $tour->startDate)->lockForUpdate()->first()
                ?? (clone $q)->where('status', 'open')->where('departureDate', '>', now()->toDateString())
                ->orderBy('departureDate')->lockForUpdate()->first();
            if (!$a) {
                throw new BookingException('Tour chưa có ngày khởi hành nào đang mở bán.');
            }
        }
        if ($a->status !== 'open') {
            throw new BookingException('Ngày khởi hành này đã ngừng nhận đặt.');
        }
        if ($a->departureDate <= now()->toDateString()) {
            throw new BookingException('Tour đã khởi hành, không thể đặt.');
        }

        return $a;
    }

    /** Khóa cache tên avail:{id} (Redis khi CACHE_STORE=redis), giữ tối đa 5 giây. */
    public const LOCK_SECONDS = 5;
    /** Số chỗ còn lại theo tour được cache ngắn cho API polling (C-12); xóa chủ động khi chỗ đổi. */
    public const SEATS_TTL = 15;

    public static function seatsKey(int $tourId): string
    {
        return "avail:tour:{$tourId}";
    }

    /**
     * B1: lớp giảm tranh chấp bằng Cache::lock("avail:{id}") quanh bước giữ chỗ. Lớp ĐÚNG ĐẮN vẫn là câu UPDATE có điều kiện
     * và khóa dòng trong MySQL (nguồn sự thật, N-12): chờ khóa quá 3 giây hoặc Redis mất kết nối thì vẫn chạy câu SQL.
     */
    private function guarded(int $availabilityId, callable $fn): mixed
    {
        try {
            return Cache::lock("avail:{$availabilityId}", self::LOCK_SECONDS)->block(3, $fn);
        } catch (LockTimeoutException) {
            return $fn();
        } catch (\Throwable $e) {
            if ($e instanceof BookingException) {
                throw $e;
            }
            report($e);

            return $fn();
        }
    }

    /** Xóa cache số chỗ của tour chứa ngày khởi hành này. */
    private function touched(int $availabilityId): void
    {
        $tourId = DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->value('tourId');
        if ($tourId) {
            Cache::forget(self::seatsKey((int) $tourId));
        }
    }

    /** Giữ chỗ cho đơn mới (n). Trả false nếu không đủ chỗ. */
    public function hold(int $availabilityId, int $people): bool
    {
        $ok = $this->guarded($availabilityId, fn() => DB::table('tbl_tour_availability')
            ->where('availabilityId', $availabilityId)
            ->whereRaw('totalSlots - bookedSlots - heldSlots >= ?', [$people])
            ->update(['heldSlots' => DB::raw('heldSlots + ' . (int) $people), 'updatedAt' => now()]) === 1);
        if ($ok) {
            $this->touched($availabilityId);
        }

        return $ok;
    }

    /** Bán thẳng chỗ (không qua giữ chỗ), vd tiền thanh toán trực tuyến về sau khi đơn đã hết hạn. Trả false nếu không đủ chỗ. */
    public function bookDirect(int $availabilityId, int $people): bool
    {
        $ok = $this->guarded($availabilityId, fn() => DB::table('tbl_tour_availability')
            ->where('availabilityId', $availabilityId)
            ->whereRaw('totalSlots - bookedSlots - heldSlots >= ?', [$people])
            ->update(['bookedSlots' => DB::raw('bookedSlots + ' . (int) $people), 'updatedAt' => now()]) === 1);
        if ($ok) {
            $this->touched($availabilityId);
        }

        return $ok;
    }

    /** Đơn n → y: chỗ đang giữ thành chỗ đã bán. */
    public function confirm(object $booking): void
    {
        if (!self::enabled() || empty($booking->availabilityId)) {
            return;
        }
        $n = (int) $booking->numAdults + (int) $booking->numChildren;
        DB::table('tbl_tour_availability')->where('availabilityId', $booking->availabilityId)->update([
            'heldSlots' => DB::raw('GREATEST(heldSlots - ' . $n . ', 0)'),
            'bookedSlots' => DB::raw('bookedSlots + ' . $n),
            'updatedAt' => now(),
        ]);
        $this->syncTour((int) $booking->tourId);
    }

    /**
     * Trả chỗ khi hủy / hết hạn. $booking là dòng TRƯỚC khi đổi trạng thái (để biết chỗ đang giữ hay đã bán).
     * Đơn cũ chưa gắn ngày khởi hành: cộng lại tbl_tours.quantity như trước.
     */
    public function release(object $booking): void
    {
        $n = (int) $booking->numAdults + (int) $booking->numChildren;
        if (!self::enabled() || empty($booking->availabilityId)) {
            DB::table('tbl_tours')->where('tourId', $booking->tourId)->increment('quantity', $n);

            return;
        }
        $col = $booking->bookingStatus === 'n' ? 'heldSlots' : 'bookedSlots';
        DB::table('tbl_tour_availability')->where('availabilityId', $booking->availabilityId)->update([
            $col => DB::raw("GREATEST({$col} - {$n}, 0)"),
            'updatedAt' => now(),
        ]);
        $this->syncTour((int) $booking->tourId);
    }

    /**
     * Ghi bản tóm tắt lên tbl_tours: chuyến mở gần nhất còn chỗ (không có thì chuyến mở gần nhất, rồi chuyến gần đây nhất).
     * startDate / endDate / quantity / durationDays là của chuyến đó.
     */
    public function syncTour(int $tourId): void
    {
        if (!self::enabled()) {
            return;
        }
        Cache::forget(self::seatsKey($tourId));
        $tour = DB::table('tbl_tours')->where('tourId', $tourId)->first();
        if (!$tour) {
            return;
        }
        $today = now()->toDateString();
        $base = DB::table('tbl_tour_availability')->where('tourId', $tourId);
        $pick = (clone $base)->where('status', 'open')->where('departureDate', '>', $today)
            ->whereRaw('totalSlots - bookedSlots - heldSlots > 0')->orderBy('departureDate')->first()
            ?? (clone $base)->where('status', 'open')->where('departureDate', '>', $today)->orderBy('departureDate')->first()
            ?? (clone $base)->orderByDesc('departureDate')->first();
        if (!$pick) {
            return;
        }
        $days = self::durationDays($tour);
        $open = $pick->status === 'open' && $pick->departureDate > $today;
        DB::table('tbl_tours')->where('tourId', $tourId)->update([
            'startDate' => $pick->departureDate,
            'endDate' => self::endDate($pick->departureDate, $days),
            'quantity' => $open ? self::remaining($pick) : 0,
        ]);
    }

    /* ------------------------------------------------------------------
     * Quản trị: thêm / sửa / đóng / xóa ngày khởi hành
     * ------------------------------------------------------------------ */

    /** @return array{ok:bool, message:string, id?:int} */
    public function add(int $tourId, string $date, int $slots, ?string $note = null): array
    {
        return DB::transaction(function () use ($tourId, $date, $slots, $note) {
            $tour = DB::table('tbl_tours')->where('tourId', $tourId)->lockForUpdate()->first();
            if (!$tour) {
                return ['ok' => false, 'message' => 'Không tìm thấy tour.'];
            }
            $this->ensureForTour($tourId);
            if ($date <= now()->toDateString()) {
                return ['ok' => false, 'message' => 'Ngày khởi hành phải sau hôm nay.'];
            }
            if ($slots < 1 || $slots > 500) {
                return ['ok' => false, 'message' => 'Số chỗ phải từ 1 đến 500.'];
            }
            if (DB::table('tbl_tour_availability')->where('tourId', $tourId)->where('departureDate', $date)->exists()) {
                return ['ok' => false, 'message' => 'Tour đã có ngày khởi hành ' . Carbon::parse($date)->format('d/m/Y') . '.'];
            }
            $id = DB::table('tbl_tour_availability')->insertGetId([
                'tourId' => $tourId, 'departureDate' => $date, 'totalSlots' => $slots,
                'status' => 'open', 'note' => $note ?: null, 'createdAt' => now(),
            ]);
            $this->syncTour($tourId);

            return ['ok' => true, 'message' => 'Đã thêm ngày khởi hành ' . Carbon::parse($date)->format('d/m/Y') . '.', 'id' => $id];
        });
    }

    /** Sửa tổng chỗ / ghi chú (và ngày, nếu chưa có đơn nào). @return array{ok:bool, message:string, before?:array, after?:array} */
    public function update(int $availabilityId, int $slots, ?string $date, ?string $note): array
    {
        return DB::transaction(function () use ($availabilityId, $slots, $date, $note) {
            $a = DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->lockForUpdate()->first();
            if (!$a) {
                return ['ok' => false, 'message' => 'Không tìm thấy ngày khởi hành.'];
            }
            $used = (int) $a->bookedSlots + (int) $a->heldSlots;
            if ($slots < max(1, $used)) {
                return ['ok' => false, 'message' => "Đã có {$used} chỗ được giữ / bán, tổng chỗ không thể nhỏ hơn số này."];
            }
            if ($slots > 500) {
                return ['ok' => false, 'message' => 'Tổng chỗ tối đa 500.'];
            }
            $data = ['totalSlots' => $slots, 'note' => $note ?: null, 'updatedAt' => now()];
            if ($date && $date !== $a->departureDate) {
                $hasBookings = DB::table('tbl_booking')->where('availabilityId', $availabilityId)->exists();
                if ($hasBookings) {
                    return ['ok' => false, 'message' => 'Ngày này đã có đơn đặt nên không đổi ngày được. Hãy thêm ngày mới và đóng ngày cũ.'];
                }
                if ($date <= now()->toDateString()) {
                    return ['ok' => false, 'message' => 'Ngày khởi hành phải sau hôm nay.'];
                }
                if (DB::table('tbl_tour_availability')->where('tourId', $a->tourId)->where('departureDate', $date)->exists()) {
                    return ['ok' => false, 'message' => 'Tour đã có ngày khởi hành này.'];
                }
                $data['departureDate'] = $date;
            }
            DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->update($data);
            $this->syncTour((int) $a->tourId);
            $after = (array) DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->first();

            return ['ok' => true, 'message' => 'Đã lưu ngày khởi hành.', 'before' => (array) $a, 'after' => $after, 'tourId' => (int) $a->tourId];
        });
    }

    /** Đóng / mở bán một ngày (đơn đã đặt vẫn giữ nguyên). */
    public function toggle(int $availabilityId): array
    {
        return DB::transaction(function () use ($availabilityId) {
            $a = DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->lockForUpdate()->first();
            if (!$a) {
                return ['ok' => false, 'message' => 'Không tìm thấy ngày khởi hành.'];
            }
            $to = $a->status === 'open' ? 'closed' : 'open';
            if ($to === 'open' && $a->departureDate <= now()->toDateString()) {
                return ['ok' => false, 'message' => 'Ngày khởi hành đã qua, không mở bán lại được.'];
            }
            DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->update(['status' => $to, 'updatedAt' => now()]);
            $this->syncTour((int) $a->tourId);

            return ['ok' => true, 'message' => $to === 'open' ? 'Đã mở bán lại ngày này.' : 'Đã ngừng nhận đặt cho ngày này.', 'status' => $to, 'tourId' => (int) $a->tourId, 'date' => $a->departureDate];
        });
    }

    /** Xóa ngày chưa có đơn nào (có đơn thì chỉ được đóng). */
    public function delete(int $availabilityId): array
    {
        return DB::transaction(function () use ($availabilityId) {
            $a = DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->lockForUpdate()->first();
            if (!$a) {
                return ['ok' => false, 'message' => 'Không tìm thấy ngày khởi hành.'];
            }
            if (DB::table('tbl_booking')->where('availabilityId', $availabilityId)->exists()) {
                return ['ok' => false, 'message' => 'Ngày này đã có đơn đặt nên không xóa được. Hãy chọn "Ngừng bán".'];
            }
            if (DB::table('tbl_tour_availability')->where('tourId', $a->tourId)->count() <= 1) {
                return ['ok' => false, 'message' => 'Tour phải còn ít nhất một ngày khởi hành.'];
            }
            DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->delete();
            $this->syncTour((int) $a->tourId);

            return ['ok' => true, 'message' => 'Đã xóa ngày khởi hành.', 'row' => (array) $a];
        });
    }

    /**
     * Sửa tour bằng form cũ (ngày đi / số chỗ còn lại): áp vào ngày khởi hành đang hiển thị để hai nơi luôn khớp.
     * Gọi trong transaction của updateTour, SAU khi tbl_tours đã có giá trị mới.
     */
    public function applyLegacyEdit(int $tourId, string $oldStart, string $newStart, int $remaining): void
    {
        if (!self::enabled()) {
            return;
        }
        $this->ensureForTour($tourId);
        $a = DB::table('tbl_tour_availability')->where('tourId', $tourId)->where('departureDate', $oldStart)->lockForUpdate()->first();
        if (!$a) {
            return;
        }
        $data = ['totalSlots' => max((int) $a->bookedSlots + (int) $a->heldSlots, (int) $a->bookedSlots + (int) $a->heldSlots + $remaining), 'updatedAt' => now()];
        if ($newStart !== $oldStart && !DB::table('tbl_tour_availability')->where('tourId', $tourId)->where('departureDate', $newStart)->exists()) {
            $data['departureDate'] = $newStart;
        }
        DB::table('tbl_tour_availability')->where('availabilityId', $a->availabilityId)->update($data);
        $this->syncTour($tourId);
    }
}
