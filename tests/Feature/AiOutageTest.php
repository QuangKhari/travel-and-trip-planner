<?php

namespace Tests\Feature;

use App\Services\Ai\Llm\FakeLlm;
use App\Services\Ai\Llm\StructuredLlm;
use App\Services\Planner\TripPlanner;
use App\Support\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Đợt D mục D6: AI lỗi / quá giờ / hết lượt thì MỌI tính năng AI vẫn trả kết quả (chế độ luật hoặc câu mẫu), không lỗi 500.
 * Đây là điều kiện để demo không hỏng khi hạn mức gói miễn phí hết giữa buổi bảo vệ.
 */
class AiOutageTest extends TestCase
{
    use RefreshDatabase;

    private int $uid;
    private int $trip;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ai.enabled' => true, 'core_destinations.slugs' => []]);
        foreach (['brief', 'explain', 'copilot', 'packing', 'booking_read', 'suggest'] as $f) {
            config(["ai.features.$f" => true]);
        }
        $this->uid = DB::table('tbl_users')->insertGetId(['username' => 'aiout', 'email' => 'aiout@example.com', 'password' => PasswordHasher::make('12345678'), 'fullName' => 'K', 'isActive' => 'y']);
        DB::table('tbl_destinations')->insert(['slug' => 'ha-noi', 'name' => 'Hà Nội', 'province' => 'Hà Nội', 'domain' => 'b', 'description' => 'Thủ đô.', 'latitude' => 21.03, 'longitude' => 105.85, 'isActive' => 1]);
        $this->trip = app(TripPlanner::class)->create($this->uid, ['title' => 'Hà Nội', 'days' => 2, 'groupSize' => 2]);
    }

    /** @return array<string, array{0:string,1:string,2:array}> */
    private function calls(): array
    {
        $t = $this->trip;

        return [
            'brief' => ['POST', '/len-ke-hoach/hieu-yeu-cau', ['text' => 'Đi Hà Nội 3 ngày tháng 12, 2 người']],
            'chat' => ['POST', '/len-ke-hoach/tro-ly/doc', ['said' => ['Đi Hà Nội 3 ngày tháng 12, 2 người']]],
            'explain' => ['POST', "/len-ke-hoach/$t/tom-tat-ai", []],
            'copilot' => ['POST', "/len-ke-hoach/$t/la-ban", ['text' => 'Thêm một quán cà phê buổi chiều ngày 1']],
            'packing' => ['GET', "/len-ke-hoach/$t/do-mang-theo", []],
            'booking' => ['POST', "/len-ke-hoach/$t/xac-nhan/doc", ['text' => "Mã đặt chỗ: KX7Q2M\nChuyến bay VN 1543 SGN - DLI\nKhởi hành: 07:15 05/12/2026"]],
        ];
    }

    private function hitAll(string $why): void
    {
        foreach ($this->calls() as $name => [$m, $url, $body]) {
            $r = $this->withSession(['userId' => $this->uid, 'username' => 'aiout'])->json($m, $url, $body);
            $this->assertSame(200, $r->getStatusCode(), "$name khi $why: " . mb_substr($r->getContent(), 0, 300));
            $this->assertIsArray($r->json(), "$name khi $why không trả JSON");
        }
    }

    public function test_every_ai_feature_survives_llm_errors_timeouts_and_bad_json(): void
    {
        foreach (['error', 'timeout', 'schema'] as $fail) {
            Cache::flush();
            $this->app->instance(StructuredLlm::class, new FakeLlm([], $fail));
            $this->hitAll("LLM $fail");
        }
    }

    public function test_every_ai_feature_survives_quota_and_circuit_and_disabled(): void
    {
        $this->app->instance(StructuredLlm::class, new FakeLlm([], 'error'));
        config(['ai.limits.daily_per_user' => 0]);
        $this->hitAll('hết hạn mức');

        Cache::put('ai:cb:until', time() + 60, 60);
        $this->hitAll('ngắt mạch');

        Cache::flush();
        config(['ai.enabled' => false]);
        $this->hitAll('tắt AI');
    }
}
