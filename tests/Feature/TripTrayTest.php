<?php

namespace Tests\Feature;

use App\Services\Content\TripTray;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tuần 1: khay là tbl_trips.kind = 'tray'; chống trùng bằng code vì đã bỏ unique(tripId, refType, refId). */
class TripTrayTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $dest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'u1', 'email' => 'u1@example.com', 'password' => 'x', 'fullName' => 'U', 'isActive' => 'y']);
        $this->dest = DB::table('tbl_destinations')->insertGetId(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b',
            'description' => 'mô tả Hà Nội', 'latitude' => 21.0285, 'longitude' => 105.8542, 'isActive' => 1]);
    }

    private function food(string $slug): void
    {
        DB::table('tbl_foods')->insert(['slug' => $slug, 'name' => 'Món ' . $slug, 'destinationId' => $this->dest, 'category' => 'bún phở',
            'description' => 'Mô tả đủ dài cho món ' . $slug, 'license' => 'Tự viết', 'reviewStatus' => 'published']);
    }

    public function test_sync_creates_tray_with_kind_and_no_destination(): void
    {
        $this->food('pho-bo');
        (new TripTray())->sync($this->uid, [['type' => 'food', 'slug' => 'pho-bo']]);

        $trip = DB::table('tbl_trips')->where('userId', $this->uid)->first();
        $this->assertSame('tray', $trip->kind);
        $this->assertNull($trip->destinationId);
        $this->assertSame(1, DB::table('tbl_trip_items')->where('tripId', $trip->tripId)->count());
    }

    public function test_sync_twice_and_duplicates_in_one_request_do_not_duplicate(): void
    {
        $this->food('pho-bo');
        $tray = new TripTray();
        $tray->sync($this->uid, [['type' => 'food', 'slug' => 'pho-bo'], ['type' => 'food', 'slug' => 'pho-bo']]);
        $out = $tray->sync($this->uid, [['type' => 'food', 'slug' => 'pho-bo'], ['type' => 'destination', 'slug' => 'ha-noi']]);

        $this->assertCount(2, $out);
        $this->assertSame(2, DB::table('tbl_trip_items')->count());
        $this->assertSame(1, DB::table('tbl_trips')->count());
    }

    public function test_remove_deletes_exactly_one_row(): void
    {
        $this->food('pho-bo');
        $this->food('bun-cha');
        $tray = new TripTray();
        $tray->sync($this->uid, [['type' => 'food', 'slug' => 'pho-bo'], ['type' => 'food', 'slug' => 'bun-cha']]);
        $tray->remove($this->uid, 'food', 'pho-bo');

        $left = $tray->items($this->uid);
        $this->assertCount(1, $left);
        $this->assertSame('bun-cha', $left[0]['slug']);
    }

    public function test_unpublished_item_is_ignored(): void
    {
        DB::table('tbl_foods')->insert(['slug' => 'nhap', 'name' => 'Nháp', 'destinationId' => $this->dest, 'category' => 'x',
            'description' => 'Mô tả đủ dài cho món nháp', 'license' => 'Tự viết', 'reviewStatus' => 'draft']);

        $this->assertSame([], (new TripTray())->sync($this->uid, [['type' => 'food', 'slug' => 'nhap']]));
    }

    public function test_max_items_is_enforced(): void
    {
        $in = [];
        for ($i = 0; $i < TripTray::MAX_ITEMS + 5; $i++) {
            $this->food('mon-' . $i);
            $in[] = ['type' => 'food', 'slug' => 'mon-' . $i];
        }
        $this->assertCount(TripTray::MAX_ITEMS, (new TripTray())->sync($this->uid, $in));
    }
}
