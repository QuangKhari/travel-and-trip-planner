<?php

namespace Tests\Feature;

use App\Services\Planner\NoteImport;
use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Dán ghi chú -> tách tên -> khớp kho địa điểm thật -> trả về để đưa vào "Đã lưu". AI không được tạo địa điểm. */
class NoteImportTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => false]);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'da-lat', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'n',
            'description' => 'x', 'latitude' => 11.94, 'longitude' => 108.44, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'fullName' => 'A', 'isActive' => 'y']);
        foreach ([['Dinh Bảo Đại (Dinh III)', 'historic'], ['Bánh căn Nhà Chung', 'restaurant'], ['Núi Langbiang', 'peak'], ['Chợ đêm Đà Lạt', 'attraction'],
                     ['Cafe Tùng', 'cafe'], ['Dinh I Đà Lạt', 'historic'], ['Hồ Tuyền Lâm', 'viewpoint'], ['Hồ Than Thở', 'viewpoint'], ['Lẩu bò Ba Toa Quán Gỗ', 'restaurant']] as [$n, $k]) {
            DB::table('tbl_external_places')->insert(['destinationId' => $this->dest, 'source' => 'demo', 'externalId' => md5($n), 'name' => $n, 'category' => 'sight',
                'kind' => $k, 'latitude' => 11.94, 'longitude' => 108.44, 'rank' => 5, 'reviewStatus' => 'published', 'syncedAt' => now(), 'isMustTry' => 0]);
        }
    }

    public function test_rules_extract_names_and_ai_names_must_appear_in_text(): void
    {
        $names = NoteImport::rules('Đà Lạt nhớ ghé Dinh Bảo Đại, ăn bánh căn Nhà Chung, chiều lên Langbiang rồi tối đi chợ đêm. Sáng hôm sau cafe Tùng');
        $this->assertContains('bánh căn Nhà Chung', $names);
        $this->assertContains('Langbiang', $names);
        $this->assertContains('chợ đêm', $names);
        $this->assertContains('cafe Tùng', $names);
        // tên AI tự thêm (không có trong ghi chú) bị bỏ
        $this->assertSame(['Dinh Bảo Đại'], NoteImport::grounded(['Dinh Bảo Đại', 'Hồ Gươm', 'x'], 'nhớ ghé dinh bao dai nhé'));
    }

    public function test_endpoint_matches_real_places_lists_unsure_and_missing_without_writing(): void
    {
        $trip = app(TripPlanner::class)->create($this->uid, ['title' => 'Đà Lạt', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
        DB::table('tbl_trips')->where('tripId', $trip)->update(['destinationId' => $this->dest]);
        $before = DB::table('tbl_trip_items')->count();
        $j = $this->withSession(['userId' => $this->uid, 'username' => 'a'])->postJson("/len-ke-hoach/$trip/dan-ghi-chu", [
            'text' => "Đà Lạt nhớ ghé Dinh Bảo Đại, ăn bánh căn Nhà Chung, chiều lên Langbiang.\nTối chợ đêm. Ghé Dinh Đà Lạt. Ăn ở quán Ốc Bà Tư",
        ])->assertOk()->json();
        $this->assertSame('rules', $j['engine']);
        $names = array_column($j['matched'], 'name');
        foreach (['Dinh Bảo Đại (Dinh III)', 'Bánh căn Nhà Chung', 'Núi Langbiang', 'Chợ đêm Đà Lạt'] as $n) {
            $this->assertContains($n, $names);
        }
        $this->assertNotContains('Dinh I Đà Lạt', $names);
        $this->assertSame('eat', collect($j['matched'])->firstWhere('name', 'Bánh căn Nhà Chung')['tile']['g']);
        // "Dinh Đà Lạt": gần với vài nơi nhưng không chắc -> để người dùng chọn, lựa chọn đầu là Dinh I
        $unsure = collect($j['unsure'])->firstWhere('text', 'Dinh Đà Lạt');
        $this->assertNotNull($unsure);
        $this->assertSame('Dinh I Đà Lạt', $unsure['options'][0]['name']);
        $this->assertContains('quán Ốc Bà Tư', $j['missing'], 'nơi không có trong dữ liệu: liệt kê, không tự tạo');
        $this->assertSame($before, DB::table('tbl_trip_items')->count(), 'endpoint không ghi gì');

        // người khác / người chỉ xem: 404; ghi chú rỗng: 422
        $other = DB::table('tbl_users')->insertGetId(['username' => 'b', 'email' => 'b@example.com', 'password' => 'x', 'fullName' => 'B', 'isActive' => 'y']);
        $this->withSession(['userId' => $other, 'username' => 'b'])->postJson("/len-ke-hoach/$trip/dan-ghi-chu", ['text' => 'Dinh Bảo Đại'])->assertNotFound();
        $this->withSession(['userId' => $this->uid, 'username' => 'a'])->postJson("/len-ke-hoach/$trip/dan-ghi-chu", ['text' => ''])->assertStatus(422);
    }
}
