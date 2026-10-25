<?php

namespace App\Console\Commands;

use App\Services\Content\ImageImporter;
use App\Services\Content\ImportRaw;
use App\Services\Discover\WikipediaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportImages extends Command
{
    protected $signature = 'content:import-images
        {kind : destinations | foods | events}
        {--slug= : Chỉ một mục}
        {--file= : Tên tệp Commons chọn tay (vd "Pho_Ha_Noi.jpg", không gồm "File:"); cần kèm --slug và --force}
        {--local= : Ảnh có sẵn trên máy (jpg/png/webp), dùng khi Commons không có ảnh; cần --slug, --credit}
        {--credit= : Ghi công cho --local, vd "Ảnh: Nguyễn Văn A (tự chụp)"}
        {--force : Tải lại dù đã có ảnh}
        {--max-kb=120 : Dung lượng WebP tối đa (tài liệu: 120 KB, ảnh hero tấm đầu 200)}
        {--thumb : Tạo thêm bản 48px {slug}-s.webp cho nền mờ của hero}
        {--dry-run : Không ghi file/DB}';

    protected $description = 'A6: tải ảnh Wikimedia Commons (chỉ giấy phép mở) -> WebP, ghi tác giả/giấy phép/trang gốc';

    private const MAP = [
        'destinations' => ['table' => 'tbl_destinations', 'key' => 'destinationId', 'dir' => 'discover/destinations'],
        'foods'        => ['table' => 'tbl_foods', 'key' => 'foodId', 'dir' => 'discover/foods'],
        'experiences'  => ['table' => 'tbl_experiences', 'key' => 'experienceId', 'dir' => 'discover/experiences'],
        'events'       => ['table' => 'tbl_events', 'key' => 'eventId', 'dir' => 'discover/events'],
    ];

    public function handle(WikipediaClient $wiki, ImageImporter $img): int
    {
        $kind = $this->argument('kind');
        $m = self::MAP[$kind] ?? null;
        if (!$m) {
            $this->error('kind phải là destinations, foods hoặc events');

            return self::FAILURE;
        }

        $q = DB::table($m['table'])->orderBy($m['key']);
        if ($kind === 'destinations') {
            $q->where('isActive', 1);
        }
        if ($s = $this->option('slug')) {
            $q->where('slug', $s);
        }
        if (($this->option('file') || $this->option('local')) && !$this->option('slug')) {
            $this->error('--file và --local chỉ dùng cùng --slug (một mục).');

            return self::FAILURE;
        }
        if ($this->option('local') && !$this->option('credit')) {
            $this->error('--local cần --credit="..." để ghi tác giả/giấy phép (bắt buộc có ghi công).');

            return self::FAILURE;
        }
        $disk = Storage::disk(config('media.disk', 'public'));
        $lang = config('discover.wikipedia.lang', 'vi');
        $ok = $skip = $fail = 0;

        foreach ($q->get() as $r) {
            if ($r->coverImage && $disk->exists($r->coverImage) && !$this->option('force')) {
                $skip++;
                continue;
            }
            if ($local = $this->option('local')) {
                $res = $img->storeLocal($local, "{$m['dir']}/{$r->slug}.webp", (int) $this->option('max-kb'), (bool) $this->option('thumb'), (bool) $this->option('dry-run'));
                if (!$res['ok']) {
                    $this->warn("✗ {$r->slug}: {$res['note']}");
                    $fail++;
                    continue;
                }
                $res['credit'] = mb_substr((string) $this->option('credit'), 0, 255);
                $res['page'] = null;
                $this->line("✓ {$r->slug}: {$res['note']} · {$res['credit']}");
                if (!$this->option('dry-run')) {
                    DB::table($m['table'])->where($m['key'], $r->{$m['key']})->update([
                        'coverImage' => "{$m['dir']}/{$r->slug}.webp",
                        'imageCredit' => $res['credit'],
                        'imageSourceUrl' => null,
                    ]);
                }
                $ok++;
                continue;
            }
            if ($file = $this->option('file')) {
                $imageFile = str_replace(' ', '_', preg_replace('/^(File|Tệp):/iu', '', trim($file)));
            } else {
                $title = $kind === 'destinations' ? ($r->wikiTitle ?: $r->name) : ImportRaw::titleFromUrl($r->sourceUrl);
                if (!$title) {
                    $this->warn("• {$r->slug}: chưa có bài Wikipedia, bỏ qua (chọn ảnh tay bằng --file=)");
                    $fail++;
                    continue;
                }
                $sum = ImportRaw::get('wikipedia', "{$lang}:{$title}") ?? $wiki->summary($title);
                if (!$sum || empty($sum['imageFile'])) {
                    $this->warn("✗ {$r->slug}: bài \"{$title}\" không có ảnh chính (chọn ảnh tay bằng --file=)");
                    $fail++;
                    continue;
                }
                $imageFile = $sum['imageFile'];
            }
            $res = $img->store($imageFile, "{$m['dir']}/{$r->slug}.webp", (int) $this->option('max-kb'), (bool) $this->option('thumb'), (bool) $this->option('dry-run'));
            if (!$res['ok']) {
                $this->warn("✗ {$r->slug}: {$res['note']}");
                $fail++;
                continue;
            }
            $this->line("✓ {$r->slug}: {$res['note']} · {$res['credit']}");
            if (!$this->option('dry-run')) {
                DB::table($m['table'])->where($m['key'], $r->{$m['key']})->update([
                    'coverImage' => "{$m['dir']}/{$r->slug}.webp",
                    'imageCredit' => $res['credit'],
                    'imageSourceUrl' => $res['page'],
                ]);
            }
            $ok++;
        }

        $this->info("Xong: {$ok} ảnh mới, {$skip} đã có, {$fail} lỗi/bỏ qua." . ($this->option('dry-run') ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
