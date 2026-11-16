<?php

namespace Tests\Feature;

use App\Services\Content\PlaceImport;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt C mục 19: nhập địa điểm theo lô, kiểm tra từng dòng, dò trùng, chỉ vào kho khi có người duyệt. */
class PlaceImportTest extends TestCase
{
    use RefreshDatabase;

    private int $dest;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dest = (int) DB::table('tbl_destinations')->insertGetId([
            'slug' => 'da-lat-t', 'name' => 'Đà Lạt', 'province' => 'Lâm Đồng', 'domain' => 'n', 'description' => 'Thành phố ngàn hoa',
            'latitude' => 11.9404, 'longitude' => 108.4583, 'isActive' => 1,
        ]);
        DB::table('tbl_external_places')->insert([
            'destinationId' => $this->dest, 'source' => 'demo', 'externalId' => 'x1', 'name' => 'Ga Đà Lạt', 'category' => 'sight', 'kind' => 'historic',
            'latitude' => 11.94153, 'longitude' => 108.4547, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(),
        ]);
        $this->admin = (int) DB::table('tbl_admin')->insertGetId([
            'userName' => 'imp_mgr', 'passWord' => PasswordHasher::make('matkhau123'), 'email' => 'imp@t.test', 'fullName' => 'Quản lý Nhập', 'address' => 'HN', 'role' => 'manager',
        ]);
    }

    private function csv(): string
    {
        return implode("\n", [
            'dest,name,category,kind,lat,lng,address,duration,price_min,price_max,indoor,hours,website,description,source,source_url',
            'da-lat-t,Thiền viện Trúc Lâm,sight,place_of_worship,11.9076,108.4370,Hồ Tuyền Lâm,60,0,0,0,Mo-Su 07:00-17:00,,Thiền viện bên hồ.,Khảo sát thực địa,',
            'da-lat-t,Nhà Ga Đà Lạt,sight,,11.94160,108.45475,1 Quang Trung,45,10000,,0,,https://dalat.example,,Trang chính thức,https://dalat.example/ga',
            'da-lat-t,Quán ngoài vùng,food,restaurant,10.7769,106.7009,,30,,,,Mo-Su 08:00-20:00,,,Google Maps,',
            'khong-co,Điểm lạ,sight,,11.9,108.4,,,,,,,,,Khảo sát,',
            'da-lat-t,Đảo tọa độ,sight,,108.45,11.94,,,,,,,,,Khảo sát,',
            'da-lat-t,Không nguồn,food,cafe,11.95,108.44,,,50000,20000,,,,,,',
            'da-lat-t,Thiền viện Trúc Lâm,sight,,11.9077,108.4371,,,,,,,,,Khảo sát,',
        ]);
    }

    public function test_stage_checks_rows_and_writes_nothing_to_places(): void
    {
        $before = DB::table('tbl_external_places')->count();
        $r = PlaceImport::stage($this->csv(), 'da-lat.csv', $this->admin);
        $this->assertNull($r['error']);
        $this->assertSame(7, $r['total']);
        $this->assertSame($before, DB::table('tbl_external_places')->count());

        $rows = DB::table('tbl_import_rows')->where('batchId', $r['batchId'])->orderBy('line')->get()->keyBy('line');
        $codes = fn($line) => array_column(json_decode($rows[$line]->issues, true), 'code');

        $this->assertSame('ok', $rows[2]->level);
        $this->assertContains('dup_existing', $codes(3));             // "Nhà Ga Đà Lạt" sát "Ga Đà Lạt"
        $this->assertNotNull($rows[3]->matchPlaceId);
        $this->assertContains('far', $codes(4));                      // tọa độ ở TP.HCM
        $this->assertContains('no_dest', $codes(5));
        $this->assertContains('bad_coords', $codes(6));               // đảo vĩ / kinh độ
        $this->assertContains('no_source', $codes(7));
        $this->assertContains('price_order', $codes(7));
        $this->assertContains('dup_batch', $codes(8));
        $this->assertSame(['error', 'error', 'error'], [$rows[5]->level, $rows[6]->level, $rows[7]->level]);
    }

    public function test_approve_creates_or_merges_and_skips_errors(): void
    {
        $r = PlaceImport::stage($this->csv(), 'da-lat.csv', $this->admin);
        $ids = DB::table('tbl_import_rows')->where('batchId', $r['batchId'])->pluck('rowId', 'line');

        $out = PlaceImport::approve($r['batchId'], [$ids[2], $ids[3], $ids[5]], $this->admin);
        $this->assertSame(['created' => 1, 'merged' => 1, 'skipped' => 1], $out);

        $new = DB::table('tbl_external_places')->where('name', 'Thiền viện Trúc Lâm')->first();
        $this->assertSame('import', $new->source);
        $this->assertSame('reviewed', $new->reviewStatus);           // chưa hiện cho khách khi không tick "hiển thị ngay"
        $this->assertSame('Khảo sát thực địa', $new->factsSource);
        $this->assertTrue(DB::table('tbl_opening_hours')->where('refType', 'place')->where('refId', $new->placeId)->exists());

        $ga = DB::table('tbl_external_places')->where('externalId', 'x1')->first();
        $this->assertSame('1 Quang Trung', $ga->address);              // ô trống được bổ sung
        $this->assertSame('historic', $ga->kind);                      // ô đã có giữ nguyên
        $this->assertSame(45, (int) $ga->durationMinutes);
        $this->assertSame(1, DB::table('tbl_external_places')->where('destinationId', $this->dest)->where('name', 'like', '%Ga Đà Lạt%')->count());

        // duyệt lại cùng dòng: không tạo thêm
        $again = PlaceImport::approve($r['batchId'], [$ids[2]], $this->admin, true);
        $this->assertSame(0, $again['created']);
    }

    public function test_publish_also_publishes_the_matched_existing_place(): void
    {
        DB::table('tbl_external_places')->where('externalId', 'x1')->update(['reviewStatus' => 'draft']);
        $r = PlaceImport::stage($this->csv(), 'da-lat.csv', $this->admin);
        $ids = DB::table('tbl_import_rows')->where('batchId', $r['batchId'])->pluck('rowId', 'line');
        $out = PlaceImport::approve($r['batchId'], [$ids[3]], $this->admin, true);
        $this->assertSame(1, $out['merged']);
        $this->assertSame('published', DB::table('tbl_external_places')->where('externalId', 'x1')->value('reviewStatus'));
    }

    public function test_admin_screens_upload_review_and_reject(): void
    {
        $s = ['adminId' => $this->admin, 'adminRole' => 'manager'];
        $this->withSession($s)->get('/admin/nhap-dia-diem')->assertOk()->assertSee('Nhập địa điểm');
        $this->get('/admin/nhap-dia-diem/tep-mau')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $file = UploadedFile::fake()->createWithContent('da-lat.csv', $this->csv());
        $res = $this->post('/admin/nhap-dia-diem', ['file' => $file, 'note' => 'Khảo sát 11/2026']);
        $bid = (int) DB::table('tbl_import_batches')->max('batchId');
        $res->assertRedirect(route('admin.place-import.show', $bid));

        $this->get("/admin/nhap-dia-diem/$bid")->assertOk()->assertSee('Thiền viện Trúc Lâm')->assertSee('Có thể trùng');
        $this->get("/admin/nhap-dia-diem/$bid?loc=error")->assertOk()->assertSee('Thiếu nguồn dữ liệu');

        $row = (int) DB::table('tbl_import_rows')->where('batchId', $bid)->where('line', 2)->value('rowId');
        $this->post("/admin/nhap-dia-diem/$bid/quyet-dinh", ['rows' => [$row], 'action' => 'reject'])->assertSessionHasErrors('note');
        $this->post("/admin/nhap-dia-diem/$bid/quyet-dinh", ['rows' => [$row], 'action' => 'reject', 'note' => 'Đã có trong lô khác']);
        $this->assertSame('rejected', DB::table('tbl_import_rows')->where('rowId', $row)->value('status'));

        $row4 = (int) DB::table('tbl_import_rows')->where('batchId', $bid)->where('line', 4)->value('rowId');
        $this->post("/admin/nhap-dia-diem/$bid/quyet-dinh", ['rows' => [$row4], 'action' => 'approve', 'publish' => 1]);
        $this->assertSame('published', DB::table('tbl_external_places')->where('name', 'Quán ngoài vùng')->value('reviewStatus'));

        // nhân viên không vào được
        $staff = (int) DB::table('tbl_admin')->insertGetId(['userName' => 'imp_st', 'passWord' => 'x', 'email' => 'st@t.test', 'fullName' => 'NV', 'address' => 'HN', 'role' => 'staff']);
        $this->withSession(['adminId' => $staff, 'adminRole' => 'staff'])->get('/admin/nhap-dia-diem')->assertRedirect();
    }

    public function test_json_and_semicolon_csv_with_vietnamese_headers(): void
    {
        $json = json_encode([['dest' => 'da-lat-t', 'name' => 'Đồi chè Cầu Đất', 'category' => 'sight', 'lat' => 11.87, 'lng' => 108.56, 'source' => 'Khảo sát', 'hours' => 'Mo-Su 07:00-17:00']]);
        $this->assertSame(1, PlaceImport::stage($json, 'a.json', null)['total']);

        $csv = "\xEF\xBB\xBFĐiểm đến;Tên;Nhóm;Vĩ độ;Kinh độ;Nguồn;Giờ mở cửa\nda-lat-t;Chợ Đà Lạt;ăn uống;11,9430;108,4370;Khảo sát;Mo-Su 06:00-22:00\n";
        $r = PlaceImport::stage($csv, 'b.csv', null);
        $this->assertSame(1, $r['total']);
        $row = DB::table('tbl_import_rows')->where('batchId', $r['batchId'])->first();
        $d = json_decode($row->data, true);
        $this->assertSame('food', $d['category']);
        $this->assertSame(11.943, $d['lat']);
        $this->assertNotSame('error', $row->level);

        $this->assertNotNull(PlaceImport::stage("foo,bar\n1,2", 'c.csv', null)['error']);   // thiếu cột name
    }
}
