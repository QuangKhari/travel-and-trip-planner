<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ContentCoverage extends Command
{
    protected $signature = 'content:coverage {--demo : Chỉ 4 điểm đến demo trong demo_content.json}';

    protected $description = 'A2/8.4-bước 7: bảng mức đủ nội dung của từng điểm đến (tháng, địa điểm, món, lễ hội, lịch trình, tour)';

    public function handle(): int
    {
        $slugs = null;
        if ($this->option('demo')) {
            $d = json_decode((string) @file_get_contents(database_path('seeders/data/demo_content.json')), true);
            $slugs = array_column($d['destinations'] ?? [], 'slug');
        }
        $q = DB::table('tbl_destinations')->where('isActive', 1)->orderBy('name');
        if ($slugs) {
            $q->whereIn('slug', $slugs);
        }

        $rows = [];
        foreach ($q->get() as $d) {
            $id = $d->destinationId;
            $pub = fn(string $t) => DB::table($t)->where('destinationId', $id)->where('reviewStatus', 'published')->count();
            $all = fn(string $t) => DB::table($t)->where('destinationId', $id)->count();
            $rows[] = [
                $d->slug,
                DB::table('tbl_destination_months')->where('destinationId', $id)->count() . '/12',
                $pub('tbl_external_places') . '/' . $all('tbl_external_places') . ' (đã duyệt/tổng, mục tiêu ~30)',
                $pub('tbl_foods') . '/' . $all('tbl_foods') . ' (mục tiêu 10)',
                $pub('tbl_events') . '/' . $all('tbl_events') . ' (mục tiêu 2-3)',
                DB::table('tbl_itinerary_items')->where('refType', 'destination')->where('refId', $id)->distinct()->count('itineraryId') . ' (mục tiêu 1)',
                DB::table('tbl_tours')->where('destination', 'like', '%' . $d->name . '%')->count(),
                $d->coverImage ? 'có' : 'THIẾU',
            ];
        }
        $this->table(['slug', 'tháng', 'địa điểm', 'món', 'lễ hội', 'lịch trình', 'tour', 'ảnh bìa'], $rows);

        return self::SUCCESS;
    }
}
