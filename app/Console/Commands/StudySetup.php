<?php

namespace App\Console\Commands;

use App\Services\Study\StudySession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Đợt F (F1): chuẩn bị một vòng thử.
 *   php artisan study:setup --round=1 --n=8          tạo thu101…thu108 (mã t1-01…), bạn đồng hành, chuyến Đà Lạt lỗi cho từng người
 *   php artisan study:setup --reset=t1-03            xóa kết quả của một người để làm lại (giữ tài khoản)
 * Chạy `php artisan demo:reset --force` trước để có chuyến Đà Lạt mẫu (nguồn của nhiệm vụ 3).
 */
class StudySetup extends Command
{
    protected $signature = 'study:setup {--round=1} {--n=8 : số người thử} {--reset= : mã người thử cần làm lại}';

    protected $description = 'Tạo tài khoản và dữ liệu cho một vòng thử người dùng';

    public function handle(): int
    {
        if (!StudySession::ready()) {
            $this->error('Chưa có bảng buổi thử. Chạy php artisan migrate.');
            return self::FAILURE;
        }
        if ($code = $this->option('reset')) {
            $p = DB::table('tbl_study_participants')->where('code', $code)->first();
            if (!$p) {
                $this->error("Không có người thử $code.");
                return self::FAILURE;
            }
            StudySession::resetParticipant($p);
            $this->info("Đã xóa kết quả của $code. Chuyến Đà Lạt (thử) giữ nguyên; muốn có lại chuyến lỗi thì xóa chuyến đó rồi chạy lại study:setup cho vòng này.");
            return self::SUCCESS;
        }
        $round = max(1, min(9, (int) $this->option('round')));
        $n = max(1, min(40, (int) $this->option('n')));
        $rows = StudySession::setup($round, $n);
        $this->table(['Mã', 'Tài khoản', 'Chuyến cho nhiệm vụ 3'], array_map(fn($r) => [strtoupper($r['code']), $r['username'], $r['fixTripId'] ? '#' . $r['fixTripId'] : 'THIẾU (chạy demo:reset trước)'], $rows));
        $this->line('Mật khẩu chung: ' . config('study.password') . ' · Bạn đồng hành để mời: ' . config('study.companion.email'));
        if (!config('study.enabled')) {
            $this->warn('Thanh nhiệm vụ đang tắt: đặt STUDY_ENABLED=true trong .env (và php artisan config:clear) trong lúc thử.');
        }
        $this->line('Sau buổi thử: php artisan study:report --round=' . $round . ' --md=docs/so-lieu/buoi-thu-vong-' . $round . '.md');

        return self::SUCCESS;
    }
}
