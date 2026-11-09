<?php

namespace App\Services;

use App\Mail\TemplateMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Danh sách chờ khi lịch khởi hành hết chỗ.
 *   waiting  -> offered   (có chỗ trống: GIỮ chỗ cho người đầu hàng đủ chỗ, gửi email mời, hạn booking.waitlist_offer_hours)
 *   offered  -> converted (khách xác nhận, đặt tour thật trong CÙNG transaction: nhả chỗ giữ rồi BookingService::create giữ lại)
 *   offered  -> expired   (quá hạn: nhả chỗ, mời người kế tiếp) | cancelled (khách tự rút)
 * Thứ tự theo thời gian đăng ký; người đứng trước không đủ chỗ thì bỏ qua, mời người sau vừa chỗ (không để chỗ trống).
 */
class WaitlistService
{
    public const MAX_ACTIVE_PER_USER = 5;

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_waitlist');
    }

    private static function hours(): int
    {
        return max(1, (int) config('booking.waitlist_offer_hours', 12));
    }

    /**
     * Thứ tự khóa thống nhất với BookingService::create / điều chỉnh số khách: dòng tour -> lịch khởi hành -> dòng chờ.
     * Gọi TRONG transaction. @return object|null dòng lịch đã khóa
     */
    private function lockChain(int $availabilityId): ?object
    {
        $tourId = (int) DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->value('tourId');
        if ($tourId) {
            DB::table('tbl_tours')->where('tourId', $tourId)->lockForUpdate()->first();
        }

        return DB::table('tbl_tour_availability')->where('availabilityId', $availabilityId)->lockForUpdate()->first();
    }

    /** @return array{ok:bool, message:string, position?:int} */
    public function join(int $userId, int $availabilityId, int $adults, int $children): array
    {
        $people = $adults + $children;
        if ($adults < 1 || $children < 0 || $people > 50) {
            return ['ok' => false, 'message' => 'Số khách không hợp lệ (ít nhất 1 người lớn, tối đa 50 người).'];
        }

        return DB::transaction(function () use ($userId, $availabilityId, $adults, $children, $people) {
            $a = $this->lockChain($availabilityId);
            if (!$a || $a->status !== 'open' || $a->departureDate <= today()->toDateString()) {
                return ['ok' => false, 'message' => 'Ngày khởi hành này không còn nhận đăng ký.'];
            }
            if ($people > (int) $a->totalSlots) {
                return ['ok' => false, 'message' => 'Số khách vượt tổng số chỗ của đoàn này.'];
            }
            if (DepartureService::remaining($a) >= $people) {
                return ['ok' => false, 'message' => 'Ngày này còn đủ chỗ cho ' . $people . ' người, bạn có thể đặt tour ngay.'];
            }
            $mine = DB::table('tbl_waitlist')->where('userId', $userId)->whereIn('status', ['waiting', 'offered']);
            if ((clone $mine)->where('availabilityId', $availabilityId)->exists()) {
                return ['ok' => false, 'message' => 'Bạn đã đăng ký chờ cho ngày này.'];
            }
            if ($mine->count() >= self::MAX_ACTIVE_PER_USER) {
                return ['ok' => false, 'message' => 'Bạn đang chờ tối đa ' . self::MAX_ACTIVE_PER_USER . ' ngày khởi hành; hãy rút bớt trước khi đăng ký thêm.'];
            }
            DB::table('tbl_waitlist')->insert(['availabilityId' => $availabilityId, 'userId' => $userId, 'numAdults' => $adults, 'numChildren' => $children,
                'status' => 'waiting', 'createdAt' => now(), 'updatedAt' => now()]);
            $pos = (int) DB::table('tbl_waitlist')->where('availabilityId', $availabilityId)->where('status', 'waiting')->count();

            return ['ok' => true, 'message' => 'Đã đăng ký chờ. Khi có chỗ, Travela sẽ gửi email mời bạn xác nhận trong ' . self::hours() . ' giờ.', 'position' => $pos];
        });
    }

    /** Khách rút khỏi danh sách; đang được mời thì nhả chỗ giữ và mời người kế tiếp. */
    public function cancel(int $waitId, int $userId, bool $isAdmin = false): bool
    {
        $availabilityId = null;
        $ok = DB::transaction(function () use ($waitId, $userId, $isAdmin, &$availabilityId) {
            $pre = DB::table('tbl_waitlist')->where('waitId', $waitId)->value('availabilityId');
            if ($pre) {
                $this->lockChain((int) $pre);
            }
            $w = DB::table('tbl_waitlist')->where('waitId', $waitId)->lockForUpdate()->first();
            if (!$w || (!$isAdmin && (int) $w->userId !== $userId) || !in_array($w->status, ['waiting', 'offered'], true)) {
                return false;
            }
            if ($w->status === 'offered') {
                $this->releaseHold($w);
            }
            DB::table('tbl_waitlist')->where('waitId', $waitId)->update(['status' => 'cancelled', 'updatedAt' => now()]);
            $availabilityId = (int) $w->availabilityId;

            return true;
        });
        if ($ok && $availabilityId) {
            $this->offerNext($availabilityId);
        }

        return $ok;
    }

    private function releaseHold(object $w): void
    {
        $n = (int) $w->numAdults + (int) $w->numChildren;
        DB::table('tbl_tour_availability')->where('availabilityId', $w->availabilityId)->update(['heldSlots' => DB::raw('GREATEST(heldSlots - ' . $n . ', 0)'), 'updatedAt' => now()]);
        $tourId = (int) DB::table('tbl_tour_availability')->where('availabilityId', $w->availabilityId)->value('tourId');
        if ($tourId) {
            app(DepartureService::class)->syncTour($tourId);
        }
    }

    /** Mời những người chờ vừa chỗ trống hiện có. @return int số lời mời đã gửi */
    public function offerNext(int $availabilityId): int
    {
        $offers = [];
        DB::transaction(function () use ($availabilityId, &$offers) {
            $a = $this->lockChain($availabilityId);
            if (!$a || $a->status !== 'open' || $a->departureDate <= today()->toDateString()) {
                return;
            }
            // lời mời không kéo dài quá 0h ngày khởi hành; còn dưới 30 phút thì không mời nữa
            $expires = Carbon::now()->addHours(self::hours());
            $limit = Carbon::parse($a->departureDate)->startOfDay();
            if ($expires->gt($limit)) {
                $expires = $limit;
            }
            if ($expires->lt(Carbon::now()->addMinutes(30))) {
                return;
            }
            $rem = DepartureService::remaining($a);
            foreach (DB::table('tbl_waitlist')->where('availabilityId', $availabilityId)->where('status', 'waiting')->orderBy('createdAt')->orderBy('waitId')->lockForUpdate()->get() as $w) {
                $p = (int) $w->numAdults + (int) $w->numChildren;
                if ($rem <= 0) {
                    break;
                }
                if ($p > $rem || !app(DepartureService::class)->hold($availabilityId, $p)) {
                    continue;
                }
                $rem -= $p;
                $token = Str::random(40);
                DB::table('tbl_waitlist')->where('waitId', $w->waitId)->update(['status' => 'offered', 'offerToken' => $token, 'offeredAt' => now(), 'offerExpiresAt' => $expires, 'updatedAt' => now()]);
                $offers[] = [$w, $token, $expires, $a];
            }
        });
        foreach ($offers as [$w, $token, $expires, $a]) {
            $this->mail($w, 'offer', ['token' => $token, 'expires' => $expires, 'departureDate' => $a->departureDate, 'tourId' => $a->tourId]);
        }

        return count($offers);
    }

    /** Lời mời quá hạn: nhả chỗ giữ, đánh dấu expired, mời người kế tiếp. @return int số lời mời hết hạn */
    public function expireOffers(): int
    {
        $n = 0;
        $touched = [];
        foreach (DB::table('tbl_waitlist')->where('status', 'offered')->where('offerExpiresAt', '<', now())->pluck('waitId') as $id) {
            DB::transaction(function () use ($id, &$n, &$touched) {
                $pre = DB::table('tbl_waitlist')->where('waitId', $id)->value('availabilityId');
                if ($pre) {
                    $this->lockChain((int) $pre);
                }
                $w = DB::table('tbl_waitlist')->where('waitId', $id)->lockForUpdate()->first();
                if (!$w || $w->status !== 'offered' || Carbon::parse($w->offerExpiresAt)->gt(now())) {
                    return;
                }
                $this->releaseHold($w);
                DB::table('tbl_waitlist')->where('waitId', $id)->update(['status' => 'expired', 'updatedAt' => now()]);
                $touched[(int) $w->availabilityId] = true;
                $n++;
            });
        }
        foreach (array_keys($touched) as $av) {
            $this->offerNext($av);
        }

        return $n;
    }

    /** Lệnh waitlist:process: hết hạn lời mời cũ, rồi mời cho mọi lịch còn chỗ và còn người chờ. @return array{expired:int, offered:int} */
    public function processAll(): array
    {
        $expired = $this->expireOffers();
        $offered = 0;
        foreach (DB::table('tbl_waitlist')->where('status', 'waiting')->distinct()->pluck('availabilityId') as $av) {
            $offered += $this->offerNext((int) $av);
        }

        return ['expired' => $expired, 'offered' => $offered];
    }

    /** Lịch bị hủy / đóng: mọi người chờ hết hiệu lực (chỗ đang giữ đã được trả khi hủy đơn). */
    public function closeForAvailability(int $availabilityId): int
    {
        if (!self::ready()) {
            return 0;
        }
        $rows = DB::table('tbl_waitlist')->where('availabilityId', $availabilityId)->whereIn('status', ['waiting', 'offered'])->get();
        foreach ($rows as $w) {
            DB::table('tbl_waitlist')->where('waitId', $w->waitId)->update(['status' => 'expired', 'updatedAt' => now()]);
            $this->mail($w, 'closed', []);
        }

        return $rows->count();
    }

    public function findOffer(string $token, int $userId): ?object
    {
        return DB::table('tbl_waitlist as w')->join('tbl_tour_availability as a', 'a.availabilityId', '=', 'w.availabilityId')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')
            ->where('w.offerToken', $token)->where('w.userId', $userId)->where('w.status', 'offered')->where('w.offerExpiresAt', '>', now())
            ->first(['w.*', 'a.departureDate', 'a.tourId', 't.title', 't.priceAdult', 't.priceChild']);
    }

    /**
     * Khách xác nhận lời mời: trong MỘT transaction nhả chỗ đang giữ rồi đặt tour thật (BookingService::create giữ lại chỗ).
     * Đặt tour lỗi (BookingException) thì rollback toàn bộ, lời mời và chỗ giữ vẫn còn.
     * @param array $input fullName, email, tel, address, payment, couponCode (không cần tourId / availabilityId / số khách)
     * @return array kết quả BookingService::create
     */
    public function accept(string $token, int $userId, array $input, BookingService $bookings): array
    {
        return DB::transaction(function () use ($token, $userId, $input, $bookings) {
            $pre = DB::table('tbl_waitlist')->where('offerToken', $token)->where('userId', $userId)->value('availabilityId');
            if ($pre) {
                $this->lockChain((int) $pre);
            }
            $w = DB::table('tbl_waitlist')->where('offerToken', $token)->where('userId', $userId)->lockForUpdate()->first();
            if (!$w || $w->status !== 'offered' || Carbon::parse($w->offerExpiresAt)->lte(now())) {
                throw new BookingException('Lời mời không còn hiệu lực (đã hết hạn hoặc đã dùng).');
            }
            $tourId = (int) DB::table('tbl_tour_availability')->where('availabilityId', $w->availabilityId)->value('tourId');
            $this->releaseHold($w);
            $result = $bookings->create($userId, $input + ['tourId' => $tourId, 'availabilityId' => (int) $w->availabilityId,
                'numAdults' => (int) $w->numAdults, 'numChildren' => (int) $w->numChildren]);
            DB::table('tbl_waitlist')->where('waitId', $w->waitId)->update(['status' => 'converted', 'bookingId' => $result['bookingId'], 'updatedAt' => now()]);

            return $result;
        });
    }

    private function mail(object $w, string $event, array $data): void
    {
        try {
            $u = DB::table('tbl_users')->where('userId', $w->userId)->first(['email', 'fullName']);
            if (!$u || !filter_var($u->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $tour = DB::table('tbl_tour_availability as a')->join('tbl_tours as t', 't.tourId', '=', 'a.tourId')->where('a.availabilityId', $w->availabilityId)->first(['t.title', 'a.departureDate']);
            Mail::to($u->email)->queue(new TemplateMail('admin.emails.waitlist-offer', $data + [
                'event' => $event, 'name' => $u->fullName, 'tourTitle' => $tour->title ?? '', 'departureDate' => $tour->departureDate ?? null,
                'people' => (int) $w->numAdults + (int) $w->numChildren, 'hours' => self::hours(),
                'url' => $event === 'offer' ? route('waitlist.offer', ['token' => $data['token']]) : route('waitlist.index'),
            ], $event === 'offer' ? 'Travela — có chỗ cho bạn, xác nhận trong ' . self::hours() . ' giờ' : 'Travela — lịch khởi hành bạn chờ không còn mở'));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
