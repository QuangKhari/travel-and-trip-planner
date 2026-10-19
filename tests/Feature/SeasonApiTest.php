<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeasonApiTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $slug, string $name, ?string $cover): void
    {
        DB::table('tbl_destinations')->insert([
            'slug' => $slug,
            'name' => $name,
            'province' => 'Test',
            'domain' => 'b',
            'description' => 'mô tả',
            'latitude' => 21.0,
            'longitude' => 105.0,
            'coverImage' => $cover,
            'isActive' => 1,
        ]);
    }

    public function test_month_ngoai_khoang_tra_422(): void
    {
        $this->getJson('/api/v1/seasons?month=13')->assertStatus(422);
        $this->getJson('/api/v1/seasons?month=abc')->assertStatus(422);
    }

    public function test_diem_dep_trong_thang_len_truoc_va_bo_diem_thieu_anh(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('discover/destinations/sa-pa.webp', 'x');
        Storage::disk('public')->put('discover/destinations/ha-noi.webp', 'x');

        $this->destination('ha-noi', 'Hà Nội', 'discover/destinations/ha-noi.webp');
        $this->destination('sa-pa', 'Sa Pa', 'discover/destinations/sa-pa.webp');
        $this->destination('khong-anh', 'Không ảnh', 'discover/destinations/khong-anh.webp'); // file không tồn tại

        config(['postcards.seasons' => ['sa-pa' => ['m' => [10], 'w' => 'Lúa chín']]]);

        $res = $this->getJson('/api/v1/seasons?month=10')->assertOk();

        $items = $res->json('items');
        $this->assertCount(2, $items);
        $this->assertSame('sa-pa', $items[0]['slug']);
        $this->assertTrue($items[0]['best']);
        $this->assertStringContainsString('tháng 10', $items[0]['why']);
        $this->assertFalse($items[1]['best']);
    }
}
