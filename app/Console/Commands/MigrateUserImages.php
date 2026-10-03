<?php

namespace App\Console\Commands;

use App\Services\UserMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Chuyển avatar (public/admin/assets/images/user-profile) và biên lai chuyển khoản
 * (public/clients/assets/images/transfer-proofs) ra khỏi source:
 *   avatar  → storage/app/public/avatars/{userId}/{hash}.webp
 *   biên lai → storage/app/private/transfer-proofs/{bookingId}/{hash}.webp  (riêng tư)
 *
 * Chạy lại an toàn. Mặc định KHÔNG xóa file cũ. Ba ảnh mặc định trong source
 * (unnamed.png, user_avatar.jpg, avt_admin.jpg) luôn được giữ lại.
 */
class MigrateUserImages extends Command
{
    protected $signature = 'media:migrate-user-images
        {--dry-run : Chỉ báo cáo, không ghi file và không sửa DB}
        {--delete-legacy : Sau khi chuyển xong và không lỗi, xóa các file cũ ĐÃ được chuyển}
        {--delete-orphans : Xóa luôn file cũ không dòng DB nào dùng (trừ ảnh mặc định)}
        {--force : Không hỏi xác nhận trước khi xóa}';

    protected $description = 'Chuyển avatar và biên lai chuyển khoản từ public/ sang storage';

    private const AVATAR_DIR = 'admin/assets/images/user-profile';
    private const PROOF_DIR = 'clients/assets/images/transfer-proofs';
    private const DEFAULT_AVATARS = ['unnamed.png', 'user_avatar.jpg', 'avt_admin.jpg'];

    public function handle(UserMediaService $media): int
    {
        foreach (['tbl_users', 'tbl_booking'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Không thấy bảng {$table} (đang trỏ nhầm database?).");

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry-run');
        $stats = ['avatar' => ['total' => 0, 'migrated' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0],
                  'proof'  => ['total' => 0, 'migrated' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0]];
        $migratedFiles = [];   // [đường dẫn tuyệt đối => true]
        $failedFiles = [];
        $referenced = [];      // tên file legacy còn được DB tham chiếu
        $missing = [];

        // ---------- AVATAR ----------
        foreach (DB::table('tbl_users')->select('userId', 'avatar')->get() as $u) {
            $stats['avatar']['total']++;
            $value = (string) $u->avatar;

            if ($value === '' || $media->isAvatarPath($value) || in_array(basename($value), self::DEFAULT_AVATARS, true)) {
                $stats['avatar']['skipped']++;
                continue;
            }

            $name = basename($value);
            $referenced[$name] = true;
            $file = public_path(self::AVATAR_DIR . '/' . $name);

            if (!is_file($file)) {
                $stats['avatar']['missing']++;
                $missing[] = "avatar userId={$u->userId} file={$name}";
                continue;
            }
            if ($dry) {
                $stats['avatar']['migrated']++;
                continue;
            }

            try {
                $path = $media->storeAvatarBinary(file_get_contents($file), (int) $u->userId);
                DB::table('tbl_users')->where('userId', $u->userId)->update(['avatar' => $path]);
                $migratedFiles[$file] = true;
                $stats['avatar']['migrated']++;
            } catch (Throwable $e) {
                $stats['avatar']['failed']++;
                $failedFiles[$file] = true;
                $this->warn("  ✗ avatar userId={$u->userId} ({$name}): {$e->getMessage()}");
            }
        }

        // ---------- BIÊN LAI ----------
        $referencedProofs = [];
        foreach (DB::table('tbl_booking')->select('bookingId', 'transferProofImage')->whereNotNull('transferProofImage')->get() as $b) {
            $stats['proof']['total']++;
            $value = (string) $b->transferProofImage;

            if ($value === '' || $media->isProofPath($value)) {
                $stats['proof']['skipped']++;
                continue;
            }

            $name = basename($value);
            $referencedProofs[$name] = true;
            $file = public_path(self::PROOF_DIR . '/' . $name);

            if (!is_file($file)) {
                $stats['proof']['missing']++;
                $missing[] = "biên lai bookingId={$b->bookingId} file={$name}";
                continue;
            }
            if ($dry) {
                $stats['proof']['migrated']++;
                continue;
            }

            try {
                $path = $media->storeProofBinary(file_get_contents($file), (int) $b->bookingId);
                DB::table('tbl_booking')->where('bookingId', $b->bookingId)->update(['transferProofImage' => $path]);
                $migratedFiles[$file] = true;
                $stats['proof']['migrated']++;
            } catch (Throwable $e) {
                $stats['proof']['failed']++;
                $failedFiles[$file] = true;
                $this->warn("  ✗ biên lai bookingId={$b->bookingId} ({$name}): {$e->getMessage()}");
            }
        }

        // ---------- Báo cáo ----------
        $this->newLine();
        $this->info($dry ? '=== DRY-RUN (chưa thay đổi gì) ===' : '=== KẾT QUẢ ===');
        $rows = [];
        foreach (['avatar' => 'Avatar', 'proof' => 'Biên lai'] as $k => $label) {
            $s = $stats[$k];
            $rows[] = [$label, $s['total'], $s['skipped'], $s['migrated'], $s['missing'], $s['failed']];
        }
        $this->table(['Loại', 'Tổng dòng', 'Bỏ qua (đã mới/mặc định/trống)', $dry ? 'Sẽ chuyển' : 'Đã chuyển', 'Thiếu file', 'Lỗi'], $rows);

        foreach (array_slice($missing, 0, 20) as $m) {
            $this->warn("  thiếu: {$m}");
        }

        // ---------- File không được DB dùng ----------
        $orphans = [];
        $bytes = 0;
        foreach (glob(public_path(self::AVATAR_DIR) . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            if (is_file($f) && !in_array(basename($f), self::DEFAULT_AVATARS, true) && !isset($referenced[basename($f)]) && !isset($migratedFiles[$f])) {
                $orphans[] = $f;
                $bytes += filesize($f);
            }
        }
        foreach (glob(public_path(self::PROOF_DIR) . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            if (is_file($f) && !isset($referencedProofs[basename($f)]) && !isset($migratedFiles[$f])) {
                $orphans[] = $f;
                $bytes += filesize($f);
            }
        }
        $this->line(sprintf('File cũ không dòng DB nào dùng (không tính ảnh mặc định): %d file, %s', count($orphans), number_format($bytes / 1048576, 2) . ' MB'));

        if ($dry) {
            $this->comment('Chạy lại không có --dry-run để thực hiện.');

            return self::SUCCESS;
        }

        // ---------- Xóa file cũ ----------
        $failed = $stats['avatar']['failed'] + $stats['proof']['failed'];
        $deleteLegacy = (bool) $this->option('delete-legacy');
        $deleteOrphans = (bool) $this->option('delete-orphans');

        if (!$deleteLegacy && !$deleteOrphans) {
            $this->comment('File cũ vẫn còn nguyên. Sau khi kiểm tra web, chạy lại với --delete-legacy (và --delete-orphans nếu muốn).');

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }
        if ($failed > 0) {
            $this->error('Có lỗi khi chuyển → KHÔNG xóa file cũ. Sửa lỗi rồi chạy lại.');

            return self::FAILURE;
        }

        $toDelete = [];
        if ($deleteLegacy) {
            $toDelete = array_keys($migratedFiles);
        }
        if ($deleteOrphans) {
            $toDelete = array_merge($toDelete, $orphans);
        }
        $toDelete = array_values(array_unique($toDelete));

        $this->warn(sprintf('Sắp xóa %d file cũ. Hãy chắc chắn đã backup.', count($toDelete)));
        if ($this->option('force') || $this->confirm('Tiếp tục xóa?', false)) {
            foreach ($toDelete as $f) {
                @unlink($f);
            }
            $this->info('Đã xóa ' . count($toDelete) . ' file cũ.');
        } else {
            $this->comment('Bỏ qua bước xóa.');
        }

        return self::SUCCESS;
    }
}