<?php

namespace Tests\Feature;

use App\Services\DepartureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Màn hình admin "Xếp xe & HDV" (/admin/resources) cho đoàn thường. */
class ResourceScheduleAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $admin;
    private int $av;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = DB::table('tbl_admin')->insertGetId(['userName' => 'adm', 'passWord' => 'x', 'email' => 'adm@example.com', 'fullName' => 'Admin', 'address' => 'HN', 'role' => 'manager']);
        $tourId = DB::table('tbl_tours')->insertGetId(['title' => 'Sa Pa 3 ngày', 'time' => '3 ngày', 'description' => 'm', 'quantity' => 30, 'priceAdult' => 1, 'priceChild' => 1,
            'destination' => 'Sa Pa', 'domain' => 'b', 'availability' => 1, 'startDate' => now()->addDays(5)->toDateString(), 'endDate' => now()->addDays(7)->toDateString(), 'durationDays' => 3]);
        app(DepartureService::class)->ensureForTour($tourId);
        $this->av = (int) DB::table('tbl_tour_availability')->where('tourId', $tourId)->value('availabilityId');
        DB::table('tbl_tour_availability')->where('availabilityId', $this->av)->update(['totalSlots' => 30, 'bookedSlots' => 18, 'heldSlots' => 0]);
    }

    private function as()
    {
        return $this->withSession(['adminId' => $this->admin]);
    }

    private function res(string $kind, int $cap, string $name): int
    {
        return DB::table('tbl_rescue_resources')->insertGetId(['name' => $name, 'kind' => $kind, 'capacity' => $cap, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_requires_admin(): void
    {
        $this->get('/admin/resources')->assertRedirect(route('admin.login'));
    }

    public function test_page_shows_departure_warnings_and_grid(): void
    {
        $this->res('transport', 16, 'Xe 16 chỗ A');
        $this->as()->get('/admin/resources')->assertOk()
            ->assertSee('Xếp xe &amp; hướng dẫn viên', false)->assertSee('Sa Pa 3 ngày')->assertSee('Xe 16 chỗ A')->assertSee('Chưa có xe')->assertSee('Chưa có HDV');
    }

    public function test_allocate_then_short_seats_warning_then_release(): void
    {
        $van = $this->res('transport', 16, 'Xe 16 chỗ A');
        $dep = DB::table('tbl_tour_availability')->where('availabilityId', $this->av)->value('departureDate');
        $this->as()->post('/admin/resources/allocate', ['availabilityId' => $this->av, 'resourceId' => $van, 'startAt' => "$dep 06:00", 'endAt' => "$dep 20:00", 'quantity' => 16])
            ->assertSessionHas('success');
        $this->as()->get('/admin/resources')->assertSee('Xe thiếu 2 chỗ')->assertDontSee('Chưa có xe');
        $a = DB::table('tbl_resource_allocations')->where(['resourceId' => $van, 'ownerType' => 'departure', 'ownerId' => $this->av])->first();
        $this->assertNotNull($a);
        $this->assertSame(1, DB::table('tbl_audit_log')->where('action', 'resource.allocate')->count());

        $this->as()->post("/admin/resources/allocations/{$a->allocationId}/release")->assertSessionHas('success');
        $this->assertSame('released', DB::table('tbl_resource_allocations')->where('allocationId', $a->allocationId)->value('status'));
        $this->as()->post("/admin/resources/allocations/{$a->allocationId}/release")->assertSessionHas('error');
    }

    public function test_overlapping_guide_shows_error_not_exception(): void
    {
        $g = $this->res('guide', 1, 'HDV Lan');
        $dep = DB::table('tbl_tour_availability')->where('availabilityId', $this->av)->value('departureDate');
        DB::table('tbl_resource_allocations')->insert(['resourceId' => $g, 'ownerType' => 'departure', 'ownerId' => 999, 'startAt' => "$dep 08:00", 'endAt' => "$dep 18:00",
            'quantity' => 1, 'status' => 'confirmed', 'createdAt' => now()]);
        $this->as()->from('/admin/resources')->post('/admin/resources/allocate', ['availabilityId' => $this->av, 'resourceId' => $g, 'startAt' => "$dep 12:00", 'endAt' => "$dep 20:00", 'quantity' => 1])
            ->assertRedirect('/admin/resources')->assertSessionHas('error', fn($m) => str_contains($m, 'HDV Lan') && str_contains($m, 'không đủ sức chứa'));
        $this->assertSame(1, DB::table('tbl_resource_allocations')->where('resourceId', $g)->count());
    }

    public function test_add_and_toggle_resource(): void
    {
        $this->as()->post('/admin/resources', ['name' => 'Xe 29 chỗ', 'kind' => 'transport', 'capacity' => 29])->assertSessionHas('success');
        $id = (int) DB::table('tbl_rescue_resources')->where('name', 'Xe 29 chỗ')->value('resourceId');
        $this->as()->post("/admin/resources/$id/toggle")->assertSessionHas('success');
        $this->assertFalse((bool) DB::table('tbl_rescue_resources')->where('resourceId', $id)->value('active'));
        $this->as()->post('/admin/resources', ['name' => '', 'kind' => 'boat', 'capacity' => 0])->assertSessionHasErrors(['name', 'kind', 'capacity']);
    }

    public function test_departure_list_links_to_resources(): void
    {
        $this->as()->get('/admin/departures')->assertOk()->assertSee('/admin/resources?from=', false)->assertSee('Xe · HDV');
    }
}
