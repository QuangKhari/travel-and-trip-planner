<?php

namespace App\Console\Commands;

use App\Services\DepartureGroupingService;
use App\Support\AdminBadges;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phát hiện lịch khởi hành sắp thiếu khách: còn <= N ngày (booking.shortfall_flag_days, mặc định 10) mà số khách đã xác nhận
 * ít hơn minParticipants (và đã có khách). Gắn cờ tbl_tour_availability.shortfallFlaggedAt để màn "Xử lý tour thiếu khách",
 * chấm vàng ở menu và dashboard cảnh báo. Cờ tự gỡ khi đủ khách, lịch đóng / qua ngày.
 *
 * Lịch nằm trong đoàn ghép KHÔNG bị xét riêng lẻ: cả đoàn chạy chung nên xét theo TỔNG khách của các lịch thành viên so với mức
 * tối thiểu CAO NHẤT trong các lịch (DepartureGroupingService::groupStats), cờ đặt ở tbl_departure_groups.shortfallFlaggedAt.
 * Chạy hằng ngày (routes/console.php); chạy tay: php artisan departures:check-shortfall --days=14
 */
class CheckDepartureShortfall extends Command
{
    protected $signature = 'departures:check-shortfall {--days= : Ghi đè số ngày trước khởi hành}';
    protected $description = 'Gắn cờ các lịch khởi hành và đoàn ghép sắp thiếu khách tối thiểu';

    public function handle(DepartureGroupingService $grouping): int
    {
        if (!Schema::hasColumn('tbl_tour_availability', 'shortfallFlaggedAt')) {
            $this->warn('Chưa chạy migration 2026_11_27_000001.');
            return self::FAILURE;
        }
        $days = max(1, (int) ($this->option('days') ?: config('booking.shortfall_flag_days', 10)));
        $today = today()->toDateString();
        $limit = today()->addDays($days)->toDateString();
        $hasGroups = Schema::hasTable('tbl_departure_group_members');
        $grouped = $hasGroups ? DB::table('tbl_departure_group_members')->select('availabilityId') : null;

        // 1. Lịch riêng lẻ
        $due = DB::table('tbl_tour_availability')
            ->where('status', 'open')->where('departureDate', '>', $today)->where('departureDate', '<=', $limit)
            ->where('bookedSlots', '>', 0)->whereColumn('bookedSlots', '<', 'minParticipants')
            ->whereNull('shortfallFlaggedAt');
        if ($grouped) {
            $due->whereNotIn('availabilityId', $grouped);
        }
        $ids = $due->pluck('availabilityId')->all();
        $flagged = $ids === [] ? 0 : DB::table('tbl_tour_availability')->whereIn('availabilityId', $ids)->whereNull('shortfallFlaggedAt')->update(['shortfallFlaggedAt' => now()]);
        foreach ($ids as $id) {
            Log::warning('Lịch khởi hành sắp thiếu khách', ['availabilityId' => $id]);
        }

        $cleared = DB::table('tbl_tour_availability')->whereNotNull('shortfallFlaggedAt')->where(function ($q) use ($today) {
            $q->where('status', '!=', 'open')->orWhere('departureDate', '<=', $today)
                ->orWhereColumn('bookedSlots', '>=', 'minParticipants')->orWhere('bookedSlots', '<=', 0);
        })->update(['shortfallFlaggedAt' => null]);
        if ($grouped) {
            // lịch đã vào đoàn thì không mang cờ riêng (xét theo đoàn)
            $cleared += DB::table('tbl_tour_availability')->whereNotNull('shortfallFlaggedAt')->whereIn('availabilityId', DB::table('tbl_departure_group_members')->select('availabilityId'))
                ->update(['shortfallFlaggedAt' => null]);
        }

        // 2. Đoàn ghép: tổng khách cả đoàn so với mức tối thiểu của đoàn
        $groupFlagged = 0;
        $groupCleared = 0;
        if ($hasGroups && Schema::hasColumn('tbl_departure_groups', 'shortfallFlaggedAt')) {
            $candidates = DB::table('tbl_departure_groups')->where('status', 'active')->where('departureDate', '>', $today)
                ->where('departureDate', '<=', $limit)->whereNull('shortfallFlaggedAt')->pluck('groupId');
            foreach ($candidates as $gid) {
                $st = $grouping->groupStats((int) $gid);
                if ($st['open'] > 0 && $st['booked'] > 0 && $st['booked'] < $st['minimum']) {
                    $groupFlagged += DB::table('tbl_departure_groups')->where('groupId', $gid)->whereNull('shortfallFlaggedAt')->update(['shortfallFlaggedAt' => now()]);
                    Log::warning('Đoàn ghép sắp thiếu khách', ['groupId' => $gid] + $st);
                }
            }
            foreach (DB::table('tbl_departure_groups')->whereNotNull('shortfallFlaggedAt')->get(['groupId', 'status', 'departureDate']) as $g) {
                $st = $grouping->groupStats((int) $g->groupId);
                $stale = $g->status !== 'active' || $g->departureDate <= $today || $st['open'] === 0 || $st['booked'] <= 0 || $st['booked'] >= $st['minimum'];
                if ($stale) {
                    $groupCleared += DB::table('tbl_departure_groups')->where('groupId', $g->groupId)->update(['shortfallFlaggedAt' => null]);
                }
            }
        }

        if ($flagged + $cleared + $groupFlagged + $groupCleared > 0) {
            AdminBadges::forget();
        }
        $this->info("Lịch: gắn cờ {$flagged}, gỡ {$cleared} · Đoàn ghép: gắn cờ {$groupFlagged}, gỡ {$groupCleared} (ngưỡng {$days} ngày)");

        return self::SUCCESS;
    }
}
