<?php

namespace App\Console\Commands;

use App\Support\AesGcm;
use App\Support\BookingPii;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EncryptExistingPii extends Command
{
    protected $signature = 'pii:encrypt-existing {--dry-run}';
    protected $description = 'Mã hóa AES-GCM các đơn đặt tour cũ';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $done = 0;

        DB::table('tbl_booking')->orderBy('bookingId')->chunkById(200, function ($rows) use ($dry, &$done) {
            foreach ($rows as $row) {
                $plain = [];
                foreach (BookingPii::FIELDS as $f) {
                    if (is_string($row->$f) && $row->$f !== '' && !AesGcm::isEncrypted($row->$f)) {
                        $plain[$f] = $row->$f;
                    }
                }
                if (!$plain) continue;

                if (!$dry) {
                    DB::table('tbl_booking')->where('bookingId', $row->bookingId)->update(BookingPii::encrypt($plain));
                }
                $done++;
            }
        }, 'bookingId');

        $this->info(($dry ? '[dry-run] Sẽ mã hóa ' : 'Đã mã hóa ') . "{$done} đơn.");
        return self::SUCCESS;
    }
}
