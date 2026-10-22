<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * C1: nạp các bài cẩm nang nháp từ database/seeders/data/guides_draft.json.
 * Bài chưa có thì tạo ở trạng thái 'draft'. Bài đã tồn tại (theo slug) KHÔNG bị ghi đè, để không mất chỉnh sửa
 * hay trạng thái duyệt của bạn. Chạy: php artisan db:seed --class=GuideDraftSeeder
 */
class GuideDraftSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/guides_draft.json');
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || empty($data['guides'])) {
            $this->command?->error("Không đọc được $path");

            return;
        }

        $now = now();
        $cat = [];
        foreach ($data['categories'] ?? [] as $c) {
            DB::table('tbl_guide_categories')->updateOrInsert(['slug' => $c['slug']], ['name' => $c['name'], 'displayOrder' => $c['displayOrder'] ?? 0]);
            $cat[$c['slug']] = DB::table('tbl_guide_categories')->where('slug', $c['slug'])->value('categoryId');
        }

        $made = $skipped = 0;
        foreach ($data['guides'] as $g) {
            if (DB::table('tbl_guides')->where('slug', $g['slug'])->exists()) {
                $skipped++;
                continue;
            }
            DB::table('tbl_guides')->insert([
                'slug' => $g['slug'],
                'categoryId' => $cat[$g['category']] ?? null,
                'title' => $g['title'],
                'body' => $g['body'],
                'sourceUrl' => $g['sourceUrl'] ?? null,
                'license' => $g['license'] ?? 'Tự viết',
                'reviewStatus' => 'draft',
                'updatedOn' => $g['updatedOn'] ?? $now->toDateString(),
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
            $made++;
        }
        $this->command?->info("Cẩm nang: tạo $made bài nháp, bỏ qua $skipped bài đã có. Vào Admin > Duyệt nội dung > Cẩm nang để duyệt.");
    }
}
