<?php

namespace App\Console\Commands;

use App\Services\BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Hoàn tất đơn đã xác nhận + đã thanh toán của tour đã kết thúc, để khách đánh giá được (L-F-11). */
class CompleteFinishedBookings extends Command
{
    protected $signature = 'bookings:complete-finished';
    protected $description = 'Chuyển đơn đã thanh toán của tour đã kết thúc sang Hoàn tất';

    public function handle(BookingService $bookings): int
    {
        $n = $bookings->completeFinished();
        if ($n > 0) {
            Log::info("bookings:complete-finished đã hoàn tất {$n} đơn.");
        }
        $this->info("Đã hoàn tất {$n} đơn.");

        return self::SUCCESS;
    }
}
