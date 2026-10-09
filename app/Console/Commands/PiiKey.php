<?php

namespace App\Console\Commands;

use App\Support\AesGcm;
use Illuminate\Console\Command;

class PiiKey extends Command
{
    protected $signature = 'pii:key';
    protected $description = 'Sinh khóa AES-256-GCM mới';

    public function handle(): int
    {
        $this->line('DATA_ENCRYPTION_KEY=' . AesGcm::generateKey());
        return self::SUCCESS;
    }
}
