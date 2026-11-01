<?php

namespace App\Console\Commands;

use App\Services\Ai\PromptLock;
use Illuminate\Console\Command;

/** Xem / ghi khóa lời nhắc La bàn (đóng băng AI-7). */
class AiPrompts extends Command
{
    protected $signature = 'ai:prompts {--lock : ghi khóa mới vào database/ai/prompt_lock.json}';
    protected $description = 'Kiểm tra lời nhắc La bàn có khớp khóa (prompt_version) không; --lock để ghi khóa mới';

    public function handle(): int
    {
        if ($this->option('lock')) {
            PromptLock::write();
            $this->info('Đã ghi ' . PromptLock::PATH);
        }
        $rows = [];
        foreach (PromptLock::status() as $f => $s) {
            $rows[] = [$f, $s['version'], $s['lockedVersion'] ?? '—', substr($s['hash'], 0, 12), $s['state']];
        }
        $this->table(['Tính năng', 'prompt_version', 'Đã khóa', 'Vân tay', 'Trạng thái'], $rows);

        return self::SUCCESS;
    }
}
