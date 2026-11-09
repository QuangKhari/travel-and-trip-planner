<?php

namespace Tests\Feature;

use App\Services\ResourceAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Lịch tài nguyên theo giờ: khoảng nửa mở [start, end), không vượt sức chứa, idempotent theo chủ sở hữu,
 * tính cả phân bổ cũ theo ngày (tbl_rescue_resource_allocations).
 */
class ResourceAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function resource(string $kind, int $capacity, string $name = 'R'): int
    {
        return DB::table('tbl_rescue_resources')->insertGetId(['name' => $name, 'kind' => $kind, 'capacity' => $capacity, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function svc(): ResourceAllocationService
    {
        return app(ResourceAllocationService::class);
    }

    public function test_guide_back_to_back_shifts_do_not_conflict(): void
    {
        $g = $this->resource('guide', 1, 'HDV Lan');
        $this->svc()->allocate($g, 'departure', 1, '2026-12-12 10:00', '2026-12-12 12:00');
        $this->svc()->allocate($g, 'departure', 2, '2026-12-12 12:00', '2026-12-12 14:00');   // chạm biên: không trùng
        $this->assertSame(2, DB::table('tbl_resource_allocations')->where('resourceId', $g)->count());
    }

    public function test_guide_overlapping_shift_is_rejected(): void
    {
        $g = $this->resource('guide', 1, 'HDV Minh');
        $this->svc()->allocate($g, 'departure', 1, '2026-12-12 10:00', '2026-12-12 12:00');
        $this->expectException(ValidationException::class);
        $this->svc()->allocate($g, 'departure', 2, '2026-12-12 11:59', '2026-12-12 14:00');
    }

    public function test_van_capacity_is_summed_across_overlapping_groups(): void
    {
        $van = $this->resource('transport', 16, 'Xe 16 chỗ');
        $this->svc()->allocate($van, 'departure', 1, '2026-12-12 06:00', '2026-12-13 18:00', 10);
        try {
            $this->svc()->allocate($van, 'departure', 2, '2026-12-13 08:00', '2026-12-14 18:00', 9);   // 10 + 9 = 19 > 16
            $this->fail('phải từ chối vượt sức chứa');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('đã dùng 10/16', $e->getMessage());
        }
        $this->svc()->allocate($van, 'departure', 3, '2026-12-13 08:00', '2026-12-14 18:00', 6);        // 10 + 6 = 16: vừa
        $this->svc()->allocate($van, 'departure', 4, '2026-12-13 18:00', '2026-12-14 18:00', 10);       // sau khi đoàn 1 trả xe: 6 + 10
        $this->assertSame([], $this->svc()->overlaps());
    }

    public function test_same_owner_request_is_idempotent_and_release_frees_capacity(): void
    {
        $g = $this->resource('guide', 1);
        $a = $this->svc()->allocate($g, 'departure', 7, '2026-12-12 08:00', '2026-12-12 17:00');
        $b = $this->svc()->allocate($g, 'departure', 7, '2026-12-12 08:00', '2026-12-12 17:00');   // bấm lại / gửi lại
        $this->assertSame($a->allocationId, $b->allocationId);
        $this->assertTrue($this->svc()->release((int) $a->allocationId));
        $this->assertFalse($this->svc()->release((int) $a->allocationId));
        $this->svc()->allocate($g, 'departure', 8, '2026-12-12 09:00', '2026-12-12 10:00');
        $this->assertSame(2, DB::table('tbl_resource_allocations')->where('resourceId', $g)->count());
    }

    public function test_legacy_day_allocations_still_count(): void
    {
        $van = $this->resource('transport', 16);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');   // chỉ cần một dòng phương án cũ làm dữ liệu nền
        $planId = DB::table('tbl_departure_rescue_plans')->insertGetId($this->planRow());
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('tbl_rescue_resource_allocations')->insert(['resourceId' => $van, 'planId' => $planId, 'startDate' => '2026-12-12', 'endDate' => '2026-12-13',
            'seats' => 12, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->svc()->allocate($van, 'departure', 1, '2026-12-14 00:00', '2026-12-15 00:00', 16);   // sau dữ liệu cũ: vừa
        $this->expectException(ValidationException::class);
        $this->svc()->allocate($van, 'departure', 2, '2026-12-13 20:00', '2026-12-13 23:00', 5);    // 12 + 5 > 16 trong ngày 13
    }

    public function test_invalid_requests_are_rejected(): void
    {
        $g = $this->resource('guide', 1);
        foreach ([['2026-12-12 12:00', '2026-12-12 12:00', 1], ['2026-12-12 12:00', '2026-12-12 10:00', 1], ['2026-12-12 10:00', '2026-12-12 12:00', 0]] as [$s, $e, $q]) {
            try {
                $this->svc()->allocate($g, 'departure', 1, $s, $e, $q);
                $this->fail('phải từ chối');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        DB::table('tbl_rescue_resources')->where('resourceId', $g)->update(['active' => false]);
        $this->expectException(ValidationException::class);
        $this->svc()->allocate($g, 'departure', 1, '2026-12-12 10:00', '2026-12-12 12:00');
    }

    private function planRow(): array
    {
        $cols = \Illuminate\Support\Facades\Schema::getColumns('tbl_departure_rescue_plans');
        $row = [];
        foreach ($cols as $c) {
            if ($c['auto_increment'] || $c['nullable'] || $c['default'] !== null) {
                continue;
            }
            $row[$c['name']] = str_contains($c['type'], 'int') ? 1 : (str_contains($c['type'], 'date') || str_contains($c['type'], 'time') ? now() : 'x');
        }

        return $row;
    }
}
