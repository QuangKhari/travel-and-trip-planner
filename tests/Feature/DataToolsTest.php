<?php

namespace Tests\Feature;

use App\Services\Content\PlaceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt D: places:audit, demo:check và các tệp dữ liệu lõi trong database/seeders/data/imports. */
class DataToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_import_files_pass_checks_and_meet_the_core_standard(): void
    {
        $files = glob(database_path('seeders/data/imports/*.csv'));
        $this->assertCount(6, $files);
        foreach ($files as $f) {
            $slug = basename($f, '.csv');
            $center = $this->center($f);
            DB::table('tbl_destinations')->insert(['slug' => $slug, 'name' => $slug, 'province' => 'x', 'domain' => 'b', 'description' => 'x',
                'latitude' => $center[0], 'longitude' => $center[1], 'isActive' => 1]);

            $r = PlaceImport::stage((string) file_get_contents($f), basename($f), null);
            $this->assertNull($r['error'], $slug);
            $this->assertSame(0, $r['bad'], "$slug có dòng lỗi");
            $this->assertSame(0, $r['dup'], "$slug có dòng trùng nhau");
            $rows = DB::table('tbl_import_rows')->where('batchId', $r['batchId'])->get();
            $data = $rows->map(fn($x) => json_decode($x->data, true));
            $this->assertGreaterThanOrEqual(config('core_destinations.min_sight'), $data->where('category', 'sight')->count(), $slug);
            $this->assertGreaterThanOrEqual(config('core_destinations.min_food'), $data->where('category', 'food')->count(), $slug);
            $this->assertTrue($data->every(fn($d) => $d['hours'] && $d['duration'] && $d['source']), "$slug thiếu giờ / thời lượng / nguồn");
            $this->assertGreaterThanOrEqual(config('core_destinations.min_indoor'), $data->where('category', 'sight')->where('indoor', 1)->count(), $slug);
        }
    }

    /** Tâm điểm đến = trung bình tọa độ trong tệp (đủ để kiểm tra không có dòng nào lệch xa). */
    private function center(string $f): array
    {
        $rows = array_map('str_getcsv', array_slice(file($f, FILE_IGNORE_NEW_LINES), 1));

        return [array_sum(array_column($rows, 4)) / count($rows), array_sum(array_column($rows, 5)) / count($rows)];
    }

    public function test_audit_flags_duplicates_bad_coordinates_and_shortfall(): void
    {
        config(['core_destinations.slugs' => ['dl'], 'core_destinations.min_sight' => 2, 'core_destinations.min_food' => 1, 'core_destinations.min_indoor' => 0]);
        $d = DB::table('tbl_destinations')->insertGetId(['slug' => 'dl', 'name' => 'Đà Lạt', 'province' => 'x', 'domain' => 'b', 'description' => 'x',
            'latitude' => 11.94, 'longitude' => 108.45, 'isActive' => 1]);
        $p = fn($name, $lat, $lng, $cat = 'sight') => ['destinationId' => $d, 'source' => 'import', 'externalId' => uniqid(), 'name' => $name, 'category' => $cat,
            'latitude' => $lat, 'longitude' => $lng, 'rank' => 1, 'reviewStatus' => 'published', 'syncedAt' => now(), 'durationMinutes' => 60, 'factsSource' => 'x'];
        DB::table('tbl_external_places')->insert([$p('Ga Đà Lạt', 11.9415, 108.4547), $p('Ga Da Lat', 11.9416, 108.4548), $p('Đảo tọa độ', 108.45, 11.94),
            $p('Chợ Đà Lạt', 11.9430, 108.4370), $p('Chợ đêm Đà Lạt', 11.9437, 108.4372, 'food')]);

        $this->artisan('places:audit', ['--core' => true, '--strict' => true])->expectsOutputToContain('Có thể trùng')->assertExitCode(1);
        $r = \App\Console\Commands\PlacesAudit::auditDestination(DB::table('tbl_destinations')->where('destinationId', $d)->first());
        $codes = array_count_values(array_column($r['issues'], 'code'));
        $this->assertSame(1, $codes['duplicate'] ?? 0);   // chợ và chợ đêm là hai nơi khác nhau
        $this->assertSame(1, $codes['bad_coords'] ?? 0);
        $this->assertSame(0, $r['pctHours']);
    }

    public function test_demo_check_reports_and_fails_on_missing_core_data(): void
    {
        config(['core_destinations.slugs' => ['khong-co']]);
        $this->artisan('demo:check')->expectsOutputToContain('không có điểm đến này')->assertExitCode(1);
    }
}
