<?php

namespace App\Console\Commands;

use App\Services\Discover\WikipediaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportWikipedia extends Command
{
    protected $signature = 'discover:import-wikipedia
        {--slug= : Chỉ một điểm đến (vd ly-son)}
        {--images : Tải cả ảnh bìa (chỉ ảnh giấy phép mở) cho điểm đến chưa có ảnh}
        {--force-images : Tải lại ảnh dù đã có}
        {--coords : Ghi đè tọa độ bằng tọa độ Wikipedia}
        {--description : Ghi đè description bằng đoạn Wikipedia (mặc định chỉ lưu vào wikiExtract)}
        {--dry-run : Chỉ in kết quả, không ghi DB/file}';

    protected $description = 'Nạp đoạn giới thiệu, tọa độ và (tuỳ chọn) ảnh bìa từ Wikipedia/Commons cho tbl_destinations';

    public function handle(WikipediaClient $wiki): int
    {
        $q = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('destinationId');
        if ($slug = $this->option('slug')) {
            $q->where('slug', $slug);
        }
        $rows = $q->get();
        if ($rows->isEmpty()) {
            $this->error('Không có điểm đến nào khớp.');

            return self::FAILURE;
        }

        $ok = 0;
        $missing = [];
        foreach ($rows as $d) {
            $title = $d->wikiTitle ?: (config('discover.wiki_titles')[$d->slug] ?? $d->name);
            $s = $wiki->summary($title);
            usleep(config('discover.wikipedia.delay_us'));

            if (!$s) {
                $missing[] = "{$d->slug} (tìm \"{$title}\")";
                $this->warn("✗ {$d->slug}: không có bài \"{$title}\" - hãy điền cột wikiTitle cho đúng tên bài.");
                continue;
            }

            $upd = [
                'wikiTitle' => $s['title'],
                'wikiExtract' => $s['extract'],
                'wikiUrl' => $s['url'],
                'wikiSyncedAt' => now(),
            ];

            if ($this->option('description')) {
                $upd['description'] = $s['extract'];
            }
            if ($s['lat'] !== null && $s['lng'] !== null) {
                $km = $this->km((float) $d->latitude, (float) $d->longitude, $s['lat'], $s['lng']);
                if ($this->option('coords')) {
                    $upd['latitude'] = round($s['lat'], 6);
                    $upd['longitude'] = round($s['lng'], 6);
                }
                $coordNote = $km > 30 ? "  ⚠ tọa độ lệch {$km} km so với DB" : '';
            } else {
                $coordNote = '  (Wikipedia không có tọa độ)';
            }

            $imgNote = '';
            if (($this->option('images') || $this->option('force-images')) && $s['imageFile']) {
                $hasImg = $d->coverImage && Storage::disk(config('media.disk', 'public'))->exists($d->coverImage);
                if ($hasImg && !$this->option('force-images')) {
                    $imgNote = '  ảnh: đã có, bỏ qua';
                } else {
                    [$imgUpd, $imgNote] = $this->fetchImage($wiki, $d->slug, $s['imageFile']);
                    $upd += $imgUpd;
                }
            }

            $this->line("✓ {$d->slug}: " . mb_strimwidth($s['extract'], 0, 70, '…') . $coordNote . $imgNote);
            if (!$this->option('dry-run')) {
                DB::table('tbl_destinations')->where('destinationId', $d->destinationId)->update($upd);
            }
            $ok++;
        }

        $this->info("Xong: {$ok}/{$rows->count()} điểm đến." . ($this->option('dry-run') ? ' (dry-run, chưa ghi gì)' : ''));
        if ($missing) {
            $this->warn('Chưa khớp: ' . implode(', ', $missing));
        }

        return self::SUCCESS;
    }

    /** @return array{0:array,1:string} */
    private function fetchImage(WikipediaClient $wiki, string $slug, string $file): array
    {
        $info = $wiki->imageInfo($file, (int) config('discover.wikipedia.image_width'));
        usleep(config('discover.wikipedia.delay_us'));
        if (!$info || $info['url'] === '') {
            return [[], '  ảnh: không đọc được thông tin'];
        }
        if (!$info['free']) {
            return [[], "  ảnh: bỏ qua (giấy phép \"{$info['license']}\" không phải giấy phép mở)"];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return [[], '  ảnh: PHP thiếu GD hoặc GD không hỗ trợ WebP'];
        }
        $bin = $wiki->download($info['url']);
        if ($bin === null) {
            return [[], '  ảnh: tải thất bại từ ' . $info['url']];
        }
        $im = @imagecreatefromstring($bin);
        if (!$im) {
            return [[], '  ảnh: file không giải mã được'];
        }

        ob_start();
        imagewebp($im, null, (int) config('discover.wikipedia.webp_quality'));
        $webp = ob_get_clean();
        imagedestroy($im);

        $path = "discover/destinations/{$slug}.webp";
        if (!$this->option('dry-run')) {
            Storage::disk(config('media.disk', 'public'))->put($path, $webp);
        }

        return [[
            'coverImage' => $path,
            'imageCredit' => mb_substr($info['artist'] . ' / ' . $info['license'], 0, 255),
            'imageSourceUrl' => $info['page'],
        ], "  ảnh: ✓ {$info['artist']} / {$info['license']}"];
    }

    private function km(float $la1, float $lo1, float $la2, float $lo2): int
    {
        $r = 6371;
        $dLa = deg2rad($la2 - $la1);
        $dLo = deg2rad($lo2 - $lo1);
        $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }
}
