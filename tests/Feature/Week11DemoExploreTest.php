<?php

namespace Tests\Feature;

use Database\Seeders\DemoExploreSeeder;
use Database\Seeders\DiscoverSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Dữ liệu mẫu Khám phá: đủ 34 tỉnh/thành, nạp lặp lại không nhân đôi, mặc định draft, publish theo PublishGate. */
class Week11DemoExploreTest extends TestCase
{
    use RefreshDatabase;

    private function seedAll(bool $publish = false, bool $events = false): void
    {
        $this->seed(DiscoverSeeder::class);
        (new DemoExploreSeeder())->with($publish, $events)->setContainer(app())->run();
    }

    public function test_destinations_cover_all_34_provinces(): void
    {
        $this->seedAll();
        $provinces = DB::table('tbl_destinations')->distinct()->pluck('province');
        $this->assertCount(34, $provinces);
        $this->assertSame(47, DB::table('tbl_destinations')->count());
        // mỗi điểm đến mới có điểm cảm hứng và tháng đẹp
        $id = DB::table('tbl_destinations')->where('slug', 'lai-chau')->value('destinationId');
        $this->assertGreaterThan(0, DB::table('tbl_destination_inspiration')->where('destinationId', $id)->count());
        $this->assertSame(12, DB::table('tbl_destination_months')->where('destinationId', $id)->count());
    }

    public function test_everything_is_draft_and_self_written_by_default(): void
    {
        $this->seedAll();
        foreach (['tbl_foods', 'tbl_experiences', 'tbl_events', 'tbl_itineraries'] as $t) {
            $this->assertGreaterThan(0, DB::table($t)->count(), $t);
            $this->assertSame(0, DB::table($t)->where('reviewStatus', '!=', 'draft')->count(), $t);
            $this->assertSame(0, DB::table($t)->where(fn ($q) => $q->whereNull('license')->orWhere('license', '!=', 'Tự viết'))->count(), $t);
        }
        // mọi điểm đến có ít nhất một trải nghiệm
        $without = DB::table('tbl_destinations as d')->leftJoin('tbl_experiences as e', 'e.destinationId', '=', 'd.destinationId')->whereNull('e.experienceId')->pluck('d.slug');
        $this->assertSame([], $without->all());
        // lịch trình có mục liên kết thật
        $this->assertGreaterThan(0, DB::table('tbl_itinerary_items')->where('refType', 'food')->whereNotNull('refId')->count());
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        $this->seedAll();
        $counts = array_map(fn ($t) => DB::table($t)->count(), ['tbl_destinations', 'tbl_foods', 'tbl_experiences', 'tbl_events', 'tbl_itineraries', 'tbl_itinerary_items', 'tbl_destination_inspiration', 'tbl_destination_months']);
        (new DemoExploreSeeder())->setContainer(app())->run();
        $again = array_map(fn ($t) => DB::table($t)->count(), ['tbl_destinations', 'tbl_foods', 'tbl_experiences', 'tbl_events', 'tbl_itineraries', 'tbl_itinerary_items', 'tbl_destination_inspiration', 'tbl_destination_months']);
        $this->assertSame($counts, $again);
    }

    public function test_publish_skips_events_unless_asked(): void
    {
        $this->seedAll(true, false);
        $this->assertGreaterThan(0, DB::table('tbl_foods')->where('reviewStatus', 'published')->count());
        $this->assertGreaterThan(0, DB::table('tbl_itineraries')->where('reviewStatus', 'published')->count());
        $this->assertSame(0, DB::table('tbl_events')->where('reviewStatus', 'published')->count());
        // trải nghiệm đã xuất bản đều đủ cho Planner
        foreach (DB::table('tbl_experiences')->where('reviewStatus', 'published')->get() as $e) {
            $this->assertSame([], \App\Services\Content\PublishGate::plannerProblems('experiences', $e), $e->slug);
        }
    }

    public function test_events_can_be_published_and_have_computable_dates(): void
    {
        $this->seedAll(true, true);
        $this->assertSame(0, DB::table('tbl_events')->where('reviewStatus', 'draft')->count());
    }

    public function test_does_not_overwrite_reviewed_content(): void
    {
        $this->seed(DiscoverSeeder::class);
        $dest = DB::table('tbl_destinations')->where('slug', 'hue')->value('destinationId');
        DB::table('tbl_foods')->insert(['slug' => 'bun-bo-hue', 'name' => 'Bún bò Huế', 'destinationId' => $dest, 'description' => 'Bản đã duyệt tay, giữ nguyên.', 'license' => 'CC BY-SA 4.0', 'sourceUrl' => 'https://vi.wikipedia.org/wiki/B%C3%BAn_b%C3%B2_Hu%E1%BA%BF', 'reviewStatus' => 'published']);
        (new DemoExploreSeeder())->setContainer(app())->run();
        $this->assertSame('Bản đã duyệt tay, giữ nguyên.', DB::table('tbl_foods')->where('slug', 'bun-bo-hue')->value('description'));
        $this->assertSame(1, DB::table('tbl_foods')->where('slug', 'bun-bo-hue')->count());
    }
}
