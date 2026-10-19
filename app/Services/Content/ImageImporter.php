<?php

namespace App\Services\Content;

use App\Services\Discover\WikipediaClient;
use Illuminate\Support\Facades\Storage;

/**
 * A6: tải ảnh Commons (chỉ giấy phép mở), đổi cỡ, WebP, khống chế dung lượng, tùy chọn bản thu nhỏ cho nền mờ.
 * Dùng lại WikipediaClient của dự án (imageInfo/download đã lọc giấy phép và chỉ tải từ máy chủ Wikimedia).
 */
class ImageImporter
{
    public function __construct(private WikipediaClient $wiki) {}

    /**
     * @return array{ok:bool,note:string,credit?:string,page?:?string,kb?:int}
     */
    public function store(string $imageFile, string $path, int $maxKb, bool $thumb, bool $dry): array
    {
        $info = $this->wiki->imageInfo($imageFile, (int) config('discover.wikipedia.image_width'));
        usleep((int) config('discover.wikipedia.delay_us'));
        if (!$info || $info['url'] === '') {
            return ['ok' => false, 'note' => 'không đọc được thông tin ảnh'];
        }
        if (!$info['free']) {
            return ['ok' => false, 'note' => "giấy phép \"{$info['license']}\" không phải giấy phép mở, bỏ qua"];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return ['ok' => false, 'note' => 'PHP thiếu GD hoặc GD không hỗ trợ WebP'];
        }
        $bin = $this->wiki->download($info['url']);
        $im = $bin === null ? null : @imagecreatefromstring($bin);
        if (!$im) {
            return ['ok' => false, 'note' => 'tải hoặc giải mã ảnh thất bại'];
        }

        [$webp, $kb] = $this->encode($im, $maxKb);
        if (!$dry) {
            $disk = Storage::disk(config('media.disk', 'public'));
            $disk->put($path, $webp);
            if ($thumb) {
                $disk->put(preg_replace('/\.webp$/', '-s.webp', $path), $this->encodeThumb($im));
            }
        }
        imagedestroy($im);

        return ['ok' => true, 'kb' => $kb, 'page' => $info['page'], 'credit' => mb_substr($info['artist'] . ' / ' . $info['license'], 0, 255), 'note' => "{$kb} KB"];
    }

    /**
     * Ảnh có sẵn trên máy (tự chụp hoặc có giấy phép riêng): đổi cỡ, WebP, khống chế dung lượng.
     * Tác giả/giấy phép do người gọi tự ghi (không có metadata Commons để kiểm tra).
     *
     * @return array{ok:bool,note:string,kb?:int}
     */
    public function storeLocal(string $file, string $path, int $maxKb, bool $thumb, bool $dry): array
    {
        if (!is_file($file)) {
            return ['ok' => false, 'note' => "không thấy tệp {$file}"];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return ['ok' => false, 'note' => 'PHP thiếu GD hoặc GD không hỗ trợ WebP'];
        }
        $im = @imagecreatefromstring((string) file_get_contents($file));
        if (!$im) {
            return ['ok' => false, 'note' => 'không đọc được ảnh (chỉ nhận jpg, png, webp, gif)'];
        }
        [$webp, $kb] = $this->encode($im, $maxKb);
        if (!$dry) {
            $disk = Storage::disk(config('media.disk', 'public'));
            $disk->put($path, $webp);
            if ($thumb) {
                $disk->put(preg_replace('/\.webp$/', '-s.webp', $path), $this->encodeThumb($im));
            }
        }
        imagedestroy($im);

        return ['ok' => true, 'kb' => $kb, 'note' => "{$kb} KB"];
    }

    /** Giảm chất lượng rồi giảm chiều rộng cho tới khi <= $maxKb (tối đa 3 lần thu nhỏ). */
    private function encode($im, int $maxKb): array
    {
        $q = (int) config('discover.wikipedia.webp_quality', 82);
        $shrink = 0;
        do {
            ob_start();
            imagewebp($im, null, $q);
            $bin = ob_get_clean();
            $kb = (int) ceil(strlen($bin) / 1024);
            if ($kb <= $maxKb || $maxKb <= 0) {
                break;
            }
            if ($q > 56) {
                $q -= 8;
            } elseif ($shrink < 3) {
                $small = imagescale($im, (int) (imagesx($im) * 0.85));
                if ($small) {
                    imagedestroy($im);
                    $im = $small;
                }
                $shrink++;
            } else {
                break;
            }
        } while (true);

        return [$bin, $kb];
    }

    private function encodeThumb($im): string
    {
        $t = imagescale($im, 48);
        ob_start();
        imagewebp($t, null, 40);
        $bin = ob_get_clean();
        imagedestroy($t);

        return $bin;
    }
}
