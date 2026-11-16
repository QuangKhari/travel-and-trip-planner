<?php

namespace Tests\Feature;

use App\Console\Commands\AiGrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Đợt F: số liệu AI từ dữ liệu thật và độ đồng thuận chấm tay. */
class EvidenceAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_kappa_matches_known_values(): void
    {
        $this->assertEqualsWithDelta(1.0, AiGrade::kappa([1, 2, 3, 4, 5], [1, 2, 3, 4, 5]), 1e-9);
        // lệch đều 1 bậc: vẫn đồng thuận cao theo trọng số bậc hai
        $k = AiGrade::kappa([1, 2, 3, 4, 5, 3], [2, 3, 4, 5, 5, 3]);
        $this->assertGreaterThan(0.6, $k);
        $this->assertLessThan(1.0, $k);
        // ngược chiều: âm
        $this->assertLessThan(0, AiGrade::kappa([1, 2, 4, 5], [5, 4, 2, 1]));
    }

    public function test_grade_command_reads_two_files(): void
    {
        $dir = storage_path('app/test-grade');
        @mkdir($dir, 0775, true);
        file_put_contents("$dir/a.csv", "\xEF\xBB\xBFid,story,judgeFaithful,judgeUseful\ne1,x,5,4\ne2,x,4,4\ne3,x,5,3\ne4,x,2,2\n");
        file_put_contents("$dir/b.csv", "id,story,judgeFaithful,judgeUseful\ne1,x,5,5\ne2,x,4,3\ne3,x,4,3\ne4,x,2,\n");
        $this->artisan('ai:grade', ['a' => "$dir/a.csv", 'b' => "$dir/b.csv"])
            ->expectsOutputToContain('Đúng với dữ liệu (không bịa) | 4 | 4,00 | 3,75 | 3,88 | 75% | 100%')
            ->expectsOutputToContain('Hữu ích khi quyết định | 3 |')
            ->assertExitCode(0);
    }

    public function test_evidence_reports_not_enough_data_honestly_and_counts_alerts(): void
    {
        $now = now();
        $u = DB::table('tbl_users')->insertGetId(['username' => 'ev', 'email' => 'ev@t.test', 'password' => 'x', 'fullName' => 'E', 'isActive' => 'y']);
        DB::table('tbl_trips')->insert(['tripId' => 900, 'userId' => $u, 'kind' => 'trip', 'title' => 'x', 'days' => 2, 'createdAt' => $now]);
        DB::table('tbl_trip_events')->insert([
            ['tripId' => 900, 'kind' => 'alert', 'tone' => 'bad', 'title' => 'Dông', 'ref' => 'weather.storm:i3:abc123:' . $now->format('Ymd'), 'createdAt' => $now->copy()->subHours(3)],
            ['tripId' => 900, 'kind' => 'resolved', 'tone' => 'ok', 'title' => 'Đã ổn', 'ref' => 'weather.storm:i3:' . $now->format('YmdH'), 'createdAt' => $now->copy()->subHour()],
            ['tripId' => 900, 'kind' => 'alert', 'tone' => 'warn', 'title' => 'Đóng cửa', 'ref' => 'hours.closed:i5:def456:' . $now->format('Ymd'), 'createdAt' => $now->copy()->subHours(2)],
        ]);
        DB::table('tbl_ai_runs')->insert([
            ['promptVersion' => 'v1', 'feature' => 'explain', 'engine' => 'llm', 'outcome' => 'ok', 'guardFlags' => '["alert"]', 'createdAt' => $now],
            ['promptVersion' => 'v1', 'feature' => 'explain', 'engine' => 'template', 'outcome' => 'guard_replaced', 'guardFlags' => '["alert","number:why"]', 'createdAt' => $now],
            ['promptVersion' => 'v1', 'feature' => 'copilot', 'engine' => 'llm', 'outcome' => 'applied', 'guardFlags' => null, 'createdAt' => $now],
            ['promptVersion' => 'v1', 'feature' => 'copilot', 'engine' => 'llm', 'outcome' => 'skipped', 'guardFlags' => null, 'createdAt' => $now],
        ]);
        $this->artisan('evidence:ai')
            ->expectsOutputToContain('Chưa đủ dữ liệu: 0 lượt hiện có nhãn')
            ->expectsOutputToContain('Chưa có dữ liệu: bật RECO_MODE=interleave')
            ->expectsOutputToContain('| copilot | 1 | 1 | 50% |')
            ->expectsOutputToContain('2 cảnh báo được phát hiện, 1 đã được xử lý (50%), thời gian từ lúc phát hiện tới khi hết cảnh báo: trung vị 2,0 giờ')
            ->expectsOutputToContain('2 lượt: 1 dùng lời AI đã qua kiểm tra, 1 bị chốt chặn thay bằng mẫu, 0 dùng mẫu')
            ->expectsOutputToContain('con số không có trong dữ kiện 1')
            ->assertExitCode(0);
    }
}
