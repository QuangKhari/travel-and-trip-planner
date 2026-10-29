<?php

namespace Tests\Unit;

use App\Services\Planner\PlaceQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Hiểu câu tìm không cụ thể: loại, mức giá, số tiền, ăn chay, trong nhà/ngoài trời, hạng sao, khoảng cách. Không cần DB hay mạng. */
class PlaceQueryTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'chỉ loại' => ['nhà hàng', ['cat' => 'restaurant', 'specific' => false]],
            'loại + chay + rẻ' => ['nhà hàng chay giá rẻ', ['cat' => 'restaurant', 'veg' => true, 'levels' => [1, 1], 'specific' => false]],
            'sao + trần giá' => ['khách sạn 4 sao dưới 1 triệu', ['cat' => 'hotel', 'stars' => 4, 'maxPrice' => 1000000]],
            'khoảng giá k-tr' => ['khách sạn từ 500k đến 1.5tr', ['cat' => 'hotel', 'minPrice' => 500000, 'maxPrice' => 1500000]],
            'khoảng giá gạch nối' => ['tour 2-5 triệu', ['cat' => 'tour', 'minPrice' => 2000000, 'maxPrice' => 5000000, 'specific' => false]],
            'tối đa' => ['trải nghiệm ngoài trời tối đa 300 nghìn', ['cat' => 'experience', 'maxPrice' => 300000, 'indoor' => false]],
            'ký hiệu nhỏ hơn' => ['nhà hàng < 300k', ['cat' => 'restaurant', 'maxPrice' => 300000]],
            'trên' => ['tour trên 3 triệu', ['cat' => 'tour', 'minPrice' => 3000000, 'maxPrice' => null]],
            'số đồng trần' => ['khách sạn dưới 800000', ['cat' => 'hotel', 'maxPrice' => 800000]],
            'khoảng cách + gần' => ['quán cà phê gần 2km', ['cat' => 'cafe', 'radiusKm' => 2.0, 'sort' => 'near', 'specific' => false]],
            'trong nhà' => ['hoạt động trong nhà', ['cat' => 'experience', 'indoor' => true]],
            'loại con' => ['bảo tàng', ['cat' => 'sight', 'kinds' => ['museum', 'gallery']]],
            'miễn phí' => ['bãi biển miễn phí', ['cat' => 'sight', 'kinds' => ['beach'], 'free' => true]],
            'món' => ['nhà hàng nhật bản', ['cat' => 'restaurant', 'cuisine' => 'japanese', 'specific' => false]],
            'cao cấp' => ['khách sạn cao cấp', ['cat' => 'hotel', 'levels' => [3, 4]]],
        ];
    }

    #[DataProvider('cases')]
    public function test_parse(string $q, array $expect): void
    {
        $r = PlaceQuery::parse($q);
        foreach ($expect as $k => $v) {
            $this->assertSame($v, $r[$k], "$q => $k");
        }
    }

    public function test_tên_riêng_là_tìm_cụ_thể(): void
    {
        foreach (['Hồ Gươm', 'phở Thìn', 'chạy bộ', 'nhà hàng Hồ Gươm'] as $q) {
            $this->assertTrue(PlaceQuery::parse($q)['specific'], $q);
        }
        $this->assertSame('hồ gươm', PlaceQuery::parse('nhà hàng Hồ Gươm')['rest']);
    }

    public function test_có_tiêu_chí_hay_không(): void
    {
        $this->assertTrue(PlaceQuery::hasCriteria(PlaceQuery::parse('khách sạn')));
        $this->assertFalse(PlaceQuery::hasCriteria(PlaceQuery::parse('Hồ Gươm')));
        $this->assertFalse(PlaceQuery::hasCriteria(PlaceQuery::parse('')));
    }

    public function test_không_dấu_vẫn_hiểu(): void
    {
        $r = PlaceQuery::parse('khach san gia re duoi 500k');
        $this->assertSame('hotel', $r['cat']);
        $this->assertSame([1, 1], $r['levels']);
        $this->assertSame(500000, $r['maxPrice']);
    }

    public function test_số_nhỏ_không_phải_tiền_và_quận_không_bị_nuốt(): void
    {
        $r = PlaceQuery::parse('quán ăn quận 1');
        $this->assertTrue($r['specific']);
        $this->assertNull($r['maxPrice']);
        $this->assertStringContainsString('quận', $r['rest']);
    }
}
