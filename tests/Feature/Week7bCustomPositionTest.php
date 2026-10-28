<?php

namespace Tests\Feature;

use App\Services\Planner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Mục tự thêm có vị trí (latitude / longitude / source): lưu, đọc lại, vẽ bản đồ, tính quãng đường, kiểm tra dữ liệu. */
class Week7bCustomPositionTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;
    private int $uid;
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->s = ['userId' => $this->uid, 'username' => 'u1'];
    }

    private function trip(): int
    {
        return app(TripPlanner::class)->create($this->uid, ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'startDate' => null, 'budget' => null]);
    }

    private function food(): int
    {
        return DB::table('tbl_foods')->insertGetId(['slug' => 'pho', 'name' => 'Phở', 'destinationId' => $this->hn, 'category' => 'bún phở',
            'description' => 'Mô tả đủ dài cho món phở', 'license' => 'Tự viết', 'reviewStatus' => 'published']);
    }

    private function body(array $items): array
    {
        return ['title' => 'Chuyến thử', 'days' => 2, 'groupSize' => 2, 'items' => $items];
    }

    private function saveItems(int $id, array $items)
    {
        return $this->withSession($this->s)->postJson("/len-ke-hoach/$id/luu", $this->body($items));
    }

    private function loadPayload(int $id): array
    {
        return app(TripPlanner::class)->payload(app(TripPlanner::class)->owned($this->uid, $id));
    }

    public function test_position_is_saved_and_loaded(): void
    {
        $id = $this->trip();
        $this->saveItems($id, [['type' => 'note', 'label' => 'Quán bún chả Hương Liên', 'day' => 1, 'lat' => 21.0172345, 'lng' => 105.8601234, 'source' => 'link']])->assertOk();

        $row = DB::table('tbl_trip_items')->where('tripId', $id)->first();
        $this->assertEqualsWithDelta(21.0172345, (float) $row->latitude, 1e-7);
        $this->assertEqualsWithDelta(105.8601234, (float) $row->longitude, 1e-7);
        $this->assertSame('link', $row->source);

        $it = $this->loadPayload($id)['days'][0]['items'][0];
        $this->assertEqualsWithDelta(21.0172345, $it['lat'], 1e-7);
        $this->assertEqualsWithDelta(105.8601234, $it['lng'], 1e-7);
        $this->assertSame('link', $it['source']);
        $this->assertStringContainsString('google.com/maps?q=21.0172345,105.8601234', $it['url']);
    }

    public function test_source_defaults_to_map_and_zero_coordinate_is_a_valid_position(): void
    {
        $id = $this->trip();
        $this->saveItems($id, [['type' => 'transport', 'label' => 'Bến phà xích đạo', 'day' => 1, 'lat' => 0, 'lng' => 106.5]])->assertOk();
        $row = DB::table('tbl_trip_items')->where('tripId', $id)->first();
        $this->assertNotNull($row->latitude);
        $this->assertEquals(0, (float) $row->latitude);
        $this->assertSame('map', $row->source);
    }

    public function test_item_without_position_stays_without_position(): void
    {
        $id = $this->trip();
        $this->saveItems($id, [['type' => 'note', 'label' => 'Khách sạn', 'day' => 1]])->assertOk();
        $row = DB::table('tbl_trip_items')->where('tripId', $id)->first();
        $this->assertNull($row->latitude);
        $this->assertNull($row->longitude);
        $this->assertNull($row->source);

        $it = $this->loadPayload($id)['days'][0]['items'][0];
        $this->assertNull($it['lat']);
        $this->assertNull($it['url']);
    }

    public function test_position_can_be_removed_by_saving_again(): void
    {
        $id = $this->trip();
        $this->saveItems($id, [['type' => 'note', 'label' => 'Quán', 'day' => 1, 'lat' => 21.0, 'lng' => 105.8, 'source' => 'map']])->assertOk();
        $this->saveItems($id, [['type' => 'note', 'label' => 'Quán', 'day' => 1, 'lat' => null, 'lng' => null, 'source' => null]])->assertOk();
        $row = DB::table('tbl_trip_items')->where('tripId', $id)->first();
        $this->assertNull($row->latitude);
        $this->assertNull($row->source);
    }

    public function test_position_adds_legs_and_distance_to_the_day(): void
    {
        $f = $this->food();
        $id = $this->trip();
        // Món ở Hà Nội (tọa độ lấy từ tâm điểm đến) rồi một nơi tự thêm ở Huế
        $this->saveItems($id, [
            ['type' => 'food', 'refId' => $f, 'day' => 1],
            ['type' => 'note', 'label' => 'Khách sạn ở Huế', 'day' => 1, 'lat' => 16.4637, 'lng' => 107.5909, 'source' => 'map'],
        ])->assertOk();
        $day = $this->loadPayload($id)['days'][0];
        $this->assertCount(1, $day['legs']);
        $this->assertSame('Khách sạn ở Huế', $day['legs'][0]['to']);
        $this->assertGreaterThan(500, $day['km']);                 // Hà Nội - Huế đường chim bay ~540 km, nhân 1,3
        $this->assertGreaterThan(300, $day['minutes']);
        $this->assertTrue(collect($this->loadPayload($id)['warnings'])->contains(fn($w) => str_contains($w, 'di chuyển khoảng')));
    }

    public function test_item_without_position_adds_no_leg(): void
    {
        $f = $this->food();
        $id = $this->trip();
        $this->saveItems($id, [
            ['type' => 'food', 'refId' => $f, 'day' => 1],
            ['type' => 'note', 'label' => 'Khách sạn (chưa có vị trí)', 'day' => 1],
        ])->assertOk();
        $day = $this->loadPayload($id)['days'][0];
        $this->assertSame([], $day['legs']);
        $this->assertSame(0, $day['km']);
    }

    public function test_half_a_position_is_rejected_and_nothing_is_written(): void
    {
        $id = $this->trip();
        $this->saveItems($id, [['type' => 'note', 'label' => 'A', 'day' => 1, 'lat' => 21.0]])->assertStatus(422);
        $this->saveItems($id, [['type' => 'note', 'label' => 'A', 'day' => 1, 'lng' => 105.0]])->assertStatus(422);
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->count());
    }

    public function test_out_of_range_or_bad_values_are_rejected(): void
    {
        $id = $this->trip();
        foreach ([
            ['lat' => 91, 'lng' => 105],
            ['lat' => -91, 'lng' => 105],
            ['lat' => 21, 'lng' => 181],
            ['lat' => 21, 'lng' => -181],
            ['lat' => 'abc', 'lng' => 105],
            ['lat' => 21, 'lng' => 105, 'source' => 'hack'],
        ] as $pos) {
            $this->saveItems($id, [['type' => 'note', 'label' => 'A', 'day' => 1] + $pos])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('tbl_trip_items')->where('tripId', $id)->count());
    }

    public function test_content_items_ignore_client_sent_position(): void
    {
        $f = $this->food();
        $id = $this->trip();
        $this->saveItems($id, [['type' => 'food', 'refId' => $f, 'day' => 1, 'lat' => 1.5, 'lng' => 2.5, 'source' => 'map']])->assertOk();
        $row = DB::table('tbl_trip_items')->where('tripId', $id)->first();
        $this->assertNull($row->latitude);
        $this->assertNull($row->source);
        // tọa độ của món vẫn là tâm điểm đến, không phải giá trị khách gửi
        $it = $this->loadPayload($id)['days'][0]['items'][0];
        $this->assertEqualsWithDelta(21.0285, $it['lat'], 1e-6);
    }

    public function test_editor_page_exposes_position_to_the_script(): void
    {
        $id = $this->trip();
        $this->saveItems($id, [['type' => 'note', 'label' => 'Quán cà phê', 'day' => 1, 'lat' => 21.0301, 'lng' => 105.8501, 'source' => 'link']])->assertOk();
        $this->withSession($this->s)->get("/len-ke-hoach/$id")->assertOk()
            ->assertSee('Quán cà phê', false)
            ->assertSee('"lat":21.0301', false)
            ->assertSee('id="tpCustomLink"', false)
            ->assertSee('id="tpPickMap"', false);
    }

    public function test_item_rules_shared_by_web_and_api_include_position_fields(): void
    {
        // trang web và /api/v1 dùng chung itemRules(), nên cùng một quy tắc về vị trí
        $rules = TripPlanner::itemRules();
        $this->assertArrayHasKey('items.*.lat', $rules);
        $this->assertArrayHasKey('items.*.lng', $rules);
        $this->assertArrayHasKey('items.*.source', $rules);
    }
}
