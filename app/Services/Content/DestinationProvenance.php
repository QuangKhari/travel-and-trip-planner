<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nguồn dữ liệu và ngày cập nhật cuối của một điểm đến (khối "Nguồn dữ liệu và cập nhật"). Chỉ nêu nguồn có thật trong dữ liệu:
 * không có wikiUrl thì không ghi Wikipedia, không có địa điểm đã duyệt thì không ghi OpenStreetMap...
 */
class DestinationProvenance
{
    /** @return array{updated:?string,sources:list<array{0:string,1:string,2:?string}>} */
    public function forDestination(object $d): array
    {
        $id = (int) $d->destinationId;
        $sources = [];

        if (!empty($d->wikiUrl)) {
            $sources[] = ['Giới thiệu', 'Wikipedia (CC BY-SA 4.0)', $d->wikiUrl];
        }
        if (!empty($d->image) && !empty($d->imageCredit)) {
            $sources[] = ['Ảnh bìa', $d->imageCredit, !empty($d->imageSourceUrl) ? $d->imageSourceUrl : null];
        }
        if (DB::table('tbl_external_places')->where('destinationId', $id)->where('reviewStatus', 'published')->exists()) {
            $sources[] = ['Địa điểm', '© OpenStreetMap contributors (ODbL)', 'https://www.openstreetmap.org/copyright'];
        }
        if (DB::table('tbl_destination_climate')->where('destinationId', $id)->exists()) {
            $sources[] = ['Khí hậu', 'Open-Meteo (dữ liệu khí hậu lịch sử)', 'https://open-meteo.com/'];
        }
        $sources[] = ['Món ăn, trải nghiệm, lễ hội, lịch trình', 'biên tập viên Travela; nguồn và giấy phép của từng mục ghi ở trang chi tiết', null];

        $dates = [$d->updatedAt ?? null, $d->wikiSyncedAt ?? null];
        foreach (['tbl_foods', 'tbl_experiences', 'tbl_events'] as $t) {
            $dates[] = $this->latest($t, 'destinationId', $id);
        }
        $latest = collect($dates)->filter()->map(fn ($v) => substr((string) $v, 0, 10))->max();

        return ['updated' => $latest ?: null, 'sources' => $sources];
    }

    private function latest(string $table, string $col, int $id): ?string
    {
        if (!Schema::hasColumn($table, 'updatedAt')) {
            return null;
        }
        $v = DB::table($table)->where($col, $id)->where('reviewStatus', 'published')->max('updatedAt');

        return $v ? (string) $v : null;
    }
}
