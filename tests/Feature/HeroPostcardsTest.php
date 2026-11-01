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

    // Trang chủ bản v3 (hero "Kể một câu" thay cho bộ bưu thiếp cũ): ô mô tả chuyến đi gửi thẳng tới Trip Planner

    public function test_hero_ke_mot_cau_gui_toi_trip_planner(): void
    {
        $this->seedThree(true);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form[^>]*id="hxAi"[^>]*action="' . preg_quote(route('planner'), '/') . '"[^>]*method="GET"/', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);   // ảnh hero tải sớm cho LCP
    }

    public function test_khoi_gioi_thieu_planner_dan_toi_trang_lap_ke_hoach(): void
    {
        $this->seedThree(false);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Kể một câu, nhận trọn lịch trình', $html);
        $this->assertStringContainsString('href="' . route('planner') . '"', $html);
    }
}
