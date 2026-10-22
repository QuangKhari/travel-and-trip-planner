<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A8: nạp lịch trình nháp từ database/seeders/data/itineraries_draft.json.
 * Điểm dừng chỉ trỏ tới điểm đến, địa điểm, món, lễ hội ĐÃ CÓ trong DB (không có thì bỏ qua và báo).
 * Luôn nạp ở trạng thái draft và không ghi đè lịch trình đã được duyệt (reviewed/published).
 * Chạy sau ContentSeeder:  php artisan db:seed --class=ItineraryDraftSeeder
 */
class ItineraryDraftSeeder extends Seeder
{
    public function run(): void
    {
        $file = database_path('seeders/data/itineraries_draft.json');
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($d)) {
            $this->command?->error('Không đọc được itineraries_draft.json');

            return;
        }
        $dest = DB::table('tbl_destinations')->pluck('destinationId', 'slug')->all();
        $food = DB::table('tbl_foods')->pluck('foodId', 'slug')->all();
        $event = DB::table('tbl_events')->pluck('eventId', 'slug')->all();
        $place = DB::table('tbl_external_places')->selectRaw("placeId, CONCAT(source, ':', externalId) as k")->pluck('placeId', 'k')->all();
        $skipped = [];

        foreach ($d['itineraries'] ?? [] as $r) {
            $existing = DB::table('tbl_itineraries')->where('slug', $r['slug'])->first();
            if ($existing && $existing->reviewStatus !== 'draft') {
                continue;
            }
            $row = [
                'title' => $r['title'], 'days' => $r['days'], 'region' => $r['region'], 'summary' => $r['summary'],
                'license' => 'Tự viết', 'reviewStatus' => 'draft',
            ];
            if ($existing) {
                DB::table('tbl_itineraries')->where('itineraryId', $existing->itineraryId)->update($row + ['updatedAt' => now()]);
                $id = (int) $existing->itineraryId;
            } else {
                $id = (int) DB::table('tbl_itineraries')->insertGetId($row + ['slug' => $r['slug'], 'createdAt' => now()]);
            }
            DB::table('tbl_itinerary_items')->where('itineraryId', $id)->delete();
            $order = [];
            foreach ($r['items'] as $it) {
                $map = match ($it['refType']) { 'destination' => $dest, 'food' => $food, 'event' => $event, default => $place };
                $refId = $map[$it['ref']] ?? null;
                if (!$refId) {
                    $skipped[] = $r['slug'] . ': ' . $it['ref'];
                }
                $order[$it['dayNo']] = ($order[$it['dayNo']] ?? -1) + 1;
                DB::table('tbl_itinerary_items')->insert([
                    'itineraryId' => $id, 'dayNo' => $it['dayNo'], 'orderNo' => $order[$it['dayNo']],
                    'refType' => $refId ? $it['refType'] : null, 'refId' => $refId, 'label' => $it['label'], 'note' => $it['note'] ?? null,
                ]);
            }
        }
        $this->command?->info('Đã nạp lịch trình nháp.' . ($skipped ? ' Không tìm thấy trong DB (giữ nhãn, không liên kết): ' . implode(' | ', $skipped) : ''));
    }
}
