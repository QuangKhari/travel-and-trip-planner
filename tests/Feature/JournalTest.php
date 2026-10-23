<?php

namespace Tests\Feature;

use App\Services\Content\TravelJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Giai đoạn E: Sổ tay du hành (E1 bộ đếm, bản đồ, tour; E2 huy hiệu). */
class JournalTest extends TestCase
{
    use RefreshDatabase;

    private int $hn;   // Hà Nội, miền Bắc
    private int $hue;  // Huế, miền Trung
    private int $cantho; // Cần Thơ, miền Nam

    protected function setUp(): void
    {
        parent::setUp();
        $this->hn = $this->dest('ha-noi', 'Hà Nội', 'Hà Nội', 'b', 21.0285, 105.8542);
        $this->hue = $this->dest('hue', 'Huế', 'Thừa Thiên Huế', 't', 16.4637, 107.5909);
        $this->cantho = $this->dest('can-tho', 'Cần Thơ', 'Cần Thơ', 'n', 10.0452, 105.7469);
    }

    private function dest(string $slug, string $name, string $province, string $domain, float $lat, float $lng): int
    {
        return DB::table('tbl_destinations')->insertGetId([
            'slug' => $slug,
            'name' => $name,
            'province' => $province,
            'domain' => $domain,
            'description' => 'mô tả ' . $name,
            'latitude' => $lat,
            'longitude' => $lng,
            'isActive' => 1
        ]);
    }

    private function user(string $name = 'u1'): int
    {
        return DB::table('tbl_users')->insertGetId(['username' => $name, 'email' => $name . '@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
    }

    private function userSession(int $uid, string $name = 'u1'): array
    {
        return ['userId' => $uid, 'username' => $name];
    }

    private function visit(int $uid, int $destId): void
    {
        DB::table('tbl_user_marks')->insert(['userId' => $uid, 'type' => 'visited', 'refType' => 'destination', 'refId' => $destId]);
    }

    private function tour(?int $destId, string $title = 'Tour thử', int $daysAhead = 10): int
    {
        return DB::table('tbl_tours')->insertGetId([
            'title' => $title,
            'time' => '3 ngày 2 đêm',
            'description' => '<p>mô tả</p>',
            'quantity' => 10,
            'priceAdult' => 1000000,
            'priceChild' => 500000,
            'destination' => 'Điểm đến',
            'destinationId' => $destId,
            'domain' => 't',
            'availability' => 1,
            'startDate' => now()->addDays($daysAhead)->toDateString(),
            'endDate' => now()->addDays($daysAhead + 2)->toDateString(),
        ]);
    }

    private function booking(int $uid, int $tourId, string $status): int
    {
        static $n = 0;
        $n++;

        return DB::table('tbl_booking')->insertGetId([
            'tourId' => $tourId,
            'userId' => $uid,
            'fullName' => 'K',
            'email' => 'k@example.com',
            'phoneNumber' => '0900000000',
            'address' => 'HN',
            'numChildren' => 0,
            'numAdults' => 1,
            'totalPrice' => 1000000,
            'bookingStatus' => $status,
            'bookingCode' => 'JT' . $n . uniqid()
        ]);
    }

    public function test_khach_chua_dang_nhap_bi_chuyen_ve_trang_dang_nhap(): void
    {
        $this->get('/so-tay-du-hanh')->assertRedirect(route('login'));
    }

    public function test_so_tay_trong_van_hien_duoc(): void
    {
        $uid = $this->user();
        $r = $this->withSession($this->userSession($uid))->get('/so-tay-du-hanh')->assertOk();
        $r->assertSee('Sổ tay du hành')->assertSee('Bản đồ còn trống')->assertSee('Bạn chưa đặt tour nào');
        $r->assertSee('0/10');                                  // chưa đạt huy hiệu nào
        $r->assertSee('noindex', false);                        // trang riêng: không đưa vào chỉ mục
    }

    public function test_da_den_hien_tren_ban_do_va_bo_dem_va_huy_hieu(): void
    {
        $uid = $this->user();
        $this->visit($uid, $this->hn);
        $this->visit($uid, $this->hue);

        $r = $this->withSession($this->userSession($uid))->get('/so-tay-du-hanh')->assertOk();
        $this->assertSame(2, substr_count($r->getContent(), 'jn-pin jn-pin--done'));
        $r->assertSee('Thừa Thiên Huế')->assertSee('Bước chân đầu tiên');
        $r->assertDontSee('Bản đồ còn trống');

        $badges = collect(\App\Services\Content\Badges::evaluate((new \App\Services\Content\Badges())->metrics($uid)))->keyBy('key');
        $this->assertTrue($badges['buoc-chan-dau']['earned']);
        $this->assertFalse($badges['nguoi-di-nhieu']['earned']);     // mới 2/5
        $this->assertFalse($badges['xuyen-viet']['earned']);         // mới 2 miền
        $this->visit($uid, $this->cantho);
        $badges = collect(\App\Services\Content\Badges::evaluate((new \App\Services\Content\Badges())->metrics($uid)))->keyBy('key');
        $this->assertTrue($badges['xuyen-viet']['earned']);          // đủ 3 miền
    }

    public function test_nhieu_diem_den_cung_tinh_chi_to_mot_vet(): void
    {
        $uid = $this->user();
        $a = $this->dest('sa-pa', 'Sa Pa', 'Lào Cai', 'b', 22.3364, 103.8438);
        $b = $this->dest('bac-ha', 'Bắc Hà', 'Lào Cai', 'b', 22.5333, 104.2833);
        $this->visit($uid, $a);
        $this->visit($uid, $b);

        $p = app(TravelJournal::class)->provinces($uid);
        $this->assertCount(1, $p);
        $this->assertSame('Lào Cai', $p[0]['province']);
        $this->assertEqualsCanonicalizing(['Sa Pa', 'Bắc Hà'], $p[0]['places']);
        $this->assertGreaterThan(0, $p[0]['x']);
        $this->assertLessThan(100, $p[0]['y']);
    }

    public function test_tour_da_dat_chia_sap_di_va_da_di_va_bo_don_huy(): void
    {
        $uid = $this->user();
        $this->booking($uid, $this->tour($this->hue, 'Tour Huế', 5), 'y');         // sắp đi
        $this->booking($uid, $this->tour($this->cantho, 'Tour Cần Thơ', -20), 'f'); // đã đi
        $this->booking($uid, $this->tour($this->hn, 'Tour Hà Nội'), 'c');          // đã hủy

        $r = $this->withSession($this->userSession($uid))->get('/so-tay-du-hanh')->assertOk();
        $r->assertSee('Tour Huế')->assertSee('Tour Cần Thơ')->assertDontSee('Tour Hà Nội');
        $r->assertSee('Sắp đi')->assertSee('Đã đi');

        $p = collect(app(TravelJournal::class)->provinces($uid))->keyBy('province');
        $this->assertSame('planned', $p['Thừa Thiên Huế']['state']);
        $this->assertSame('done', $p['Cần Thơ']['state']);
        $this->assertArrayNotHasKey('Hà Nội', $p->all());                               // đơn hủy không tô

        $badges = collect(\App\Services\Content\Badges::evaluate((new \App\Services\Content\Badges())->metrics($uid)))->keyBy('key');
        $this->assertTrue($badges['khach-travela']['earned']);                         // có 1 tour hoàn thành
    }

    public function test_da_den_thang_hon_sap_di_khi_cung_tinh(): void
    {
        $uid = $this->user();
        $this->visit($uid, $this->hue);                                                // đã đến
        $this->booking($uid, $this->tour($this->hue), 'n');                            // lại có tour chờ xác nhận
        $p = app(TravelJournal::class)->provinces($uid);
        $this->assertCount(1, $p);
        $this->assertSame('done', $p[0]['state']);
    }

    public function test_khong_lo_du_lieu_cua_nguoi_khac(): void
    {
        $a = $this->user('a');
        $b = $this->user('b');
        $this->visit($b, $this->hn);
        $this->booking($b, $this->tour($this->hn, 'Tour của B'), 'y');

        $r = $this->withSession($this->userSession($a, 'a'))->get('/so-tay-du-hanh')->assertOk();
        $r->assertDontSee('Tour của B')->assertSee('Bản đồ còn trống');
    }

    public function test_mon_da_thu_tinh_so_mien(): void
    {
        $uid = $this->user();
        foreach ([['pho', $this->hn], ['bun-bo', $this->hue], ['hu-tieu', $this->cantho]] as [$slug, $dest]) {
            $id = DB::table('tbl_foods')->insertGetId([
                'slug' => $slug,
                'name' => 'Món ' . $slug,
                'destinationId' => $dest,
                'category' => 'bún phở',
                'description' => 'Mô tả đủ dài cho món',
                'license' => 'Tự viết',
                'reviewStatus' => 'published'
            ]);
            DB::table('tbl_user_marks')->insert(['userId' => $uid, 'type' => 'tried', 'refType' => 'food', 'refId' => $id]);
        }
        $m = (new \App\Services\Content\Badges())->metrics($uid);
        $this->assertSame(3, $m['tried']);
        $this->assertSame(3, $m['tried_domains']);
    }
}
