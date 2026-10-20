<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A11: nạp nội dung từ database/seeders/data/content_export.json (do content:export tạo). Chạy lại an toàn:
 * mọi bản ghi upsert theo slug (địa điểm theo source+externalId, tháng/khí hậu theo điểm đến+tháng).
 * Điểm đến phải có sẵn (nạp bằng DiscoverSeeder trước); mục tham chiếu điểm đến không có sẽ bị bỏ qua và báo.
 */
class ContentSeeder extends Seeder
{
    private ?string $file = null;
    private array $skipped = [];

    public function useFile(string $file): static
    {
        $this->file = $file;

        return $this;
    }

    public function run(): void
    {
        $file = $this->file ?: database_path('seeders/data/content_export.json');
        $d = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (!is_array($d) || ($d['version'] ?? 0) !== 1) {
            $this->say("Không đọc được {$file} (thiếu tệp hoặc sai version).", true);

            return;
        }
        $this->skipped = [];
        $destId = DB::table('tbl_destinations')->pluck('destinationId', 'slug')->all();
        $now = now();
        $by = fn (?string $s) => $s === null ? null : ($destId[$s] ?? false);

        DB::transaction(function () use ($d, $destId, $now, $by) {
            foreach ($d['months'] ?? [] as $slug => $rows) {
                if (!isset($destId[$slug])) { $this->skip("tháng đẹp: {$slug}"); continue; }
                DB::table('tbl_destination_months')->upsert(array_map(fn ($r) => $r + ['destinationId' => $destId[$slug]], $rows), ['destinationId', 'month'], ['level', 'note', 'source']);
            }
            foreach ($d['climate'] ?? [] as $slug => $rows) {
                if (!isset($destId[$slug])) { $this->skip("khí hậu: {$slug}"); continue; }
                DB::table('tbl_destination_climate')->upsert(array_map(fn ($r) => $r + ['destinationId' => $destId[$slug], 'fetchedAt' => $now], $rows), ['destinationId', 'month'], ['tMax', 'tMin', 'rainMm', 'rainyDays', 'years', 'periodFrom', 'periodTo']);
            }
            foreach ($d['foods'] ?? [] as $r) {
                $id = $by($r['destination'] ?? null);
                if ($id === false) { $this->skip("món {$r['slug']} -> {$r['destination']}"); continue; }
                unset($r['destination']);
                $this->upsertSlug('tbl_foods', $r + ['destinationId' => $id], $now);
            }
            foreach ($d['events'] ?? [] as $r) {
                $id = $by($r['destination'] ?? null);
                if ($id === false) { $this->skip("lễ hội {$r['slug']} -> {$r['destination']}"); continue; }
                unset($r['destination']);
                $this->upsertSlug('tbl_events', $r + ['destinationId' => $id], $now);
            }
            foreach ($d['guideCategories'] ?? [] as $c) {
                DB::table('tbl_guide_categories')->updateOrInsert(['slug' => $c['slug']], ['name' => $c['name'], 'displayOrder' => $c['displayOrder']]);
            }
            $catId = DB::table('tbl_guide_categories')->pluck('categoryId', 'slug')->all();
            foreach ($d['guides'] ?? [] as $r) {
                $cat = $r['category'] ?? null;
                unset($r['category']);
                $this->upsertSlug('tbl_guides', $r + ['categoryId' => $catId[$cat] ?? null], $now);
            }

            $foodId = DB::table('tbl_foods')->pluck('foodId', 'slug')->all();
            $eventId = DB::table('tbl_events')->pluck('eventId', 'slug')->all();
            foreach ($d['places'] ?? [] as $r) {
                $id = $destId[$r['destination'] ?? ''] ?? null;
                if (!$id) { $this->skip("địa điểm {$r['externalId']} -> {$r['destination']}"); continue; }
                unset($r['destination']);
                DB::table('tbl_external_places')->updateOrInsert(['source' => $r['source'], 'externalId' => $r['externalId']], $r + ['destinationId' => $id, 'syncedAt' => $now]);
            }
            $placeId = DB::table('tbl_external_places')->selectRaw("placeId, CONCAT(source, ':', externalId) as k")->pluck('placeId', 'k')->all();

            foreach ($d['itineraries'] ?? [] as $r) {
                $items = $r['items'] ?? [];
                unset($r['items']);
                $iid = $this->upsertSlug('tbl_itineraries', $r, $now);
                DB::table('tbl_itinerary_items')->where('itineraryId', $iid)->delete();
                foreach ($items as $it) {
                    $refId = match ($it['refType']) {
                        'destination' => $destId[$it['ref'] ?? ''] ?? null, 'food' => $foodId[$it['ref'] ?? ''] ?? null,
                        'event' => $eventId[$it['ref'] ?? ''] ?? null, 'place' => $placeId[$it['ref'] ?? ''] ?? null, default => null,
                    };
                    DB::table('tbl_itinerary_items')->insert(['itineraryId' => $iid, 'dayNo' => $it['dayNo'], 'orderNo' => $it['orderNo'],
                        'refType' => $refId ? $it['refType'] : null, 'refId' => $refId, 'label' => $it['label'], 'note' => $it['note']]);
                }
            }
        });

        $this->say('Đã nạp nội dung từ ' . basename($file) . ($this->skipped ? '; bỏ qua ' . count($this->skipped) . ' mục: ' . implode(' | ', array_slice($this->skipped, 0, 5)) : '.'));
    }

    /** Upsert theo slug; trả về khóa chính. Cột createdAt/updatedAt do seeder đặt. */
    private function upsertSlug(string $table, array $row, $now): int
    {
        $key = ['tbl_foods' => 'foodId', 'tbl_events' => 'eventId', 'tbl_guides' => 'guideId', 'tbl_itineraries' => 'itineraryId'][$table];
        $exists = DB::table($table)->where('slug', $row['slug'])->value($key);
        if ($exists) {
            DB::table($table)->where($key, $exists)->update($row + ['updatedAt' => $now]);

            return (int) $exists;
        }

        return (int) DB::table($table)->insertGetId($row + ['createdAt' => $now]);
    }

    private function skip(string $what): void
    {
        $this->skipped[] = $what;
    }

    private function say(string $msg, bool $error = false): void
    {
        $this->command ? ($error ? $this->command->error($msg) : $this->command->info($msg)) : null;
    }
}
