<?php

namespace App\Console\Commands;

use Database\Seeders\DemoShowcaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đưa bộ demo về trạng thái chuẩn (Đợt D mục D5): xóa mọi thứ của các tài khoản demo (config/demo.php) rồi dựng lại.
 * Không đụng dữ liệu khác. Chạy trước mỗi lần trình bày, kèm `php artisan demo:check`.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : Không hỏi lại}';

    protected $description = 'Xóa dữ liệu của các tài khoản demo và dựng lại trạng thái demo chuẩn';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('Xóa toàn bộ chuyến đi, báo giá, tín hiệu của các tài khoản demo rồi dựng lại?', true)) {
            return self::FAILURE;
        }
        $t0 = microtime(true);
        $users = DB::table('tbl_users')->whereIn('username', array_column(array_filter((array) config('demo.accounts'), fn($a) => $a['type'] === 'user'), 'username'))->pluck('userId')->all();
        DB::transaction(function () use ($users) {
            $trips = $users ? DB::table('tbl_trips')->whereIn('userId', $users)->pluck('tripId')->all() : [];
            $del = function (string $table, string $col, array $ids) {
                if ($ids && Schema::hasTable($table) && Schema::hasColumn($table, $col)) {
                    DB::table($table)->whereIn($col, $ids)->delete();
                }
            };
            if (Schema::hasTable('tbl_tour_quotes')) {
                $quotes = DB::table('tbl_tour_quotes')->whereIn('tripId', $trips ?: [0])->orWhereIn('userId', $users ?: [0])->pluck('quoteId')->all();
                $del('tbl_tour_quote_messages', 'quoteId', $quotes);
                $del('tbl_tour_quotes', 'quoteId', $quotes);
            }
            if (Schema::hasTable('tbl_trip_polls')) {
                $polls = DB::table('tbl_trip_polls')->whereIn('tripId', $trips ?: [0])->pluck('pollId')->all();
                $del('tbl_trip_poll_votes', 'pollId', $polls);
                $del('tbl_trip_poll_options', 'pollId', $polls);
                $del('tbl_trip_polls', 'pollId', $polls);
            }
            foreach (['tbl_trip_items', 'tbl_trip_members', 'tbl_trip_external', 'tbl_trip_watch', 'tbl_trip_expenses', 'tbl_trip_revisions', 'tbl_trip_ai_cache', 'tbl_reco_impressions'] as $t) {
                $del($t, 'tripId', $trips);
            }
            $del('tbl_trip_members', 'userId', $users);
            $del('tbl_trips', 'tripId', $trips);
            $del('tbl_place_signals', 'userId', $users);
            $del('tbl_reco_impressions', 'userId', $users);
            $del('tbl_user_taste', 'userId', $users);
            $del('tbl_ai_runs', 'userId', $users);
            if (Schema::hasTable('tbl_import_batches')) {
                $b = DB::table('tbl_import_batches')->where('note', config('demo.import_note'))->pluck('batchId')->all();
                $del('tbl_import_rows', 'batchId', $b);
                $del('tbl_import_batches', 'batchId', $b);
            }
        });
        (new DemoShowcaseSeeder())->setContainer(app())->setCommand($this)->run();
        $this->info(sprintf('Đã dựng lại bộ demo trong %.1f giây. Mật khẩu chung: %s', microtime(true) - $t0, config('demo.password')));
        $this->table(['Tài khoản', 'Vai trò'], array_map(fn($a) => [$a['username'], $a['label']], (array) config('demo.accounts')));

        return self::SUCCESS;
    }
}
