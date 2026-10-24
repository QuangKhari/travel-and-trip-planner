<?php

namespace App\Services\Content;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Tải ảnh bìa từ admin: kiểm tra MIME thật + số điểm ảnh, đổi WebP, khống chế dung lượng,
 * sinh bản thu nhỏ -s.webp. Dùng lại ImageImporter::storeLocal (cùng đường GD như lệnh content:import-images).
 * Đường dẫn lưu giống lệnh nhập: discover/{loại}/{slug}.webp
 */
class CoverUploader
{
    public function __construct(private ImageImporter $img) {}

    /**
     * @return string đường dẫn trong disk media (vd discover/foods/pho-ha-noi.webp)
     *
     * @throws \RuntimeException thông điệp tiếng Việt, hiển thị thẳng cho người dùng
     */
    public function store(UploadedFile $file, string $dir, string $slug, int $maxKb = 160, bool $thumb = true): string
    {
        $real = $file->getRealPath();
        $info = $real ? @getimagesize($real) : false;
        if (!$info || !in_array($info['mime'] ?? '', (array) config('media.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']), true)) {
            throw new \RuntimeException('Chỉ nhận ảnh JPG, PNG hoặc WebP.');
        }
        if (($info[0] * $info[1]) > (int) config('media.limits.max_pixels', 16000000)) {
            throw new \RuntimeException('Ảnh quá lớn (số điểm ảnh vượt giới hạn). Hãy thu nhỏ ảnh rồi tải lại.');
        }
        $path = trim($dir, '/') . '/' . $slug . '.webp';
        $res = $this->img->storeLocal($real, $path, $maxKb, $thumb, false);
        if (!$res['ok']) {
            throw new \RuntimeException('Không xử lý được ảnh: ' . $res['note']);
        }

        return $path;
    }

    /** Xóa ảnh và bản thu nhỏ. Chỉ đụng tới đường dẫn bắt đầu bằng discover/ để không xóa nhầm ảnh tour. */
    public function delete(?string $path): void
    {
        if (!$path || str_contains($path, '..') || !str_starts_with($path, 'discover/')) {
            return;
        }
        $disk = Storage::disk(config('media.disk', 'public'));
        $disk->delete($path);
        $disk->delete(preg_replace('/\.webp$/', '-s.webp', $path));
    }
}
