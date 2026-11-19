<?php

namespace Tests\Feature;

use App\Services\Content\PlaceMedia;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Làm giàu địa điểm: ảnh có ghi nguồn, mô tả, ô màu theo nhóm khi thiếu ảnh, nhãn mức tin cậy,
 * ảnh đề xuất từ Commons chờ duyệt (lệnh không tự gắn ảnh), gói mô tả cho 3 điểm đến demo.
 */
class PlaceMediaTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('media.disk', 'public'));
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'hoi-an', 'name' => 'Hội An', 'province' => 'Quảng Nam', 'domain' => 't',
            'description' => 'x', 'latitude' => 15.88, 'longitude' => 108.33, 'isActive' => 1]);
        $this->admin = DB::table('tbl_admin')->insertGetId(['userName' => 'm' . uniqid(), 'passWord' => 'x', 'email' => uniqid() . '@example.com', 'fullName' => 'A', 'address' => 'HN', 'role' => 'manager']);
    }

    private function place(string $name, string $kind = 'historic'): int
    {
        return DB::table('tbl_external_places')->insertGetId(['destinationId' => $this->dest, 'source' => 'manual', 'externalId' => $name, 'name' => $name,
            'category' => 'sight', 'kind' => $kind, 'latitude' => 15.877, 'longitude' => 108.326, 'rank' => 5, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
    }

    public function test_tiles_and_trust_labels_say_only_what_data_supports(): void
    {
        $this->assertSame('eat', PlaceMedia::tile('place', PlaceMedia::groupOfKind('cafe'))['g']);
        $this->assertSame('culture', PlaceMedia::tile('place', PlaceMedia::groupOfKind('museum'))['g']);
        $this->assertSame('see', PlaceMedia::tile('place', PlaceMedia::groupOfKind('kind_la'))['g'], 'kind lạ rơi về Tham quan');
        $this->assertSame('food', PlaceMedia::tile('food')['g']);

        $codes = fn(array $it, array $m) => array_column(PlaceMedia::trust($it, $m), 'code');
        $this->assertSame(['hours_unknown', 'price_ref'], $codes(['type' => 'place', 'priceMin' => 70000], ['hasHours' => false, 'checked' => false]));
        $this->assertSame(['hours_unchecked'], $codes(['type' => 'place', 'priceMin' => 0], ['hasHours' => true, 'checked' => false]), 'miễn phí thì không gắn "giá tham khảo"');
        $this->assertSame([], $codes(['type' => 'place', 'priceMin' => 70000], ['hasHours' => true, 'checked' => true]), 'đã xác minh trong hạn: không nhãn');
        $this->assertSame(['booked'], $codes(['type' => 'place', 'priceMin' => 70000, 'booked' => true], ['hasHours' => false]));
        $this->assertSame([], $codes(['type' => 'food'], []), 'món ăn không có khái niệm giờ mở cửa');
    }

    public function test_admin_saves_description_and_own_photo_with_required_credit(): void
    {
        $id = $this->place('Chùa Cầu');
        $as = $this->withSession(['adminId' => $this->admin]);
        $as->get('/admin/place-data?tab=media')->assertOk()->assertSee('Chùa Cầu')->assertSee('Ảnh &amp; mô tả', false);

        // ảnh tải lên mà không ghi nguồn -> lỗi
        $as->post("/admin/place-data/media/$id", ['image' => UploadedFile::fake()->image('a.jpg', 800, 600)])->assertSessionHasErrors('imageCredit');
        $as->post("/admin/place-data/media/$id", ['description' => '<b>Cây cầu</b> có mái', 'descriptionSource' => 'Travela biên tập', 'website' => 'https://hoianworldheritage.org.vn',
            'image' => UploadedFile::fake()->image('a.jpg', 800, 600), 'imageCredit' => 'Ảnh tự chụp: Khải', 'imageLicense' => 'Ảnh tự chụp'])->assertSessionHas('success');
        $p = DB::table('tbl_external_places')->where('placeId', $id)->first();
        $this->assertSame('Cây cầu có mái', $p->description, 'bỏ thẻ HTML');
        $this->assertSame('discover/places/' . $id . '.webp', $p->coverImage);
        $this->assertSame('Ảnh tự chụp: Khải', $p->imageCredit);
        Storage::disk(config('media.disk', 'public'))->assertExists($p->coverImage);

        $m = PlaceMedia::forItems([['type' => 'place', 'refId' => $id]])['place:' . $id];
        $this->assertNotNull($m['image']);
        $this->assertSame('Cây cầu có mái', $m['blurb']);
        $this->assertSame('Ảnh tự chụp: Khải', $m['credit']);

        // gỡ ảnh
        $as->post("/admin/place-data/media/$id", ['removeImage' => 1, 'description' => 'Cây cầu có mái'])->assertSessionHas('success');
        $this->assertNull(DB::table('tbl_external_places')->where('placeId', $id)->value('coverImage'));
        $this->post("/admin/place-data/media/$id", ['description' => 'x'])->assertRedirect();   // chưa đăng nhập quản trị
    }

    public function test_commons_suggest_keeps_only_free_licenses_and_never_attaches_by_itself(): void
    {
        $id = $this->place('Hội quán Phúc Kiến', 'place_of_worship');
        Http::fake([
            'commons.wikimedia.org/w/api.php?*list=geosearch*' => Http::response(['query' => ['geosearch' => [
                ['title' => 'File:Phuc Kien 1.jpg', 'dist' => 35.2], ['title' => 'File:Map.svg', 'dist' => 10], ['title' => 'File:Phuc Kien 2.jpg', 'dist' => 120]]]]),
            'commons.wikimedia.org/w/api.php?*Phuc%20Kien%201*' => Http::response(['query' => ['pages' => [['imageinfo' => [['thumburl' => 'https://upload.wikimedia.org/a/960px-1.jpg',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Phuc_Kien_1.jpg', 'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-SA 4.0'], 'Artist' => ['value' => '<a>Nguyễn A</a>']]]]]]]]),
            'commons.wikimedia.org/w/api.php?*Phuc%20Kien%202*' => Http::response(['query' => ['pages' => [['imageinfo' => [['thumburl' => 'https://upload.wikimedia.org/a/960px-2.jpg',
                'extmetadata' => ['LicenseShortName' => ['value' => 'All rights reserved']]]]]]]]),
            '*' => Http::response(['query' => ['search' => []]]),
        ]);
        config(['discover.wikipedia.delay_us' => 0]);
        $this->artisan('places:media-suggest', ['--dest' => [$this->dest]])->assertSuccessful();
        $c = DB::table('tbl_place_media_candidates')->where('placeId', $id)->get();
        $this->assertCount(1, $c, 'SVG và ảnh không có giấy phép mở bị loại');
        $this->assertSame('CC BY-SA 4.0', $c[0]->license);
        $this->assertSame('Nguyễn A', $c[0]->artist);
        $this->assertSame(35, (int) $c[0]->distanceM);
        $this->assertNull(DB::table('tbl_external_places')->where('placeId', $id)->value('coverImage'), 'lệnh chỉ đề xuất, không tự gắn ảnh');

        // quản trị viên bấm "Dùng": tải ảnh qua ImageImporter (giả lập), ghi tác giả + giấy phép + trang nguồn
        $mock = \Mockery::mock(\App\Services\Content\ImageImporter::class);
        $mock->shouldReceive('store')->once()->andReturn(['ok' => true, 'note' => '80 KB', 'kb' => 80]);
        $this->app->instance(\App\Services\Content\ImageImporter::class, $mock);
        $this->withSession(['adminId' => $this->admin])->post('/admin/place-data/media/candidate/' . $c[0]->candidateId . '/use')->assertSessionHas('success');
        $p = DB::table('tbl_external_places')->where('placeId', $id)->first();
        $this->assertSame('discover/places/' . $id . '.webp', $p->coverImage);
        $this->assertSame('Nguyễn A / Wikimedia Commons', $p->imageCredit);
        $this->assertSame('CC BY-SA 4.0', $p->imageLicense);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:Phuc_Kien_1.jpg', $p->imageSourceUrl);
        $this->assertSame('used', DB::table('tbl_place_media_candidates')->where('candidateId', $c[0]->candidateId)->value('status'));
    }

    public function test_demo_text_pack_fills_only_empty_descriptions(): void
    {
        $pack = json_decode((string) file_get_contents(base_path('database/seeders/data/demo_place_text.json')), true);
        $city = json_decode((string) file_get_contents(base_path('database/seeders/data/demo_city_pack.json')), true);
        foreach ($city['cities'] as $destId => $rows) {
            $this->assertSame(array_column($rows, 0), array_keys($pack['cities'][$destId]), "mỗi nơi của gói demo #$destId có đúng một mô tả");
        }
        $dest = (int) array_key_first($pack['cities']);
        DB::table('tbl_destinations')->insert(['destinationId' => $dest, 'slug' => 'd' . $dest, 'name' => 'D', 'province' => 'P', 'domain' => 'b', 'description' => 'x', 'latitude' => 21, 'longitude' => 105, 'isActive' => 1]);
        [$n1, $n2] = array_slice(array_keys($pack['cities'][$dest]), 0, 2);
        $mk = fn($n, $d) => DB::table('tbl_external_places')->insertGetId(['destinationId' => $dest, 'source' => 'demo', 'externalId' => md5($n), 'name' => $n, 'category' => 'sight',
            'kind' => 'museum', 'latitude' => 21, 'longitude' => 105, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0, 'description' => $d]);
        $a = $mk($n1, null);
        $b = $mk($n2, 'Mô tả admin đã viết');
        $this->seed(\Database\Seeders\DemoPlaceTextSeeder::class);
        $this->assertSame($pack['cities'][$dest][$n1], DB::table('tbl_external_places')->where('placeId', $a)->value('description'));
        $this->assertSame('Mô tả admin đã viết', DB::table('tbl_external_places')->where('placeId', $b)->value('description'));
    }

    public function test_trip_items_carry_tile_trust_and_booked_label(): void
    {
        $id = $this->place('VinWonders Nam Hội An', 'theme_park');
        DB::table('tbl_external_places')->where('placeId', $id)->update(['priceMin' => 600000]);
        $uid = DB::table('tbl_users')->insertGetId(['username' => 'u', 'email' => 'u@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $trip = app(TripPlanner::class)->create($uid, ['title' => 'Hội An', 'days' => 1, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
        DB::table('tbl_trip_items')->insert(['tripId' => $trip, 'refType' => 'place', 'refId' => $id, 'dayNo' => 1, 'orderNo' => 0]);

        $item = function () use ($trip, $id) {
            $p = app(\App\Services\Planner\TripPayloadBuilder::class)->build(DB::table('tbl_trips')->where('tripId', $trip)->first());
            return collect($p['days'])->flatMap(fn($d) => $d['items'])->firstWhere('refId', $id);
        };
        $it = $item();
        $this->assertSame('fun', $it['tile']['g']);
        $this->assertContains('price_ref', array_column($it['trust'], 'code'));
        $this->assertFalse($it['booked']);

        DB::table('tbl_trip_external')->insert(['tripId' => $trip, 'kind' => 'ticket', 'provider' => 'manual', 'title' => 'Vé VinWonders Nam Hội An 2 người', 'payload' => '{}', 'createdAt' => now()]
            + (\Illuminate\Support\Facades\Schema::hasColumn('tbl_trip_external', 'status') ? ['status' => 'booked'] : []));
        $it = $item();
        $this->assertTrue($it['booked']);
        $this->assertSame(['booked'], array_column($it['trust'], 'code'), 'đã đặt thì không còn "giá tham khảo"');
    }
}
