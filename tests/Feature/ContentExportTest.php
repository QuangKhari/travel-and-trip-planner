<?php

namespace Tests\Feature;

use App\Services\Content\ContentExporter;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentExportTest extends TestCase
{
    use RefreshDatabase;

    /** Xuất -> xóa sạch nội dung -> nạp lại: dữ liệu và liên kết (bằng slug) phải giữ nguyên; bản nháp không bị xuất. */
    public function test_xuat_roi_nap_lai_giu_nguyen_noi_dung(): void
    {
        $hn = DB::table('tbl_destinations')->insertGetId([
            'slug' => 'ha-noi',
            'name' => 'Hà Nội',
            'province' => 'Hà Nội',
            'domain' => 'b',
            'description' => 'mô tả',
            'latitude' => 21.0,
            'longitude' => 105.8,
            'isActive' => 1
        ]);
        DB::table('tbl_destination_months')->insert(['destinationId' => $hn, 'month' => 10, 'level' => 'best', 'note' => 'Ít mưa', 'source' => 'rule']);
        DB::table('tbl_foods')->insert([
            ['slug' => 'pho', 'name' => 'Phở', 'destinationId' => $hn, 'description' => 'Món phở', 'license' => 'Tự viết', 'reviewStatus' => 'published'],
            ['slug' => 'nhap', 'name' => 'Nháp', 'destinationId' => $hn, 'description' => 'chưa duyệt', 'license' => 'Nháp', 'reviewStatus' => 'draft'],
        ]);
        DB::table('tbl_events')->insert([
            'slug' => 'tet',
            'name' => 'Tết',
            'destinationId' => null,
            'isLunar' => 1,
            'lunarMonth' => 1,
            'lunarDay' => 1,
            'durationDays' => 5,
            'reviewStatus' => 'published'
        ]);
        DB::table('tbl_external_places')->insert([
            'destinationId' => $hn,
            'source' => 'overpass',
            'externalId' => 'node/1',
            'name' => 'Hồ Gươm',
            'category' => 'see',
            'latitude' => 21.028,
            'longitude' => 105.852,
            'syncedAt' => now(),
            'reviewStatus' => 'published'
        ]);
        $it = DB::table('tbl_itineraries')->insertGetId(['slug' => '1-ngay', 'title' => '1 ngày', 'days' => 1, 'reviewStatus' => 'published']);
        $foodId = DB::table('tbl_foods')->where('slug', 'pho')->value('foodId');
        DB::table('tbl_itinerary_items')->insert(['itineraryId' => $it, 'dayNo' => 1, 'orderNo' => 1, 'refType' => 'food', 'refId' => $foodId, 'label' => 'Ăn phở']);

        $path = tempnam(sys_get_temp_dir(), 'content') . '.json';
        file_put_contents($path, json_encode(app(ContentExporter::class)->build('published'), JSON_UNESCAPED_UNICODE));

        $json = json_decode(file_get_contents($path), true);
        $this->assertSame(['pho'], array_column($json['foods'], 'slug'));          // bản nháp không được xuất
        $this->assertSame('ha-noi', $json['foods'][0]['destination']);

        // Dọn nội dung (giữ điểm đến, như một máy đích đã có điểm đến) rồi nạp lại
        foreach (['tbl_itinerary_items', 'tbl_itineraries', 'tbl_external_places', 'tbl_events', 'tbl_foods', 'tbl_destination_months'] as $t) {
            DB::table($t)->delete();
        }
        app(ContentSeeder::class)->useFile($path)->run();

        $this->assertSame(1, DB::table('tbl_foods')->count());
        $this->assertSame('best', DB::table('tbl_destination_months')->where('month', 10)->value('level'));
        $this->assertNull(DB::table('tbl_events')->where('slug', 'tet')->value('destinationId'));
        $newFood = DB::table('tbl_foods')->where('slug', 'pho')->first();
        $this->assertSame($hn, (int) $newFood->destinationId);
        $item = DB::table('tbl_itinerary_items')->first();
        $this->assertSame('food', $item->refType);
        $this->assertSame((int) $newFood->foodId, (int) $item->refId);      // liên kết khôi phục theo slug dù id đổi

        // Chạy lại lần nữa không nhân đôi
        app(ContentSeeder::class)->useFile($path)->run();
        $this->assertSame(1, DB::table('tbl_foods')->count());
        $this->assertSame(1, DB::table('tbl_external_places')->count());
        $this->assertSame(1, DB::table('tbl_itinerary_items')->count());
        @unlink($path);
    }
}
