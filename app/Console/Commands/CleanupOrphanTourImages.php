<?php

namespace App\Console\Commands;

use App\Services\TourImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn file ảnh "mồ côi": có trong storage/app/public/tours nhưng không dòng tbl_images nào dùng.
 *
 * Nguyên tắc an toàn:
 *  - Không bao giờ xóa file đang được tbl_images tham chiếu.
 *  - Chỉ xóa file cũ hơn N giờ (mặc định 24h) để không xóa ảnh vừa upload trong wizard chưa Hoàn tất.
 *  - Nếu tbl_images rỗng mà trên đĩa có ảnh → dừng (nghi trỏ nhầm DB), trừ khi có --force-empty.
 *  - Ghi log mọi file bị xóa.
 */
class CleanupOrphanTourImages extends Command
{
    protected $signature = 'media:cleanup-orphans
        {--dry-run : Chỉ liệt kê, không xóa}
        {--min-age= : Chỉ xóa file cũ hơn số giờ này (mặc định lấy từ config media.orphan_min_age_hours)}
        {--force-empty : Cho phép chạy dù tbl_images đang rỗng}';

    protected $description = 'Dọn ảnh tour mồ côi trong storage và dòng tbl_temp_images quá hạn';

    public function handle(TourImageService $service): int
    {
        if (!Schema::hasTable('tbl_images')) {
            $this->error('Không thấy bảng tbl_images.');

            return self::FAILURE;
        }

        $minAge = $this->option('min-age') !== null
            ? max(0, (int) $this->option('min-age'))
            : (int) config('media.orphan_min_age_hours', 24);
        $cutoff = now()->subHours($minAge)->getTimestamp();
        $dry = (bool) $this->option('dry-run');

        $referenced = [];
        foreach (DB::table('tbl_images')->pluck('imageURL') as $value) {
            if ($service->isStem($value)) {
                $referenced[$value] = true;
            }
        }

        $stored = $service->listStoredStems();

        if ($referenced === [] && $stored !== [] && !$this->option('force-empty')) {
            $this->error('tbl_images không có ảnh nào ở dạng mới nhưng trên đĩa có ' . count($stored)
                . ' ảnh. Nghi đang trỏ nhầm database hoặc chưa chạy media:migrate-tour-images. Dừng để an toàn.');

            return self::FAILURE;
        }

        $orphans = [];
        $recent = 0;
        $bytes = 0;
        foreach ($stored as $stem => $info) {
            if (isset($referenced[$stem])) {
                continue;
            }
            if ($info['mtime'] > $cutoff) {
                $recent++;
                continue;
            }
            $orphans[$stem] = $info;
            $bytes += $info['bytes'];
        }

        $this->line(sprintf(
            'Trên đĩa: %d ảnh | được tham chiếu: %d | mồ côi cũ hơn %dh: %d (%s) | mồ côi còn mới (giữ lại): %d',
            count($stored),
            count($stored) - count($orphans) - $recent,
            $minAge,
            count($orphans),
            number_format($bytes / 1048576, 1) . ' MB',
            $recent
        ));

        foreach ($orphans as $stem => $info) {
            $this->line(($dry ? '  [dry-run] ' : '  xóa ') . $stem);
            if (!$dry) {
                $service->disk()->delete($info['files']);
                Log::info('media:cleanup-orphans deleted', ['stem' => $stem, 'files' => $info['files']]);
            }
        }

        // Dòng tạm của wizard bị bỏ dở.
        if (Schema::hasTable('tbl_temp_images')) {
            $stale = DB::table('tbl_temp_images')
                ->where('uploadDate', '<', now()->subHours($minAge))
                ->whereNotIn('imageTempURL', array_keys($referenced) ?: [''])
                ->count();
            $this->line("tbl_temp_images quá hạn và không còn dùng: {$stale} dòng");
            if (!$dry && $stale > 0) {
                DB::table('tbl_temp_images')
                    ->where('uploadDate', '<', now()->subHours($minAge))
                    ->whereNotIn('imageTempURL', array_keys($referenced) ?: [''])
                    ->delete();
            }
        }

        // Thư mục tour rỗng.
        if (!$dry) {
            foreach ($service->disk()->directories($service->toursDir()) as $dir) {
                if ($service->disk()->allFiles($dir) === []) {
                    $service->disk()->deleteDirectory($dir);
                }
            }
        }

        return self::SUCCESS;
    }
}