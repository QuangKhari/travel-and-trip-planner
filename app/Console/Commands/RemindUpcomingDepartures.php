<?php

namespace App\Console\Commands;

use App\Mail\TemplateMail;
use App\Models\admin\BookingModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * C-10: email nhắc khách 3 ngày và 1 ngày trước khởi hành (đơn đã xác nhận, chưa hủy).
 * Mỗi mốc ghi một dòng tbl_booking_reminders (UNIQUE bookingId + daysBefore) nên chạy lại không gửi trùng.
 * Lịch: mỗi ngày 08:00. Dùng --date=YYYY-MM-DD để chạy thử cho một ngày khác.
 */
class RemindUpcomingDepartures extends Command
{
    protected $signature = 'bookings:remind-departure {--date= : Coi như hôm nay là ngày này}';
    protected $description = 'Gửi email nhắc lịch 3 ngày và 1 ngày trước ngày khởi hành';

    public const MARKS = [3, 1];

    public function handle(): int
    {
        $today = $this->option('date') ? \Carbon\Carbon::parse($this->option('date'))->startOfDay() : today();
        $model = new BookingModel();
        $sent = 0;

        foreach (self::MARKS as $days) {
            $date = $today->copy()->addDays($days)->toDateString();
            $ids = DB::table('tbl_booking as b')
                ->join('tbl_tours as t', 't.tourId', '=', 'b.tourId')
                ->where('b.bookingStatus', 'y')
                ->whereRaw('DATE(COALESCE(b.departureDate, t.startDate)) = ?', [$date])
                ->whereNotExists(fn($q) => $q->selectRaw('1')->from('tbl_booking_reminders as r')
                    ->whereColumn('r.bookingId', 'b.bookingId')->where('r.daysBefore', $days))
                ->pluck('b.bookingId');

            foreach ($ids as $id) {
                // giữ chỗ dòng nhắc trước khi gửi: hai tiến trình chạy song song không gửi hai lần
                $claimed = DB::table('tbl_booking_reminders')->insertOrIgnore(['bookingId' => $id, 'daysBefore' => $days, 'sentAt' => now()]);
                if (!$claimed) {
                    continue;
                }
                $b = $model->getInvoiceBooking($id);
                if (!$b || !filter_var($b->email ?? '', FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $subject = 'Nhắc lịch: ' . $b->title . ($days <= 1 ? ' khởi hành ngày mai' : " khởi hành sau {$days} ngày");
                Mail::to($b->email)->queue(new TemplateMail('admin.emails.booking-status', ['b' => $b, 'event' => 'reminder', 'refund' => null, 'days' => $days], $subject));
                $sent++;
            }
        }
        $this->info("Đã xếp {$sent} email nhắc lịch.");

        return self::SUCCESS;
    }
}
