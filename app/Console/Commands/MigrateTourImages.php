<?php

namespace App\Console\Commands;

use App\Services\TourImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Chuyển ảnh tour cũ (nằm trong source: public/admin|clients/assets/images/gallery-tours)
 * sang kho ảnh ngoài source: storage/app/public/tours/{tourId}/..., rồi cập nhật tbl_images.
 *
 * An toàn để chạy lại: dòng nào đã ở dạng mới (có "/") thì bỏ qua.
 * Mặc định KHÔNG xóa file cũ; xóa chỉ khi có cờ rõ ràng và không có lỗi.
 */
class MigrateTourImages extends Command
{
    protected $signature = 'media:migrate-tour-images
        {--dry-run : Chỉ báo cáo, không ghi file và không sửa DB}
        {--delete-legacy : Sau khi chuyển xong và không có lỗi, xóa các file cũ ĐÃ được chuyển}
        {--delete-orphans : Xóa luôn file cũ không có dòng nào trong tbl_images tham chiếu}
        {--force : Không hỏi xác nhận trước khi xóa}';

    protected $description = 'Chuyển ảnh tour từ public/*/gallery-tours sang storage/app/public/tours (WebP 320/800/1600)';

    /** Thư mục ảnh cũ (tính từ public_path) */
    private const LEGACY_DIRS = [
        'admin/assets/images/gallery-tours',
        'clients/assets/images/gallery-tours',
    ];

    public function handle(TourImageService $service): int
    {
        if (!Schema::hasTable('tbl_images')) {
            $this->error('Không thấy bảng tbl_images (đang trỏ nhầm database?).');

            return self::FAILURE;
        }

        foreach (['width', 'height', 'sizeBytes', 'sortOrder', 'isCover'] as $col) {
            if (!Schema::hasColumn('tbl_images', $col)) {
                $this->error("Thiếu cột tbl_images.{$col}. Hãy chạy `php artisan migrate` trước.");

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry-run');
        $rows = DB::table('tbl_images')->orderBy('tourId')->orderBy('imageId')->get();

        // Tên file cũ đang được DB tham chiếu (để biết file nào là mồ côi).
        $referencedLegacy = [];
        foreach ($rows as $row) {
            if (!$service->isStem($row->imageURL)) {
                $referencedLegacy[basename((string) $row->imageURL)] = true;
            }
        }

        $stats = ['total' => $rows->count(), 'already' => 0, 'migrated' => 0, 'missing' => 0, 'failed' => 0];
        $legacyBytes = 0;
        $newBytes = 0;
        $okNames = [];
        $failedNames = [];
        $missing = [];
        $order = [];

        foreach ($rows as $row) {
            $tourId = (int) $row->tourId;
            $position = $order[$tourId] = ($order[$tourId] ?? -1) + 1;

            if ($service->isStem($row->imageURL)) {
                $stats['already']++;
                continue;
            }

            $name = basename((string) $row->imageURL);   // basename: chặn "../" trong dữ liệu bẩn
            $path = $this->findLegacyFile($name);

            if ($path === null) {
                $stats['missing']++;
                $missing[] = "imageId={$row->imageId} tour={$tourId} file={$name}";
                continue;
            }

            $legacyBytes += filesize($path);

            if ($dry) {
                $stats['migrated']++;
                continue;
            }

            try {
                $meta = $service->storePath($path, $tourId);

                DB::table('tbl_images')->where('imageId', $row->imageId)->update([
                    'imageURL'  => $meta['stem'],
                    'width'     => $meta['width'],
                    'height'    => $meta['height'],
                    'sizeBytes' => $meta['sizeBytes'],
                    'sortOrder' => $position,
                    'isCover'   => $position === 0 ? 1 : 0,
                ]);

                $newBytes += $meta['sizeBytes'];
                $okNames[$name] = true;
                $stats['migrated']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $failedNames[$name] = true;
                $this->warn("  ✗ imageId={$row->imageId} ({$name}): {$e->getMessage()}");
            }
        }

        // ---- Báo cáo ----
        $this->newLine();
        $this->info($dry ? '=== DRY-RUN (chưa thay đổi gì) ===' : '=== KẾT QUẢ ===');
        $this->table(['Mục', 'Số lượng'], [
            ['Tổng dòng tbl_images', $stats['total']],
            ['Đã ở dạng mới (bỏ qua)', $stats['already']],
            [$dry ? 'Sẽ chuyển' : 'Đã chuyển', $stats['migrated']],
            ['Thiếu file trên đĩa', $stats['missing']],
            ['Lỗi', $stats['failed']],
        ]);

        $this->line('Dung lượng ảnh cũ được tham chiếu : ' . $this->mb($legacyBytes));
        if (!$dry) {
            $this->line('Dung lượng sau khi chuyển (3 cỡ WebP): ' . $this->mb($newBytes));
        }

        foreach (array_slice($missing, 0, 20) as $line) {
            $this->warn("  thiếu: {$line}");
        }
        if (count($missing) > 20) {
            $this->warn('  ... và ' . (count($missing) - 20) . ' dòng thiếu file khác.');
        }

        // ---- File mồ côi (có trên đĩa nhưng không dòng DB nào dùng) ----
        $orphans = [];
        $orphanBytes = 0;
        foreach (self::LEGACY_DIRS as $dir) {
            foreach (glob(public_path($dir) . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file) && !isset($referencedLegacy[basename($file)])) {
                    $orphans[] = $file;
                    $orphanBytes += filesize($file);
                }
            }
        }
        $this->line(sprintf('File cũ không được DB tham chiếu: %d file, %s', count($orphans), $this->mb($orphanBytes)));

        $tempCount = Schema::hasTable('tbl_temp_images') ? DB::table('tbl_temp_images')->count() : 0;
        if ($tempCount > 0) {
            $this->line("tbl_temp_images còn {$tempCount} dòng tạm (không bị đụng tới; nên dọn bằng media:cleanup-orphans).");
        }

        if ($dry) {
            $this->comment('Chạy lại không có --dry-run để thực hiện.');

            return self::SUCCESS;
        }

        // ---- Xóa file cũ (chỉ khi được yêu cầu và không có lỗi) ----
        $deleteLegacy = (bool) $this->option('delete-legacy');
        $deleteOrphans = (bool) $this->option('delete-orphans');

        if (($deleteLegacy || $deleteOrphans) && $stats['failed'] > 0) {
            $this->error('Có lỗi khi chuyển ảnh → KHÔNG xóa file cũ. Sửa lỗi rồi chạy lại.');

            return self::FAILURE;
        }

        if ($deleteLegacy || $deleteOrphans) {
            $toDelete = [];
            if ($deleteLegacy) {
                foreach (array_keys($okNames) as $name) {
                    if (isset($failedNames[$name])) {
                        continue;
                    }
                    foreach (self::LEGACY_DIRS as $dir) {
                        $file = public_path($dir . '/' . $name);
                        if (is_file($file)) {
                            $toDelete[] = $file;
                        }
                    }
                }
            }
            if ($deleteOrphans) {
                $toDelete = array_merge($toDelete, $orphans);
            }
            $toDelete = array_values(array_unique($toDelete));

            $bytes = array_sum(array_map('filesize', $toDelete));
            $this->warn(sprintf('Sắp xóa %d file cũ (%s). Hãy chắc chắn đã backup.', count($toDelete), $this->mb($bytes)));

            if ($this->option('force') || $this->confirm('Tiếp tục xóa?', false)) {
                foreach ($toDelete as $file) {
                    @unlink($file);
                }
                $this->info('Đã xóa ' . count($toDelete) . ' file cũ.');
            } else {
                $this->comment('Bỏ qua bước xóa.');
            }
        } else {
            $this->comment('File cũ vẫn còn nguyên. Sau khi kiểm tra website hiển thị đúng, chạy lại với --delete-legacy (và --delete-orphans nếu muốn).');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function findLegacyFile(string $name): ?string
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        foreach (self::LEGACY_DIRS as $dir) {
            $path = public_path($dir . '/' . $name);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function mb(int|float $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
}