<?php

namespace Database\Seeders;

use App\Services\Content\PublishGate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu mẫu cho phần Khám phá (database/seeders/data/demo_explore.json):
 *  - 10 điểm đến mới để phủ đủ 34 tỉnh/thành sau sáp nhập 07/2025, kèm điểm cảm hứng và tháng đẹp;
 *  - món ăn, trải nghiệm, lễ hội, lịch trình do nhóm tự viết (license "Tự viết"), không có giá, giờ mở cửa, ảnh;
 *  - bổ sung mô tả cho các món/lễ hội/trải nghiệm đã có tên nhưng còn trống.
 *
 * An toàn khi chạy lại: chỉ thêm bản ghi chưa có (theo slug); bản ghi đã reviewed/published không bị sửa.
 * Mặc định mọi thứ ở trạng thái draft. Dùng `php artisan explore:seed-demo --publish` để xuất bản phần đủ điều kiện
 * (PublishGate). Lễ hội luôn cần người đối chiếu ngày nên chỉ publish khi thêm --publish-events.
 * Cần chạy DiscoverSeeder trước (có sẵn 8 cảm hứng).
 */
class DemoExploreSeeder extends Seeder
{
    public bool $publish = false;
    public bool $publishEvents = false;
    private array $stats = [];
    private array $skipped = [];

    public function with(bool $publish, bool $publishEvents = false): static
    {
        $this->publish = $publish;
        $this->publishEvents = $publishEvents;

        return $this;
    }

    public function run(): void
    {
        $file = database_path('seeders/data/demo_explore.json');
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($d)) {
            $this->say("Không đọc được {$file}", true);

            return;
        }
        $this->stats = [];
        $this->skipped = [];
        $now = now();

        DB::transaction(function () use ($d, $now) {
            $this->destinations($d['destinations'] ?? []);
            $this->seasons($d['seasons'] ?? []);
            $dest = DB::table('tbl_destinations')->pluck('destinationId', 'slug')->all();
            $this->foods($d, $dest, $now);
            $this->experiences($d, $dest, $now);
            $this->events($d, $dest, $now);
            $this->itineraries($d['itineraries'] ?? [], $dest, $now);
            if ($this->publish) {
                $this->publishAll($d);
            }
        });

        $line = [];
        foreach ($this->stats as $k => $n) {
            $line[] = "{$k}: {$n}";
        }
        $this->say('Đã nạp dữ liệu mẫu Khám phá. ' . implode(', ', $line) . '.');
        if ($this->skipped) {
            $this->say('Bỏ qua: ' . implode(' | ', $this->skipped), true);
        }
    }

    private function bump(string $k, int $n = 1): void
    {
        $this->stats[$k] = ($this->stats[$k] ?? 0) + $n;
    }

    private function destinations(array $rows): void
    {
        $insp = DB::table('tbl_inspirations')->pluck('inspirationId', 'slug')->all();
        foreach ($rows as $r) {
            $exists = DB::table('tbl_destinations')->where('slug', $r['slug'])->exists();
            DB::table('tbl_destinations')->updateOrInsert(['slug' => $r['slug']], [
                'name' => $r['name'], 'province' => $r['province'], 'domain' => $r['domain'],
                'description' => $r['description'], 'latitude' => $r['lat'], 'longitude' => $r['lng'],
                'coverImage' => "discover/destinations/{$r['slug']}.webp", 'isActive' => 1,
            ]);
            if ($exists) {
                continue;
            }
            $id = (int) DB::table('tbl_destinations')->where('slug', $r['slug'])->value('destinationId');
            $code = ['bien' => 'bien', 'nhien' => 'thien-nhien', 'lanh' => 'chua-lanh', 'van' => 'van-hoa', 'am' => 'am-thuc', 'que' => 'mien-que', 'anh' => 'san-anh', 'doi' => 'hai-nguoi'];
            $pivot = [];
            foreach ($r['scores'] as $c => $score) {
                if (isset($insp[$code[$c] ?? ''])) {
                    $pivot[] = ['destinationId' => $id, 'inspirationId' => $insp[$code[$c]], 'score' => $score];
                }
            }
            if ($pivot) {
                DB::table('tbl_destination_inspiration')->insert($pivot);
            }
            $this->bump('điểm đến mới');
        }
    }

    private function seasons(array $seasons): void
    {
        $id = DB::table('tbl_destinations')->pluck('destinationId', 'slug')->all();
        foreach ($seasons as $slug => $s) {
            if (!isset($id[$slug]) || DB::table('tbl_destination_months')->where('destinationId', $id[$slug])->exists()) {
                continue;
            }
            $rows = [];
            for ($m = 1; $m <= 12; $m++) {
                $best = in_array($m, $s['m'], true);
                $rows[] = ['destinationId' => $id[$slug], 'month' => $m, 'level' => $best ? 'best' : 'ok', 'note' => $best ? $s['w'] : null, 'source' => 'manual'];
            }
            DB::table('tbl_destination_months')->insert($rows);
            $this->bump('tháng đẹp (điểm đến)');
        }
    }

    private function foods(array $d, array $dest, $now): void
    {
        foreach ($d['foods'] ?? [] as $r) {
            $did = $dest[$r['destination']] ?? null;
            if (!$did) {
                $this->skipped[] = "món {$r['slug']}";
                continue;
            }
            if (DB::table('tbl_foods')->where('slug', $r['slug'])->exists()) {
                continue;
            }
            $id = DB::table('tbl_foods')->insertGetId([
                'slug' => $r['slug'], 'name' => $r['name'], 'destinationId' => $did, 'category' => $r['category'],
                'description' => $r['description'], 'monthFrom' => $r['monthFrom'], 'monthTo' => $r['monthTo'],
                'license' => 'Tự viết', 'reviewStatus' => 'draft', 'createdAt' => $now,
            ], 'foodId');
            DB::table('tbl_food_destination')->insertOrIgnore(['foodId' => $id, 'destinationId' => $did]);
            $this->bump('món mới');
        }
        foreach ($d['foodDescriptions'] ?? [] as $slug => $text) {
            $n = $this->fillEmpty('tbl_foods', $slug, ['description' => $text, 'license' => 'Tự viết']);
            $this->bump('món bổ sung mô tả', $n);
        }
    }

    private function experiences(array $d, array $dest, $now): void
    {
        foreach ($d['experiences'] ?? [] as $r) {
            $did = $dest[$r['destination']] ?? null;
            if (!$did) {
                $this->skipped[] = "trải nghiệm {$r['slug']}";
                continue;
            }
            if (DB::table('tbl_experiences')->where('slug', $r['slug'])->exists()) {
                continue;
            }
            DB::table('tbl_experiences')->insert([
                'slug' => $r['slug'], 'name' => $r['name'], 'destinationId' => $did, 'category' => $r['category'],
                'duration' => $r['duration'], 'durationMinutes' => $r['durationMinutes'], 'difficulty' => $r['difficulty'],
                'indoor' => $r['indoor'], 'description' => $r['description'],
                'license' => 'Tự viết', 'reviewStatus' => 'draft', 'createdAt' => $now,
            ]);
            $this->bump('trải nghiệm mới');
        }
        // 8 ý tưởng trải nghiệm có sẵn (chỉ có tên): bảo đảm tồn tại rồi mới bổ sung mô tả
        $draft = new ExperienceDraftSeeder();
        $draft->setContainer(app())->run();
        foreach ($d['experienceDetails'] ?? [] as $slug => $x) {
            $row = DB::table('tbl_experiences')->where('slug', $slug)->first();
            if (!$row || $row->reviewStatus !== 'draft' || trim((string) $row->description) !== '') {
                continue;
            }
            DB::table('tbl_experiences')->where('slug', $slug)->update([
                'description' => $x['description'], 'duration' => $row->duration ?: $x['duration'],
                'durationMinutes' => $row->durationMinutes ?: $x['durationMinutes'], 'difficulty' => $row->difficulty ?: $x['difficulty'],
                'indoor' => $row->indoor ?? $x['indoor'], 'license' => $row->license ?: 'Tự viết', 'updatedAt' => $now,
            ]);
            $this->bump('trải nghiệm bổ sung mô tả');
        }
    }

    private function events(array $d, array $dest, $now): void
    {
        foreach ($d['events'] ?? [] as $r) {
            $did = $dest[$r['destination']] ?? null;
            if (!$did) {
                $this->skipped[] = "lễ hội {$r['slug']}";
                continue;
            }
            if (DB::table('tbl_events')->where('slug', $r['slug'])->exists()) {
                continue;
            }
            DB::table('tbl_events')->insert([
                'slug' => $r['slug'], 'name' => $r['name'], 'destinationId' => $did, 'isLunar' => $r['isLunar'] ? 1 : 0,
                'lunarMonth' => $r['lunarMonth'], 'lunarDay' => $r['lunarDay'], 'solarMonth' => $r['solarMonth'], 'solarDay' => $r['solarDay'],
                'durationDays' => $r['durationDays'], 'dateNote' => $r['dateNote'], 'description' => $r['description'],
                'license' => 'Tự viết', 'reviewStatus' => 'draft', 'createdAt' => $now,
            ]);
            $this->bump('lễ hội mới');
        }
        foreach ($d['eventDescriptions'] ?? [] as $slug => $text) {
            $this->bump('lễ hội bổ sung mô tả', $this->fillEmpty('tbl_events', $slug, ['description' => $text, 'license' => 'Tự viết']));
        }
    }

    private function itineraries(array $rows, array $dest, $now): void
    {
        $food = DB::table('tbl_foods')->pluck('foodId', 'slug')->all();
        foreach ($rows as $r) {
            $existing = DB::table('tbl_itineraries')->where('slug', $r['slug'])->first();
            if ($existing) {
                continue;
            }
            $id = (int) DB::table('tbl_itineraries')->insertGetId([
                'slug' => $r['slug'], 'title' => $r['title'], 'days' => $r['days'], 'region' => $r['region'], 'summary' => $r['summary'],
                'license' => 'Tự viết', 'reviewStatus' => 'draft', 'createdAt' => $now,
            ], 'itineraryId');
            $order = [];
            foreach ($r['items'] as $it) {
                $refId = match ($it['refType']) {
                    'destination' => $dest[$it['ref']] ?? null,
                    'food' => $food[$it['ref']] ?? null,
                    default => null,
                };
                if ($it['ref'] && !$refId) {
                    $this->skipped[] = "{$r['slug']}: {$it['ref']}";
                }
                $order[$it['dayNo']] = ($order[$it['dayNo']] ?? -1) + 1;
                DB::table('tbl_itinerary_items')->insert([
                    'itineraryId' => $id, 'dayNo' => $it['dayNo'], 'orderNo' => $order[$it['dayNo']],
                    'refType' => $refId ? $it['refType'] : null, 'refId' => $refId, 'label' => $it['label'], 'note' => $it['note'] ?? null,
                ]);
            }
            $this->bump('lịch trình mới');
        }
    }

    /** Ghi các cột còn trống của một bản ghi còn draft và chưa có mô tả. @return int 0|1 */
    private function fillEmpty(string $table, string $slug, array $values): int
    {
        $row = DB::table($table)->where('slug', $slug)->first();
        if (!$row || $row->reviewStatus !== 'draft' || trim((string) $row->description) !== '') {
            return 0;
        }
        $set = ['description' => $values['description'], 'updatedAt' => now()];
        if (empty($row->license)) {
            $set['license'] = $values['license'];
        }
        DB::table($table)->where('slug', $slug)->update($set);

        return 1;
    }

    /** Chuyển sang published những bản ghi trong tệp mẫu đạt PublishGate. */
    private function publishAll(array $d): void
    {
        $kinds = [
            'foods' => ['tbl_foods', array_merge(array_column($d['foods'] ?? [], 'slug'), array_keys($d['foodDescriptions'] ?? []))],
            'experiences' => ['tbl_experiences', array_merge(array_column($d['experiences'] ?? [], 'slug'), array_keys($d['experienceDetails'] ?? []))],
            'itineraries' => ['tbl_itineraries', array_column($d['itineraries'] ?? [], 'slug')],
        ];
        if ($this->publishEvents) {
            $kinds['events'] = ['tbl_events', array_merge(array_column($d['events'] ?? [], 'slug'), array_keys($d['eventDescriptions'] ?? []))];
        }
        foreach ($kinds as $kind => [$table, $slugs]) {
            foreach (DB::table($table)->whereIn('slug', $slugs)->where('reviewStatus', 'draft')->get() as $row) {
                if (PublishGate::problems($kind, $row) !== []) {
                    $this->skipped[] = "{$kind}/{$row->slug} chưa đạt điều kiện xuất bản";
                    continue;
                }
                DB::table($table)->where('slug', $row->slug)->update(['reviewStatus' => 'published', 'updatedAt' => now()]);
                $this->bump("{$kind} đã xuất bản");
            }
        }
    }

    private function say(string $m, bool $warn = false): void
    {
        $this->command ? ($warn ? $this->command->warn($m) : $this->command->info($m)) : null;
    }
}
