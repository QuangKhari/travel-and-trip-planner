<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeasonApiTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $slug, string $name, ?string $cover): int
    {
        return DB::table('tbl_destinations')->insertGetId([
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

    private function month(int $destId, int $month, string $level, ?string $note = null): void
    {
        DB::table('tbl_destination_months')->insert([
            'destinationId' => $destId,
            'month' => $month,
            'level' => $level,
            'note' => $note,
            'source' => 'manual',
        ]);
    }

    public function test_month_ngoai_khoang_tra_422(): void
    {
        $this->getJson('/api/v1/seasons?month=13')->assertStatus(422);
        $this->getJson('/api/v1/seasons?month=abc')->assertStatus(422);
    }

    public function test_best_len_truoc_avoid_bi_loai_va_bo_diem_thieu_anh(): void
    {
        Storage::fake('public');
        foreach (['ha-noi', 'sa-pa', 'da-lat'] as $s) {
            Storage::disk('public')->put("discover/destinations/{$s}.webp", 'x');
        }
        $hn = $this->destination('ha-noi', 'Hà Nội', 'discover/destinations/ha-noi.webp');
        $sp = $this->destination('sa-pa', 'Sa Pa', 'discover/destinations/sa-pa.webp');
        $dl = $this->destination('da-lat', 'Đà Lạt', 'discover/destinations/da-lat.webp');
        $this->destination('khong-anh', 'Không ảnh', 'discover/destinations/khong-anh.webp'); // file không tồn tại

        $this->month($sp, 10, 'best', 'Lúa chín');
        $this->month($dl, 10, 'avoid');
        $this->month($hn, 10, 'ok');

        $items = $this->getJson('/api/v1/seasons?month=10')->assertOk()->json('items');

        $this->assertSame(['sa-pa', 'ha-noi'], array_column($items, 'slug')); // Đà Lạt (avoid) và điểm thiếu ảnh bị loại
        $this->assertTrue($items[0]['best']);
        $this->assertStringContainsString('tháng 10', $items[0]['why']);
        $this->assertFalse($items[1]['best']);
    }
}
