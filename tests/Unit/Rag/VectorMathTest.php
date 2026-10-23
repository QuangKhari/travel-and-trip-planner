<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\Embedding\LocalHashEmbedder;
use App\Services\Rag\VectorMath;
use PHPUnit\Framework\TestCase;

class VectorMathTest extends TestCase
{
    public function test_chuan_hoa_ve_do_dai_1(): void
    {
        $v = VectorMath::normalize([3, 4]);
        $this->assertEqualsWithDelta(1.0, VectorMath::dot($v, $v), 1e-9);
    }

    public function test_pack_unpack_khu_hoi_trong_sai_so_float32(): void
    {
        $v = VectorMath::normalize([0.1, -0.7, 0.33, 0.9]);
        $back = VectorMath::unpack(VectorMath::pack($v));
        $this->assertCount(4, $back);
        foreach ($v as $i => $x) {
            $this->assertEqualsWithDelta($x, $back[$i], 1e-6);
        }
    }

    public function test_nhung_local_xac_dinh_va_gan_nhau_khi_cung_noi_dung(): void
    {
        $e = new LocalHashEmbedder(512);
        [$a, $b, $c] = $e->embed(['Thuê xe máy cần bằng lái', 'thue xe may can bang lai', 'Lễ hội đền Hùng tháng ba']);
        $this->assertEqualsWithDelta(1.0, VectorMath::dot($a, $b), 1e-9);   // bỏ dấu, hoa thường như nhau
        $this->assertLessThan(0.3, VectorMath::dot($a, $c));
        $this->assertSame($a, $e->embed(['Thuê xe máy cần bằng lái'])[0]);
    }
}
