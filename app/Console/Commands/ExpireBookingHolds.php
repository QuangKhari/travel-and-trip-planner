<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Hủy đơn chờ xác nhận quá hạn giữ chỗ, trả chỗ và nhả mã giảm giá. */
class ExpireBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Hủy các đơn chờ xác nhận đã quá hạn giữ chỗ';

    public function handle(BookingService $bookings): int
    {
        $count = $bookings->expireHolds();

        if ($count > 0) {
            Log::info("bookings:expire-holds đã hủy {$count} đơn quá hạn giữ chỗ.");
        }

        $this->info("Đã hủy {$count} đơn quá hạn.");

        return self::SUCCESS;
    }
}
