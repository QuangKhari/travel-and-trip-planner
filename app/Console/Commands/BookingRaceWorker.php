<?php

namespace App\Console\Commands;

use App\Services\BookingException;
use App\Services\BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Tiến trình con của bookings:race-test. Không chạy tay. */
class BookingRaceWorker extends Command
{
    protected $signature = 'bookings:race-worker {tourId} {userId} {people} {startAt : unix time (có phần lẻ) để bắt đầu}';

    protected $description = 'Nội bộ: một người đặt tour trong bài test tranh slot';

    protected $hidden = true;

    public function handle(BookingService $bookings): int
    {
        // Chờ tới đúng thời điểm chung để các tiến trình lao vào DB cùng lúc
        while (microtime(true) < (float) $this->argument('startAt')) {
            usleep(200);
        }

        try {
            $r = $bookings->create((int) $this->argument('userId'), [
                'tourId'       => (int) $this->argument('tourId'),
                'fullName'     => 'RACE-TEST',
                'email'        => 'race@test.local',
                'tel'          => '0900000000',
                'address'      => 'race test',
                'numAdults'    => (int) $this->argument('people'),
                'numChildren'  => 0,
                'couponCode'   => '',
                'requestToken' => (string) Str::uuid(),
            ]);

            $this->line(json_encode(['ok' => true, 'bookingId' => $r['bookingId']]));
        } catch (BookingException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
