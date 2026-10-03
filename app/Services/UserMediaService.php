<?php

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Lưu ảnh người dùng NGOÀI source code.
 *
 *  - AVATAR (công khai)  : disk "public"  → avatars/{userId}/{hash}.webp  (vuông, cắt giữa, 320x320)
 *  - AVATAR ADMIN        : disk "public"  → avatars/admin.webp
 *  - BIÊN LAI CHUYỂN KHOẢN (riêng tư): disk "local" (storage/app/private)
 *                          → transfer-proofs/{bookingId}/{hash}.webp (tối đa 1600px rộng)
 *
 * Giá trị lưu trong DB là đường dẫn tương đối có đuôi .webp.
 * Giá trị cũ (tên file trơn như "1735832355.jpg") là dạng legacy, vẫn hiển thị được
 * cho tới khi chạy `php artisan media:migrate-user-images`.
 */
class UserMediaService
{
    // ---------------------------------------------------------------- disks / quy ước

    public function publicDisk()
    {
        return Storage::disk(config('media.disk', 'public'));
    }

    public function proofDisk()
    {
        return Storage::disk(config('media.proof.disk', 'local'));
    }

    public function isAvatarPath(?string $value): bool
    {
        $dir = trim((string) config('media.avatar.dir', 'avatars'), '/');

        return is_string($value) && $value !== ''
            && str_starts_with($value, $dir . '/')
            && str_ends_with($value, '.webp')
            && !str_contains($value, '..') && !str_contains($value, '\\');
    }

    public function isProofPath(?string $value): bool
    {
        $dir = trim((string) config('media.proof.dir', 'transfer-proofs'), '/');

        return is_string($value) && $value !== ''
            && str_starts_with($value, $dir . '/')
            && str_ends_with($value, '.webp')
            && !str_contains($value, '..') && !str_contains($value, '\\');
    }

    // ---------------------------------------------------------------- AVATAR

    /** @return string đường dẫn tương đối để lưu vào tbl_users.avatar */
    public function storeAvatar(UploadedFile $file, int $userId): string
    {
        return $this->storeAvatarBinary($this->readUpload($file, (int) config('media.avatar.max_file_kb', 5120)), $userId);
    }

    public function storeAvatarBinary(string $binary, int $userId): string
    {
        $bytes = $this->encodeSquare($binary);
        $path = trim(config('media.avatar.dir', 'avatars'), '/') . "/{$userId}/" . substr(sha1($bytes), 0, 16) . '.webp';

        if (!$this->publicDisk()->exists($path) && !$this->publicDisk()->put($path, $bytes, 'public')) {
            throw new \RuntimeException("Không ghi được file avatar: {$path}");
        }

        return $path;
    }

    /** Ảnh đại diện của admin (một ảnh chung của site), ghi đè avatars/admin.webp. */
    public function storeAdminAvatar(UploadedFile $file): string
    {
        $bytes = $this->encodeSquare($this->readUpload($file, (int) config('media.avatar.max_file_kb', 5120)));
        $path = trim(config('media.avatar.dir', 'avatars'), '/') . '/admin.webp';

        if (!$this->publicDisk()->put($path, $bytes, 'public')) {
            throw new \RuntimeException('Không ghi được avatar admin.');
        }

        return $path;
    }

    /** Chỉ xóa file nếu là đường dẫn do hệ thống sinh ra; tên mặc định/legacy bị bỏ qua. */
    public function deleteAvatar(?string $value): void
    {
        if ($this->isAvatarPath($value) && $value !== trim(config('media.avatar.dir', 'avatars'), '/') . '/admin.webp') {
            $this->publicDisk()->delete($value);
        }
    }

    // ---------------------------------------------------------------- BIÊN LAI (riêng tư)

    /** @return string đường dẫn tương đối để lưu vào tbl_booking.transferProofImage */
    public function storeProof(UploadedFile $file, int $bookingId): string
    {
        return $this->storeProofBinary($this->readUpload($file, (int) config('media.proof.max_file_kb', 5120)), $bookingId);
    }

    public function storeProofBinary(string $binary, int $bookingId): string
    {
        $this->assertImageAllowed($binary);

        $image = Image::decodeBinary($binary);
        $image->scaleDown(width: (int) config('media.proof.max_width', 1600));
        $bytes = $image->encodeUsingFormat(Format::WEBP, quality: (int) config('media.proof.quality', 85), strip: true)->toString();

        $path = trim(config('media.proof.dir', 'transfer-proofs'), '/') . "/{$bookingId}/" . substr(sha1($bytes), 0, 16) . '.webp';

        if (!$this->proofDisk()->exists($path) && !$this->proofDisk()->put($path, $bytes)) {
            throw new \RuntimeException("Không ghi được biên lai: {$path}");
        }

        return $path;
    }

    public function deleteProof(?string $value): void
    {
        if ($this->isProofPath($value)) {
            $this->proofDisk()->delete($value);
        }
    }

    // ---------------------------------------------------------------- nội bộ

    private function readUpload(UploadedFile $file, int $maxKb): string
    {
        if (!$file->isValid()) {
            throw new InvalidImageException('File tải lên không hợp lệ hoặc bị gián đoạn.');
        }
        if ($file->getSize() > $maxKb * 1024) {
            throw new InvalidImageException("Ảnh vượt quá giới hạn {$maxKb} KB.");
        }
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw new InvalidImageException('Không đọc được file tải lên.');
        }

        return $binary;
    }

    private function encodeSquare(string $binary): string
    {
        $this->assertImageAllowed($binary);

        $size = (int) config('media.avatar.size', 320);
        $image = Image::decodeBinary($binary);
        $image->cover($size, $size);

        return $image->encodeUsingFormat(Format::WEBP, quality: (int) config('media.avatar.quality', 82), strip: true)->toString();
    }

    /** Kiểm tra MIME thật + kích thước điểm ảnh TRƯỚC khi giải mã (chống "ảnh bom"). */
    private function assertImageAllowed(string $binary): void
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if (!in_array($mime, (array) config('media.allowed_mimes'), true)) {
            throw new InvalidImageException('Chỉ chấp nhận ảnh JPEG, PNG hoặc WebP.');
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidImageException('Không đọc được kích thước ảnh (file hỏng?).');
        }

        [$w, $h] = $info;
        $maxSide = (int) config('media.limits.max_side', 8000);
        $maxPixels = (int) config('media.limits.max_pixels', 16000000);
        if (max($w, $h) > $maxSide || ($w * $h) > $maxPixels) {
            throw new InvalidImageException(
                "Ảnh quá lớn ({$w}×{$h}px). Giới hạn: cạnh dài tối đa {$maxSide}px, tối đa "
                . number_format($maxPixels / 1000000, 0) . ' megapixel.'
            );
        }
    }
}