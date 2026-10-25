<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Nạp ý tưởng trải nghiệm (draft) từ database/seeders/data/experiences_draft.json. Chạy lại an toàn: upsert theo slug,
 * không ghi đè bản ghi đã có. Điểm đến phải có sẵn; mục thiếu điểm đến bị bỏ qua và báo.
 *   php artisan db:seed --class=ExperienceDraftSeeder
 */
class ExperienceDraftSeeder extends Seeder
{
    public function run(): void
    {
        $file = database_path('seeders/data/experiences_draft.json');
        $d = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (!is_array($d)) {
            $this->command?->error("Không đọc được {$file}");

            return;
        }
        $dest = DB::table('tbl_destinations')->pluck('destinationId', 'slug')->all();
        $added = 0;
        foreach ($d['experiences'] ?? [] as $r) {
            if (!isset($dest[$r['destination'] ?? ''])) {
                $this->command?->warn("Bỏ qua {$r['slug']}: không có điểm đến {$r['destination']}");
                continue;
            }
            if (DB::table('tbl_experiences')->where('slug', $r['slug'])->exists()) {
                continue;
            }
            DB::table('tbl_experiences')->insert([
                'slug' => $r['slug'], 'name' => $r['name'], 'category' => $r['category'] ?? null,
                'destinationId' => $dest[$r['destination']], 'reviewStatus' => 'draft', 'createdAt' => now(),
            ]);
            $added++;
        }
        $this->command?->info("Đã thêm {$added} ý tưởng trải nghiệm (draft).");
    }
}
