<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Hero "Dòng bưu ảnh": nền mờ dùng bản thu nhỏ, bưu ảnh trắng không dẫn tới tham số bị bỏ qua. */
class HeroPostcardsTest extends TestCase
{
    use RefreshDatabase;

    private function seedThree(bool $withThumb): void
    {
        Storage::fake('public');
        foreach (['a', 'b', 'c'] as $s) {
            Storage::disk('public')->put("discover/destinations/{$s}.webp", 'x');
            if ($withThumb && $s === 'a') {
                Storage::disk('public')->put('discover/destinations/a-s.webp', 'x');
            }
            DB::table('tbl_destinations')->insert(['slug' => $s, 'name' => strtoupper($s), 'province' => 'T', 'domain' => 'b', 'description' => 'm',
                'latitude' => 21, 'longitude' => 105, 'coverImage' => "discover/destinations/{$s}.webp", 'isActive' => 1]);
        }
    }

    public function test_api_seasons_co_thumb_khi_co_file_thu_nho(): void
    {
        $this->seedThree(true);
        $items = collect($this->getJson('/api/v1/seasons?month=5')->assertOk()->json('items'))->keyBy('slug');
        $this->assertStringEndsWith('/a-s.webp', $items['a']['thumb']);
        $this->assertNull($items['b']['thumb']);
    }

    public function test_trang_chu_blank_card_khong_con_tham_so_vo_nghia(): void
    {
        $this->seedThree(true);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-thumb="', $html);
        $this->assertMatchesRegularExpression('/class="hp-card hp-blank" href="#kham-pha"/', $html);
        $this->assertStringContainsString('Khám phá theo cảm hứng →', $html);
        $this->assertStringNotContainsString('#kham-pha' . (int) date('n'), $html);
        // tấm đầu hiện sẵn bằng CSS và có fetchpriority cho LCP
        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_co_planner_thi_blank_card_dan_thang_kem_thang(): void
    {
        config(['postcards.planner_url' => '/len-ke-hoach']);
        $this->seedThree(false);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('/len-ke-hoach?thang=' . (int) date('n'), $html);
        $this->assertStringContainsString('Lên kế hoạch cho tháng ' . (int) date('n'), $html);
    }
}
