<?php

namespace App\Console\Commands;

use App\Support\HtmlSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SanitizeTourHtml extends Command
{
    protected $signature = 'tours:sanitize-html {--dry-run : Chỉ đếm số dòng sẽ đổi, không ghi vào database}';
    protected $description = 'Làm sạch HTML trong mô tả tour và lịch trình đã lưu (L-E-13)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $targets = [
            ['tbl_tours', 'tourId'],
            ['tbl_timeline', 'timeLineId'],
        ];

        foreach ($targets as [$table, $pk]) {
            $changed = 0;

            DB::table($table)->select($pk, 'description')->orderBy($pk)->each(
                function ($row) use ($table, $pk, $dry, &$changed) {
                    $clean = HtmlSanitizer::clean($row->description);

                    if ($clean !== $row->description) {
                        $changed++;
                        if (!$dry) {
                            DB::table($table)->where($pk, $row->{$pk})->update(['description' => $clean]);
                        }
                    }
                }
            );

            $this->info("{$table}: " . ($dry ? 'sẽ đổi ' : 'đã đổi ') . "{$changed} dòng");
        }

        return self::SUCCESS;
    }
}
