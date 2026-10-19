<?php

namespace App\Console\Commands;

use App\Services\Content\ImportRaw;
use App\Services\Discover\WikipediaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportArticles extends Command
{
    protected $signature = 'content:import-articles
        {kind=all : foods | events | all}
        {--file= : Đường dẫn JSON nội dung (mặc định database/seeders/data/demo_content.json)}
        {--slug= : Chỉ một mục (slug món/lễ hội)}
        {--refresh : Gọi lại Wikipedia dù vùng đệm đã có}
        {--dry-run : Chỉ in, không ghi DB}';

    protected $description = 'A3: nạp đoạn giới thiệu Wikipedia vi cho món ăn/lễ hội trong demo_content.json -> tbl_import_raw -> bản draft';

    private const LICENSE = 'CC BY-SA 4.0';

    public function handle(WikipediaClient $wiki): int
    {
        $file = $this->option('file') ?: database_path('seeders/data/demo_content.json');
        $data = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (!$data) {
            $this->error("Không đọc được {$file}");

            return self::FAILURE;
        }

        $kind = $this->argument('kind');
        $dests = DB::table('tbl_destinations')->pluck('destinationId', 'slug');
        $miss = [];

        foreach (['foods', 'events'] as $k) {
            if ($kind !== 'all' && $kind !== $k) {
                continue;
            }
            foreach ($data[$k] ?? [] as $it) {
                if (($s = $this->option('slug')) && $it['slug'] !== $s) {
                    continue;
                }
                $dest = $it['destination'] ?? null;
                if ($dest && !isset($dests[$dest])) {
                    $this->warn("✗ {$it['slug']}: điểm đến '{$dest}' chưa có trong tbl_destinations");
                    continue;
                }
                $extract = $url = null;
                // "desc" trong JSON (kể cả null) thay hẳn đoạn Wikipedia: dùng khi bài Wikipedia khớp sai chủ đề.
                $override = array_key_exists('desc', $it);
                if ($override) {
                    $extract = $it['desc'] ?: null;
                } elseif (!empty($it['wikiTitle'])) {
                    $sum = $this->summary($wiki, $it['wikiTitle']);
                    if ($sum) {
                        $extract = $sum['extract'];
                        $url = $sum['url'];
                    } else {
                        $miss[] = "{$it['slug']} (\"{$it['wikiTitle']}\")";
                    }
                }
                $this->line(($extract ? '✓ ' : '• ') . "{$k}/{$it['slug']}" . ($extract ? ': ' . mb_strimwidth($extract, 0, 60, '…') : ': chưa có đoạn Wikipedia'));
                if (!$this->option('dry-run')) {
                    $k === 'foods' ? $this->saveFood($it, $dests[$dest] ?? null, $extract, $url, $override) : $this->saveEvent($it, $dests[$dest] ?? null, $extract, $url, $override);
                }
            }
        }

        if ($miss) {
            $this->warn('Không thấy bài Wikipedia cho: ' . implode(', ', $miss) . ' -> sửa wikiTitle trong JSON cho đúng tên bài.');
        }
        $this->info('Xong. Mọi bản ghi mới ở trạng thái draft; bản đã reviewed/published không bị ghi đè.');

        return self::SUCCESS;
    }

    /** Vùng đệm trước, chỉ gọi mạng khi chưa có (hoặc --refresh). */
    private function summary(WikipediaClient $wiki, string $title): ?array
    {
        $id = config('discover.wikipedia.lang', 'vi') . ':' . $title;
        $raw = $this->option('refresh') ? null : ImportRaw::get('wikipedia', $id);
        if ($raw === null) {
            $raw = $wiki->summary($title);
            usleep((int) config('discover.wikipedia.delay_us'));
            if ($raw) {
                ImportRaw::put('wikipedia', $id, $raw);
            }
        }

        return $raw;
    }

    private function saveFood(array $it, ?int $destId, ?string $extract, ?string $url, bool $override = false): void
    {
        $row = DB::table('tbl_foods')->where('slug', $it['slug'])->first();
        if ($row && $row->reviewStatus !== 'draft') {
            return;
        }
        $vals = [
            'name' => $it['name'],
            'destinationId' => $destId,
            'category' => $it['category'] ?? null,
            'description' => $override ? $extract : ($extract ?? ($row->description ?? null)),
            'sourceUrl' => $override ? null : ($url ?? ($row->sourceUrl ?? null)),
            'license' => $override ? ($extract ? 'Tự viết' : null) : ($extract ? self::LICENSE : ($row->license ?? null)),
            'updatedAt' => now(),
        ];
        $row ? DB::table('tbl_foods')->where('foodId', $row->foodId)->update($vals)
            : DB::table('tbl_foods')->insert($vals + ['slug' => $it['slug'], 'reviewStatus' => 'draft']);
    }

    /** Ngày âm/dương luôn lấy từ JSON do người nhập, KHÔNG lấy từ Wikipedia. */
    private function saveEvent(array $it, ?int $destId, ?string $extract, ?string $url, bool $override = false): void
    {
        $row = DB::table('tbl_events')->where('slug', $it['slug'])->first();
        if ($row && $row->reviewStatus !== 'draft') {
            return;
        }
        $vals = [
            'name' => $it['name'],
            'destinationId' => $destId,
            'isLunar' => (int) ($it['isLunar'] ?? 0),
            'lunarMonth' => $it['lunarMonth'] ?? null,
            'lunarDay' => $it['lunarDay'] ?? null,
            'solarMonth' => $it['solarMonth'] ?? null,
            'solarDay' => $it['solarDay'] ?? null,
            'durationDays' => $it['durationDays'] ?? 1,
            'dateNote' => $it['dateNote'] ?? null,
            'description' => $override ? $extract : ($extract ?? ($row->description ?? null)),
            'sourceUrl' => $override ? null : ($url ?? ($row->sourceUrl ?? null)),
            'license' => $override ? ($extract ? 'Tự viết' : null) : ($extract ? self::LICENSE : ($row->license ?? null)),
            'updatedAt' => now(),
        ];
        $row ? DB::table('tbl_events')->where('eventId', $row->eventId)->update($vals)
            : DB::table('tbl_events')->insert($vals + ['slug' => $it['slug'], 'reviewStatus' => 'draft']);
    }
}
