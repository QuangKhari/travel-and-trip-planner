<?php

namespace Tests\Unit;

use App\Services\Planner\PlaceFinder;
use Tests\TestCase;

/** Phần thuần của PlaceFinder: đọc dữ liệu OSM mẫu (tự viết, không gọi mạng), quy đổi giá, lọc và sắp xếp. */
class PlaceFinderLogicTest extends TestCase
{
    private function osm(): array
    {
        return ['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 16.06, 'lon' => 108.22, 'tags' => ['name' => 'Khách sạn A', 'tourism' => 'hotel', 'stars' => '4', 'website' => 'https://a.test']],
            ['type' => 'node', 'id' => 2, 'lat' => 16.061, 'lon' => 108.221, 'tags' => ['name' => 'Khách sạn B', 'tourism' => 'hotel', 'stars' => '2']],
            ['type' => 'way', 'id' => 3, 'center' => ['lat' => 16.07, 'lon' => 108.23], 'tags' => ['name' => 'Hostel C', 'tourism' => 'hostel']],
            ['type' => 'node', 'id' => 4, 'lat' => 16.09, 'lon' => 108.25, 'tags' => ['name' => 'Khách sạn D', 'tourism' => 'hotel']],
            ['type' => 'node', 'id' => 5, 'lat' => 16.2, 'lon' => 108.4, 'tags' => ['name' => 'Khách sạn Xa', 'tourism' => 'hotel', 'stars' => '4']],
            ['type' => 'node', 'id' => 6, 'tags' => ['name' => 'Không có tọa độ', 'tourism' => 'hotel']],
            ['type' => 'node', 'id' => 7, 'lat' => 16.0, 'lon' => 108.0, 'tags' => ['tourism' => 'hotel']],
        ]];
    }

    private function list(): array
    {
        $l = PlaceFinder::parseOverpass($this->osm(), 'hotel');
        foreach ($l as &$it) {
            $it['distM'] = PlaceFinder::distanceM(16.06, 108.22, $it['lat'], $it['lng']);
        }

        return $l;
    }

    private function names(array $in): string
    {
        return implode(',', array_map(fn($x) => $x['name'], PlaceFinder::apply($this->list(), PlaceFinder::criteria($in))['list']));
    }

    public function test_đọc_osm_bỏ_mục_thiếu_tên_hoặc_vị_trí_và_suy_mức_giá(): void
    {
        $l = PlaceFinder::parseOverpass($this->osm(), 'hotel');
        $this->assertCount(5, $l);
        $this->assertSame([3, 'stars', 4], [$l[0]['priceLevel'], $l[0]['priceSource'], $l[0]['stars']]);
        $this->assertSame([1, 'stars'], [$l[1]['priceLevel'], $l[1]['priceSource']]);
        $this->assertSame([1, 'kind'], [$l[2]['priceLevel'], $l[2]['priceSource']]);
        $this->assertNull($l[3]['priceLevel']);
        $this->assertNull($l[3]['priceVnd']);   // OSM không có giá thật, không được bịa
    }

    public function test_đọc_thẻ_chay_món_wifi(): void
    {
        $l = PlaceFinder::parseOverpass(['elements' => [['type' => 'node', 'id' => 9, 'lat' => 1, 'lon' => 1,
            'tags' => ['name' => 'Quán Chay', 'amenity' => 'restaurant', 'diet:vegetarian' => 'yes', 'cuisine' => 'vietnamese;seafood', 'internet_access' => 'wlan']]]], 'restaurant');
        $this->assertTrue($l[0]['veg']);
        $this->assertSame(['vietnamese', 'seafood'], $l[0]['cuisine']);
        $this->assertTrue($l[0]['wifi']);
        $this->assertNull($l[0]['priceLevel']);
    }

    public function test_bán_kính_lọc_mục_xa(): void
    {
        $this->assertSame('Khách sạn A,Khách sạn B,Hostel C', $this->names(['cat' => 'hotel', 'includeUnknown' => 1, 'sort' => 'near']));
        $this->assertStringContainsString('Khách sạn D', $this->names(['cat' => 'hotel', 'radiusKm' => 10, 'includeUnknown' => 1]));
        $this->assertStringNotContainsString('Khách sạn Xa', $this->names(['cat' => 'hotel', 'radiusKm' => 30, 'includeUnknown' => 1]));   // trần 20 km
    }

    public function test_hạng_sao_và_mục_chưa_rõ_bị_ẩn_có_đếm(): void
    {
        $r = PlaceFinder::apply($this->list(), PlaceFinder::criteria(['cat' => 'hotel', 'stars' => 4]));
        $this->assertSame(['Khách sạn A'], array_map(fn($x) => $x['name'], $r['list']));
        $this->assertSame(1, $r['hidden']);   // Hostel C chưa có hạng sao
        $r2 = PlaceFinder::apply($this->list(), PlaceFinder::criteria(['cat' => 'hotel', 'stars' => 4, 'includeUnknown' => 1]));
        $this->assertSame(0, $r2['hidden']);
        $this->assertCount(2, $r2['list']);
    }

    public function test_mức_giá_rẻ_và_cao(): void
    {
        $this->assertSame('Khách sạn B,Hostel C', $this->names(['cat' => 'hotel', 'levels' => [1, 1]]));
        $this->assertSame('Khách sạn A', $this->names(['cat' => 'hotel', 'levels' => [3, 4]]));
    }

    public function test_lọc_theo_số_tiền_chỉ_dùng_giá_thật(): void
    {
        // OSM không có giá: mọi khách sạn bị ẩn khi lọc theo số tiền, không được đoán
        $r = PlaceFinder::apply($this->list(), PlaceFinder::criteria(['cat' => 'hotel', 'maxPrice' => 1000000]));
        $this->assertSame([], $r['list']);
        $this->assertSame(3, $r['hidden']);
    }

    public function test_giá_thật_của_travela_lọc_đúng(): void
    {
        $tl = [
            ['name' => 'Lớp nấu ăn', 'kindKey' => 'experience', 'distM' => 0, 'priceLevel' => 2, 'priceVnd' => 350000.0, 'indoor' => true, 'rank' => 5],
            ['name' => 'Đi thuyền', 'kindKey' => 'experience', 'distM' => 0, 'priceLevel' => 1, 'priceVnd' => 0.0, 'indoor' => false, 'rank' => 10],
            ['name' => 'Show', 'kindKey' => 'experience', 'distM' => 0, 'priceLevel' => null, 'priceVnd' => null, 'indoor' => null, 'rank' => 5],
        ];
        $n = fn(array $in) => implode(',', array_map(fn($x) => $x['name'], PlaceFinder::apply($tl, PlaceFinder::criteria($in))['list']));
        $this->assertSame('Đi thuyền', $n(['free' => 1]));
        $this->assertSame('Lớp nấu ăn', $n(['indoor' => 1]));
        $this->assertSame('Đi thuyền,Lớp nấu ăn', $n(['maxPrice' => 400000]));
        $this->assertSame('Lớp nấu ăn', $n(['minPrice' => 100000, 'maxPrice' => 400000]));
        $this->assertStringContainsString('Show', $n(['levels' => [1, 2], 'includeUnknown' => 1]));
    }

    public function test_sắp_xếp(): void
    {
        $this->assertSame('Khách sạn A', explode(',', $this->names(['cat' => 'hotel', 'radiusKm' => 30, 'includeUnknown' => 1, 'sort' => 'near']))[0]);
        $first = explode(',', $this->names(['cat' => 'hotel', 'radiusKm' => 30, 'includeUnknown' => 1, 'sort' => 'price']))[0];
        $this->assertContains($first, ['Khách sạn B', 'Hostel C']);   // rẻ nhất trước
    }

    public function test_quy_đổi_giá_và_trùng_lặp_và_truy_vấn(): void
    {
        $this->assertSame([1, 2, 3, 4], [PlaceFinder::levelFromPrice('tour', 1500000), PlaceFinder::levelFromPrice('tour', 3000000), PlaceFinder::levelFromPrice('tour', 9000000), PlaceFinder::levelFromPrice('tour', 20000000)]);
        $this->assertCount(2, PlaceFinder::dedupe([['name' => 'Quán Hồ', 'lat' => 1.0, 'lng' => 1.0], ['name' => 'QUÁN HO', 'lat' => 1.0004, 'lng' => 1.0], ['name' => 'Quán Hồ', 'lat' => 1.01, 'lng' => 1.0]]));
        $q = PlaceFinder::overpassQuery('cafe', 16.06, 108.22, 3000, 100);
        $this->assertStringContainsString('(around:3000,16.06,108.22)[amenity=cafe][name]', $q);
        $this->assertStringContainsString('out center tags 100;', $q);
        $this->assertNull(PlaceFinder::overpassQuery('experience', 1, 1, 1000));
    }

    public function test_criteria_chặn_giá_trị_xấu(): void
    {
        $c = PlaceFinder::criteria(['cat' => 'hack', 'kinds' => ['ok_kind', 'Bad Kind', '../x'], 'levels' => [9, 0], 'stars' => 9, 'radiusKm' => 999, 'cuisine' => 'a;b', 'minPrice' => -5]);
        $this->assertNull($c['cat']);
        $this->assertSame(['ok_kind'], $c['kinds']);
        $this->assertSame([4, 4], $c['levels']);
        $this->assertNull($c['stars']);
        $this->assertSame(20000, $c['radiusM']);
        $this->assertNull($c['cuisine']);
        $this->assertNull($c['minPrice']);
    }
}
