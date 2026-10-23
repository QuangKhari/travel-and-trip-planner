<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\Chunker;
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase
{
    private function words(int $n, string $w = 'từ'): string
    {
        return implode(' ', array_fill(0, $n, $w));
    }

    public function test_bai_ngan_thanh_mot_doan_kem_tieu_de_muc(): void
    {
        $c = (new Chunker(120, 380))->split('Thuê xe máy', "## Giấy tờ\nCần bằng lái.\n\n## Lưu ý\nĐội mũ bảo hiểm.");
        $this->assertCount(1, $c);
        $this->assertSame('Thuê xe máy — Giấy tờ', $c[0]['title']);
        $this->assertStringContainsString('Cần bằng lái.', $c[0]['text']);
        $this->assertStringContainsString('Lưu ý:', $c[0]['text']);
    }

    public function test_muc_dai_bi_tach_va_khong_doan_nao_vuot_toi_da(): void
    {
        $md = "## A\n" . $this->words(300) . ".\n\n" . $this->words(300) . ".\n\n## B\n" . $this->words(100) . '.';
        $chunks = (new Chunker(120, 380))->split('Bài', $md);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(400, Chunker::words($c['text']));
        }
    }

    public function test_cau_qua_dai_khong_co_dau_cham_van_bi_cat_cung(): void
    {
        $chunks = (new Chunker(50, 100))->split('Bài', $this->words(250));
        $this->assertGreaterThanOrEqual(3, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(100, Chunker::words($c['text']));
        }
    }

    public function test_bo_ky_hieu_markdown_nhung_giu_noi_dung(): void
    {
        $c = (new Chunker())->split('Bài', "> **Lưu ý:** xem [trang này](https://x.vn) nhé\n\n- Mục một\n- Mục hai");
        $t = $c[0]['text'];
        $this->assertStringNotContainsString('**', $t);
        $this->assertStringNotContainsString('https://x.vn', $t);
        $this->assertStringContainsString('xem trang này nhé', $t);
        $this->assertStringContainsString('- Mục một', $t);
    }

    public function test_noi_dung_rong_khong_sinh_doan(): void
    {
        $this->assertSame([], (new Chunker())->split('Bài', "   \n\n"));
    }
}
