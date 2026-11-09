<?php

namespace App\Services;

use App\Mail\TemplateMail;
use App\Support\BookingPii;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Khiếu nại sau tour: chỉ khách của đơn ĐÃ ĐI xong (kết thúc chuyến), trong booking.complaint_days ngày sau khi kết thúc.
 * Quy trình: open -> investigating -> resolved | rejected -> closed. Bồi hoàn ghi sổ riêng ngay trong khiếu nại (không dùng tbl_refunds):
 * không vượt số tiền khách đã thanh toán; có thể quy lỗi cho nhà cung cấp để tính vào đánh giá nhà cung cấp.
 */
class ComplaintService
{
    public const CATEGORIES = ['service' => 'Dịch vụ không đúng mô tả', 'guide' => 'Hướng dẫn viên', 'hotel' => 'Khách sạn / lưu trú', 'transport' => 'Phương tiện / vận chuyển',
        'food' => 'Ăn uống', 'itinerary' => 'Lịch trình thay đổi / cắt giảm', 'safety' => 'An toàn', 'other' => 'Khác'];
    public const STATUSES = ['open' => 'Mới', 'investigating' => 'Đang xử lý', 'resolved' => 'Đã giải quyết', 'rejected' => 'Từ chối', 'closed' => 'Đã đóng'];
    public const FLOW = ['open' => ['investigating', 'resolved', 'rejected'], 'investigating' => ['resolved', 'rejected'], 'resolved' => ['closed'], 'rejected' => ['investigating', 'closed'], 'closed' => []];
    public const MAX_PER_BOOKING = 3;

    public static function ready(): bool
    {
        return Schema::hasTable('tbl_complaints');
    }

    public static function windowDays(): int
    {
        return max(1, (int) config('booking.complaint_days', 30));
    }

    /** Đơn đã đi xong và còn trong thời hạn khiếu nại. @return array{can:bool, why:string} */
    public function eligibility(object $booking): array
    {
        $no = fn (string $m) => ['can' => false, 'why' => $m];
        if (!self::ready()) {
            return $no('Tính năng chưa được kích hoạt.');
        }
        if (!in_array($booking->bookingStatus, ['y', 'f'], true)) {
            return $no('Chỉ khiếu nại được đơn đã xác nhận.');
        }
        $start = $booking->departureDate ?? null;
        if (!$start) {
            return $no('Đơn chưa có ngày khởi hành.');
        }
        $days = (int) (DB::table('tbl_tours')->where('tourId', $booking->tourId)->value('durationDays') ?: 1);
        $end = \Carbon\Carbon::parse($start)->addDays(max(0, $days - 1))->endOfDay();
        if ($end->isFuture()) {
            return $no('Bạn có thể gửi khiếu nại sau khi chuyến đi kết thúc.');
        }
        if ($end->copy()->addDays(self::windowDays())->isPast()) {
            return $no('Đã quá ' . self::windowDays() . ' ngày kể từ khi kết thúc chuyến đi.');
        }
        if (DB::table('tbl_complaints')->where('bookingId', $booking->bookingId)->count() >= self::MAX_PER_BOOKING) {
            return $no('Đơn đã đạt số khiếu nại tối đa (' . self::MAX_PER_BOOKING . ').');
        }

        return ['can' => true, 'why' => ''];
    }

    /** @return object tbl_complaints row */
    public function create(object $booking, int $userId, string $category, string $subject, string $body, ?int $claimed): object
    {
        return DB::transaction(function () use ($booking, $userId, $category, $subject, $body, $claimed) {
            $code = 'KN' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
            $id = DB::table('tbl_complaints')->insertGetId(['code' => $code, 'bookingId' => $booking->bookingId, 'userId' => $userId, 'category' => $category,
                'subject' => mb_substr($subject, 0, 160), 'status' => 'open', 'claimedAmount' => $claimed, 'createdAt' => now(), 'updatedAt' => now()]);
            DB::table('tbl_complaint_messages')->insert(['complaintId' => $id, 'authorType' => 'customer', 'authorId' => $userId, 'body' => $body, 'createdAt' => now()]);

            return DB::table('tbl_complaints')->where('complaintId', $id)->first();
        });
    }

    public function addMessage(int $complaintId, string $type, ?int $authorId, string $body): void
    {
        DB::table('tbl_complaint_messages')->insert(['complaintId' => $complaintId, 'authorType' => $type, 'authorId' => $authorId, 'body' => $body, 'createdAt' => now()]);
        DB::table('tbl_complaints')->where('complaintId', $complaintId)->update($type === 'staff' ? ['customerUnread' => true, 'updatedAt' => now()] : ['updatedAt' => now()]);
        if ($type === 'staff') {
            $this->mail($complaintId, 'reply', ['body' => $body]);
        }
    }

    /** @return array{ok:bool, message:string} */
    public function setStatus(int $complaintId, string $to, ?int $adminId, ?string $resolution): array
    {
        $res = DB::transaction(function () use ($complaintId, $to, $adminId, $resolution) {
            $c = DB::table('tbl_complaints')->where('complaintId', $complaintId)->lockForUpdate()->first();
            if (!$c || !in_array($to, self::FLOW[$c->status] ?? [], true)) {
                return ['ok' => false, 'message' => 'Không chuyển được sang trạng thái này.'];
            }
            if (in_array($to, ['resolved', 'rejected'], true) && mb_strlen(trim((string) $resolution)) < 10) {
                return ['ok' => false, 'message' => 'Phải ghi kết quả / lý do xử lý (tối thiểu 10 ký tự) để khách được biết.'];
            }
            if ($to === 'closed' && $c->compensationStatus === 'pending') {
                return ['ok' => false, 'message' => 'Còn khoản bồi hoàn chờ chi trả; chi trả xong mới đóng.'];
            }
            $upd = ['status' => $to, 'updatedAt' => now(), 'assignedTo' => $c->assignedTo ?: $adminId];
            if (in_array($to, ['resolved', 'rejected'], true)) {
                $upd += ['resolution' => trim($resolution), 'resolvedAt' => now(), 'customerUnread' => true];
            }
            DB::table('tbl_complaints')->where('complaintId', $complaintId)->update($upd);

            return ['ok' => true, 'message' => 'Đã cập nhật trạng thái.'];
        });
        if ($res['ok'] && in_array($to, ['resolved', 'rejected'], true)) {
            $this->mail($complaintId, $to, ['body' => trim((string) $resolution)]);
        }

        return $res;
    }

    /** Đặt khoản bồi hoàn (chờ chi trả). Không vượt số tiền khách đã thanh toán của đơn. @return array{ok:bool,message:string} */
    public function setCompensation(int $complaintId, int $amount, ?int $supplierId): array
    {
        return DB::transaction(function () use ($complaintId, $amount, $supplierId) {
            $c = DB::table('tbl_complaints')->where('complaintId', $complaintId)->lockForUpdate()->first();
            if (!$c || $c->status === 'closed') {
                return ['ok' => false, 'message' => 'Khiếu nại đã đóng hoặc không tồn tại.'];
            }
            if ($c->compensationStatus === 'paid') {
                return ['ok' => false, 'message' => 'Đã chi trả bồi hoàn, không đổi được.'];
            }
            $paid = (int) round((float) DB::table('tbl_checkout')->where('bookingId', $c->bookingId)->value('amount'));
            if ($amount < 0 || $amount > $paid) {
                return ['ok' => false, 'message' => 'Bồi hoàn phải từ 0 đến số tiền khách đã thanh toán (' . number_format($paid, 0, ',', '.') . ' đ).'];
            }
            DB::table('tbl_complaints')->where('complaintId', $complaintId)->update(['compensationAmount' => $amount, 'compensationStatus' => $amount > 0 ? 'pending' : 'none',
                'supplierId' => $supplierId ?: $c->supplierId, 'updatedAt' => now()]);

            return ['ok' => true, 'message' => $amount > 0 ? 'Đã đặt khoản bồi hoàn, chờ chi trả.' : 'Đã bỏ khoản bồi hoàn.'];
        });
    }

    /** Ghi nhận đã chi trả bồi hoàn. @return array{ok:bool,message:string} */
    public function markCompensationPaid(int $complaintId, int $adminId, string $method, ?string $reference): array
    {
        $res = DB::transaction(function () use ($complaintId, $adminId, $method, $reference) {
            $c = DB::table('tbl_complaints')->where('complaintId', $complaintId)->lockForUpdate()->first();
            if (!$c || $c->compensationStatus !== 'pending') {
                return ['ok' => false, 'message' => 'Không có khoản bồi hoàn chờ chi trả.'];
            }
            if ($reference !== null && $reference !== '' && (
                DB::table('tbl_complaints')->where('complaintId', '!=', $complaintId)->where('compensationMethod', mb_substr($method, 0, 40))->where('compensationRef', mb_substr($reference, 0, 80))->exists()
                || (Schema::hasTable('tbl_refunds') && DB::table('tbl_refunds')->where('method', mb_substr($method, 0, 40))->where('reference', mb_substr($reference, 0, 80))->exists())
                || (Schema::hasTable('tbl_booking_adjustments') && DB::table('tbl_booking_adjustments')->where('method', mb_substr($method, 0, 40))->where('reference', mb_substr($reference, 0, 80))->exists())
            )) {
                return ['ok' => false, 'message' => 'Mã giao dịch này đã được dùng cho một khoản khác: kiểm tra lại để tránh chi trùng.'];
            }
            DB::table('tbl_complaints')->where('complaintId', $complaintId)->update(['compensationStatus' => 'paid', 'compensationMethod' => mb_substr($method, 0, 40),
                'compensationRef' => $reference ? mb_substr($reference, 0, 80) : null, 'compensationPaidAt' => now(), 'compensationBy' => $adminId, 'customerUnread' => true, 'updatedAt' => now()]);

            return ['ok' => true, 'message' => 'Đã ghi nhận chi trả bồi hoàn.', 'amount' => (int) $c->compensationAmount];
        });
        if ($res['ok']) {
            $this->mail($complaintId, 'paid', ['amount' => $res['amount']]);
        }

        return $res;
    }

    private function mail(int $complaintId, string $event, array $data): void
    {
        try {
            $c = DB::table('tbl_complaints as c')->join('tbl_booking as b', 'b.bookingId', '=', 'c.bookingId')->where('c.complaintId', $complaintId)->first(['c.*', 'b.email', 'b.fullName', 'b.bookingCode']);
            $c = $c ? BookingPii::decrypt($c) : null;
            if ($c && filter_var($c->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($c->email)->queue(new TemplateMail('admin.emails.complaint-update', ['c' => $c, 'event' => $event, 'd' => $data, 'url' => route('complaint.show', ['code' => $c->code])],
                    'Travela — cập nhật khiếu nại ' . $c->code));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
