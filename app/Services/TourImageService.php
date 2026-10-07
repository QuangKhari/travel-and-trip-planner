<?php

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Lưu ảnh tour NGOÀI source code.
 *
 * Quy ước lưu trữ (disk "public" → storage/app/public):
 *   tours/{tourId}/{stem}-320.webp
 *   tours/{tourId}/{stem}-800.webp
 *   tours/{tourId}/{stem}-1600.webp
 *
 * - "stem" = tours/{tourId}/{24 ký tự sha1 của bản lớn nhất}; đây là giá trị duy nhất
 *   được ghi vào tbl_images.imageURL (đường dẫn tương đối, không có host, không có đuôi).
 * - Tên file sinh từ nội dung (hash) → upload trùng ảnh không tạo thêm file, và có thể
 *   cache trình duyệt vô thời hạn.
 * - KHÔNG giữ ảnh gốc. Mọi ảnh được giải mã, bỏ EXIF, tái mã hóa WebP.
 * - Không bao giờ upscale: ảnh nhỏ hơn 800/1600px sẽ có bản 800/1600 cùng kích thước thật,
 *   để mọi stem luôn đủ 3 file (view không phải kiểm tra file tồn tại).
 */
class TourImageService
{
    /** @return \Illuminate\Contracts\Filesystem\Filesystem */
    public function disk()
    {
        return Storage::disk(config('media.disk'));
    }

    public function toursDir(): string
    {
        return trim((string) config('media.tours_dir', 'tours'), '/');
    }

    public function tourDir(int $tourId): string
    {
        return $this->toursDir() . '/' . $tourId;
    }

    /** @return int[] các cỡ, tăng dần */
    public function sizes(): array
    {
        $sizes = array_map('intval', (array) config('media.sizes', [320, 800, 1600]));
        sort($sizes);

        return $sizes;
    }

    /** Giá trị trong DB có phải đường dẫn kiểu mới (tours/{id}/{hash}) không? */
    public function isStem(?string $value): bool
    {
        return is_string($value)
            && $value !== ''
            && str_starts_with($value, $this->toursDir() . '/')
            && !str_contains($value, '..')
            && !str_contains($value, '\\');
    }

    public function isStemForTour(?string $value, int $tourId): bool
    {
        if (!$this->isStem($value)) {
            return false;
        }

        $pattern = '#^' . preg_quote($this->toursDir(), '#') . '/' . $tourId . '/[a-f0-9]{24}$#';

        return preg_match($pattern, $value) === 1;
    }

    /** @return string[] đường dẫn các file của một stem */
    public function variantPaths(string $stem): array
    {
        return array_map(fn(int $w) => "{$stem}-{$w}.webp", $this->sizes());
    }

    // ---------------------------------------------------------------------
    // LƯU
    // ---------------------------------------------------------------------

    /**
     * Lưu file upload từ request (Dropzone/form).
     *
     * @return array{stem:string,width:int,height:int,sizeBytes:int}
     * @throws InvalidImageException khi file không đạt chính sách
     */
    public function storeUploaded(UploadedFile $file, int $tourId): array
    {
        if (!$file->isValid()) {
            throw new InvalidImageException('File tải lên không hợp lệ hoặc bị gián đoạn.');
        }

        $maxKb = (int) config('media.limits.max_file_kb', 8192);
        if ($file->getSize() > $maxKb * 1024) {
            throw new InvalidImageException("Ảnh vượt quá giới hạn {$maxKb} KB.");
        }

        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            throw new InvalidImageException('Không đọc được file tải lên.');
        }

        return $this->storeBinary($binary, $tourId);
    }

    /**
     * Lưu từ một file có sẵn trên đĩa (dùng cho lệnh di chuyển ảnh cũ).
     *
     * @return array{stem:string,width:int,height:int,sizeBytes:int}
     */
    public function storePath(string $absolutePath, int $tourId): array
    {
        $binary = is_file($absolutePath) ? file_get_contents($absolutePath) : false;
        if ($binary === false) {
            throw new InvalidImageException("Không đọc được file: {$absolutePath}");
        }

        return $this->storeBinary($binary, $tourId);
    }

    /**
     * @return array{stem:string,width:int,height:int,sizeBytes:int}
     */
    public function storeBinary(string $binary, int $tourId): array
    {
        $this->assertImageAllowed($binary);

        $variants = $this->encodeVariants($binary);          // [width => [bytes,width,height]]
        $largest = $variants[max(array_keys($variants))];

        $stem = $this->tourDir($tourId) . '/' . substr(sha1($largest['bytes']), 0, 24);
        $paths = $this->variantPaths($stem);

        $alreadyStored = $this->disk()->exists($paths[array_key_last($paths)]);

        if (!$alreadyStored) {
            $this->assertQuota($tourId, strlen($largest['bytes']));
            $this->writeVariants($stem, $variants);
        }

        return [
            'stem'      => $stem,
            'width'     => $largest['width'],
            'height'    => $largest['height'],
            'sizeBytes' => array_sum(array_map(fn($v) => strlen($v['bytes']), $variants)),
        ];
    }

    /** @param array<int,array{bytes:string,width:int,height:int}> $variants */
    private function writeVariants(string $stem, array $variants): void
    {
        $written = [];
        try {
            foreach ($variants as $width => $variant) {
                $path = "{$stem}-{$width}.webp";
                if (!$this->disk()->put($path, $variant['bytes'], 'public')) {
                    throw new \RuntimeException("Không ghi được file ảnh: {$path}");
                }
                $written[] = $path;
            }
        } catch (\Throwable $e) {
            // Không để lại nửa chừng: xóa những file đã ghi rồi ném lỗi tiếp.
            $this->disk()->delete($written);
            throw $e;
        }
    }

    /**
     * Giải mã một lần, bỏ EXIF, sinh các cỡ giảm dần (không upscale).
     * Xử lý từ lớn xuống nhỏ trên cùng một đối tượng để chỉ giữ một bản ảnh trong RAM.
     *
     * @return array<int,array{bytes:string,width:int,height:int}>
     */
    private function encodeVariants(string $binary): array
    {
        $image = Image::decodeBinary($binary);   // tự xoay theo EXIF (autoOrientation mặc định bật)
        $quality = (int) config('media.webp_quality', 80);

        $out = [];
        foreach (array_reverse($this->sizes()) as $width) {
            $image->scaleDown(width: $width);    // giữ tỷ lệ, không phóng to
            $encoded = $image->encodeUsingFormat(Format::WEBP, quality: $quality, strip: true);
            $out[$width] = [
                'bytes'  => $encoded->toString(),
                'width'  => $image->width(),
                'height' => $image->height(),
            ];
        }
        ksort($out);

        return $out;
    }

    // ---------------------------------------------------------------------
    // KIỂM TRA / QUOTA
    // ---------------------------------------------------------------------

    /** Kiểm tra MIME thật + kích thước điểm ảnh TRƯỚC khi giải mã (chống ảnh bom). */
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
                "Ảnh quá lớn ({$w}×{$h}px). Giới hạn: cạnh dài tối đa {$maxSide}px, tổng tối đa "
                    . number_format($maxPixels / 1000000, 0) . ' megapixel. Hãy giảm kích thước rồi tải lại.'
            );
        }
    }

    public function assertQuota(int $tourId, int $incomingBytes = 0): void
    {
        $maxImages = (int) config('media.limits.max_images_per_tour', 12);
        if ($this->countStems($tourId) >= $maxImages) {
            throw new InvalidImageException("Mỗi tour chỉ được tối đa {$maxImages} ảnh.");
        }

        $maxBytes = (int) config('media.limits.max_total_mb', 2048) * 1048576;
        if ($this->totalBytes() + $incomingBytes * 2 > $maxBytes) {
            throw new InvalidImageException('Kho ảnh đã đầy (vượt quota). Liên hệ quản trị hệ thống.');
        }
    }

    /** Số ảnh (stem) đang có trong thư mục của một tour, kể cả ảnh tạm của wizard. */
    public function countStems(int $tourId): int
    {
        $stems = [];
        foreach ($this->disk()->files($this->tourDir($tourId)) as $file) {
            if (preg_match('#^(.+)-\d+\.webp$#', $file, $m)) {
                $stems[$m[1]] = true;
            }
        }

        return count($stems);
    }

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->disk()->allFiles($this->toursDir()) as $file) {
            $total += (int) $this->disk()->size($file);
        }

        return $total;
    }

    // ---------------------------------------------------------------------
    // XÓA / DỌN
    // ---------------------------------------------------------------------

    /** Xóa vật lý các file của một stem. Chỉ gọi SAU KHI transaction DB đã thành công. */
    public function delete(?string $stem): void
    {
        if (!$this->isStem($stem)) {
            return; // tên cũ kiểu legacy hoặc giá trị lạ: không đụng tới
        }
        $this->disk()->delete($this->variantPaths($stem));
    }

    /** @param iterable<string|null> $stems */
    public function deleteMany(iterable $stems): void
    {
        foreach ($stems as $stem) {
            $this->delete($stem);
        }
    }

    public function deleteTourDirectory(int $tourId): void
    {
        $this->disk()->deleteDirectory($this->tourDir($tourId));
    }

    /**
     * Liệt kê mọi stem đang nằm trên đĩa.
     *
     * @return array<string,array{files:string[],bytes:int,mtime:int}>
     */
    public function listStoredStems(): array
    {
        $groups = [];
        foreach ($this->disk()->allFiles($this->toursDir()) as $file) {
            if (!preg_match('#^(.+)-\d+\.webp$#', $file, $m)) {
                continue;
            }
            $stem = $m[1];
            $groups[$stem]['files'][] = $file;
            $groups[$stem]['bytes'] = ($groups[$stem]['bytes'] ?? 0) + (int) $this->disk()->size($file);
            $groups[$stem]['mtime'] = max($groups[$stem]['mtime'] ?? 0, (int) $this->disk()->lastModified($file));
        }

        return $groups;
    }

    // ---------------------------------------------------------------------
    // SIÊU DỮ LIỆU
    // ---------------------------------------------------------------------

    /**
     * Đọc kích thước/dung lượng của một stem đã lưu (dùng khi chuyển ảnh tạm → tbl_images).
     *
     * @return array{width:?int,height:?int,sizeBytes:?int}
     */
    public function describe(string $stem): array
    {
        if (!$this->isStem($stem)) {
            return ['width' => null, 'height' => null, 'sizeBytes' => null];
        }

        $bytes = 0;
        foreach ($this->variantPaths($stem) as $path) {
            if ($this->disk()->exists($path)) {
                $bytes += (int) $this->disk()->size($path);
            }
        }

        $width = $height = null;
        $largest = "{$stem}-" . max($this->sizes()) . '.webp';
        if ($this->disk()->exists($largest)) {
            $info = @getimagesizefromstring($this->disk()->get($largest));
            if ($info !== false) {
                [$width, $height] = $info;
            }
        }

        return ['width' => $width, 'height' => $height, 'sizeBytes' => $bytes ?: null];
    }
}
