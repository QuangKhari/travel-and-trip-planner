<?php

namespace App\Console\Commands;

use App\Services\TourImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn ảnh tour "mồ côi" và tour nháp bỏ dở.
 *
 * Thứ tự (L-E-20, sửa lỗi "tbl_temp_images quá hạn luôn 0 dòng"):
 *   1. Tour nháp bỏ dở: availability = 0, CHƯA từng đăng (không có dòng tbl_images), không có đơn / đánh giá / lịch sử,
 *      tạo trước N ngày (media.draft_max_age_days) -> xóa tour, lộ trình, ảnh tạm (L-E-08).
 *   2. Dòng tbl_temp_images quá hạn: cũ hơn N giờ (media.orphan_min_age_hours) -> xóa dòng. Ảnh tạm CHỈ được coi là
 *      "đang dùng" khi còn mới; trước đây mọi ảnh tạm đều được coi là đang dùng nên bước này không bao giờ xóa gì.
 *   3. File ảnh không còn dòng nào tham chiếu (tbl_images hoặc ảnh tạm còn mới) và cũ hơn N giờ -> xóa file.
 * An toàn: không bao giờ xóa file tbl_images đang dùng; tbl_images rỗng mà đĩa có ảnh thì dừng (nghi trỏ nhầm DB).
 */
class CleanupOrphanTourImages extends Command
{
    protected $signature = 'media:cleanup-orphans
        {--dry-run : Chỉ liệt kê, không xóa}
        {--min-age= : Ảnh / ảnh tạm cũ hơn số giờ này mới bị dọn (mặc định config media.orphan_min_age_hours)}
        {--draft-days= : Tour nháp bỏ dở cũ hơn số ngày này bị xóa (mặc định config media.draft_max_age_days)}
        {--keep-drafts : Không xóa tour nháp}
        {--force-empty : Cho phép chạy dù tbl_images đang rỗng}';

    protected $description = 'Dọn ảnh tour mồ côi, dòng tbl_temp_images quá hạn và tour nháp bỏ dở';

    public function handle(TourImageService $service): int
    {
        return Cache::lock('tour-image-global-quota', 60)->block(10, function () use ($service) {
            if (!Schema::hasTable('tbl_images')) {
                $this->error('Không thấy bảng tbl_images.');

                return self::FAILURE;
            }
            $minAge = $this->option('min-age') !== null ? max(0, (int) $this->option('min-age')) : (int) config('media.orphan_min_age_hours', 24);
            $draftDays = $this->option('draft-days') !== null ? max(1, (int) $this->option('draft-days')) : (int) config('media.draft_max_age_days', 7);
            $cutoff = now()->subHours($minAge);
            $dry = (bool) $this->option('dry-run');
            $hasTemp = Schema::hasTable('tbl_temp_images');

            // ---- 1. tour nháp bỏ dở
            $drafts = [];
            if (!$this->option('keep-drafts') && Schema::hasColumn('tbl_tours', 'createdAt')) {
                $drafts = DB::table('tbl_tours as t')->where('t.availability', 0)
                    ->where('t.createdAt', '<', now()->subDays($draftDays))
                    ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('tbl_images as i')->whereColumn('i.tourId', 't.tourId'))
                    ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('tbl_booking as b')->whereColumn('b.tourId', 't.tourId'))
                    ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('tbl_reviews as r')->whereColumn('r.tourId', 't.tourId'))
                    ->when(Schema::hasTable('tbl_history'), fn($q) => $q->whereNotExists(fn($w) => $w->select(DB::raw(1))->from('tbl_history as h')->whereColumn('h.tourId', 't.tourId')))
                    ->pluck('t.title', 't.tourId')->all();
            }
            $this->line('Tour nháp bỏ dở (chưa đăng, không đơn, tạo trước ' . $draftDays . ' ngày): ' . count($drafts));
            foreach ($drafts as $id => $title) {
                $this->line(($dry ? '  [dry-run] ' : '  xóa tour nháp ') . '#' . $id . ' ' . $title);
                if (!$dry) {
                    DB::transaction(function () use ($id) {
                        DB::table('tbl_timeline')->where('tourId', $id)->delete();
                        DB::table('tbl_temp_images')->where('tourId', $id)->delete();
                        DB::table('tbl_tours')->where('tourId', $id)->where('availability', 0)->delete();
                    });
                    $service->deleteTourDirectory((int) $id);
                    Log::info('media:cleanup-orphans deleted draft tour', ['tourId' => $id]);
                }
            }

            // ---- 2. dòng ảnh tạm quá hạn (wizard bỏ dở). Tour đã đăng không còn ảnh tạm (addTimeline chuyển hết sang tbl_images).
            $stale = 0;
            if ($hasTemp) {
                $staleQ = DB::table('tbl_temp_images')->where('uploadDate', '<', $cutoff)
                    ->when($drafts, fn($q) => $q->whereNotIn('tourId', array_keys($drafts)));
                $stale = (clone $staleQ)->count();
                $this->line("tbl_temp_images quá hạn ({$minAge}h): {$stale} dòng");
                if (!$dry && $stale > 0) {
                    $staleQ->delete();
                }
            }

            // ---- 3. file mồ côi
            $referenced = [];
            foreach (DB::table('tbl_images')->pluck('imageURL') as $value) {
                if ($service->isStem($value)) {
                    $referenced[$value] = true;
                }
            }
            $fromImages = count($referenced);
            if ($hasTemp) {
                // chỉ ảnh tạm CÒN MỚI mới được giữ (ảnh tạm quá hạn vừa bị coi là bỏ dở ở bước 2, kể cả khi chạy --dry-run)
                foreach (DB::table('tbl_temp_images')->where('uploadDate', '>=', $cutoff)->when($drafts, fn($q) => $q->whereNotIn('tourId', array_keys($drafts)))->pluck('imageTempURL') as $value) {
                    if ($service->isStem($value)) {
                        $referenced[$value] = true;
                    }
                }
            }
            $stored = $service->listStoredStems();
            if ($fromImages === 0 && $stored !== [] && !$this->option('force-empty')) {
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
                if ($info['mtime'] > $cutoff->getTimestamp()) {
                    $recent++;
                    continue;
                }
                $orphans[$stem] = $info;
                $bytes += $info['bytes'];
            }
            $this->line(sprintf('Trên đĩa: %d ảnh | đang dùng: %d | mồ côi cũ hơn %dh: %d (%s) | mồ côi còn mới (giữ lại): %d',
                count($stored), count($stored) - count($orphans) - $recent, $minAge, count($orphans), number_format($bytes / 1048576, 1) . ' MB', $recent));
            foreach ($orphans as $stem => $info) {
                $this->line(($dry ? '  [dry-run] ' : '  xóa ') . $stem);
                if (!$dry) {
                    $service->disk()->delete($info['files']);
                    Log::info('media:cleanup-orphans deleted', ['stem' => $stem, 'files' => $info['files']]);
                }
            }

            if (!$dry) {
                foreach ($service->disk()->directories($service->toursDir()) as $dir) {
                    if ($service->disk()->allFiles($dir) === []) {
                        $service->disk()->deleteDirectory($dir);
                    }
                }
            }

            return self::SUCCESS;
        });
    }
}
