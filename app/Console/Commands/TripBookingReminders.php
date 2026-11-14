<?php

namespace App\Console\Commands;

use App\Mail\TemplateMail;
use App\Services\Planner\BookingPlan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Nhắc đặt chỗ ngoài cho chuyến tự lên kế hoạch (không phải tour Travela):
 *  - việc "cần đặt" tới hạn (hạn đặt ≤ ngày mai) hoặc đã quá hạn;
 *  - chuyến khởi hành trong 3 ngày tới mà còn đêm chưa có chỗ ở / chưa có vé đi, về (người dùng chưa chọn "Không cần").
 * Mỗi chuyến tối đa một email mỗi ngày (khóa cache), chỉ gửi cho chủ chuyến.
 */
class TripBookingReminders extends Command
{
    protected $signature = 'trips:booking-reminders {--date= : Coi như hôm nay là ngày này} {--dry : Chỉ in ra, không gửi}';
    protected $description = 'Email nhắc chủ chuyến các việc đặt chỗ (khách sạn, vé) tới hạn hoặc còn thiếu';

    public function handle(BookingPlan $bp): int
    {
        if (!BookingPlan::ready()) {
            $this->warn('Cần chạy migrate.');

            return self::SUCCESS;
        }
        $today = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();
        $trips = DB::table('tbl_trips')->where('kind', 'trip')->where('status', '!=', 'done')->whereNotNull('startDate')
            ->whereBetween('startDate', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])->get();
        $sent = 0;
        foreach ($trips as $trip) {
            $plan = $bp->forTrip($trip);
            $due = array_values(array_filter($plan['planned'], fn($x) => !empty($x['needBy']) && Carbon::parse($x['needBy'])->lte($today->copy()->addDay())));
            $soon = Carbon::parse($trip->startDate)->diffInDays($today, true) <= 3;
            $gaps = $soon ? array_values(array_filter($plan['needs'], fn($n) => empty($n['plannedId']))) : [];
            if (!$due && !$gaps) {
                continue;
            }
            $owner = DB::table('tbl_users')->where('userId', $trip->userId)->first(['email', 'fullName']);
            if (!$owner || !filter_var($owner->email ?? '', FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $key = 'trip-book-remind:' . $trip->tripId . ':' . $today->toDateString();
            if (!$this->option('dry') && !Cache::add($key, 1, now()->addDay())) {
                continue;
            }
            $this->line("#{$trip->tripId} {$trip->title}: " . count($due) . ' tới hạn, ' . count($gaps) . ' còn thiếu');
            if (!$this->option('dry')) {
                Mail::to($owner->email)->queue(new TemplateMail('admin.emails.trip-booking-reminder', [
                    'name' => $owner->fullName, 'title' => $trip->title, 'start' => $trip->startDate, 'due' => $due, 'gaps' => $gaps,
                    'url' => route('planner.edit', ['id' => $trip->tripId]) . '#info',
                ], 'Nhắc đặt chỗ cho chuyến ' . $trip->title));
            }
            $sent++;
        }
        $this->info("Đã xếp {$sent} email nhắc đặt chỗ.");

        return self::SUCCESS;
    }
}
