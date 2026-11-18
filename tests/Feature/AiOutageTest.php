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

    /** Copilot lỗi liên tiếp chỉ ngắt mạch Copilot; Brief / Explain vẫn gọi được model. Khóa chung vẫn ngắt toàn bộ. */
    public function test_circuit_breaker_is_per_feature(): void
    {
        $this->app->instance(StructuredLlm::class, new FakeLlm([], 'error'));
        config(['ai.cooldown.fail_threshold' => 3]);
        $gw = app(\App\Services\Ai\AiGateway::class);
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($gw->json('copilot', $this->uid, 's', 'u', ['type' => 'object'])['ok']);
        }
        $this->assertSame('circuit', $gw->blocked('copilot', $this->uid));
        $this->assertNull($gw->blocked('brief', $this->uid), 'lỗi của Copilot không khóa Brief');
        $this->assertNull($gw->blocked('explain', $this->uid));
        $this->assertArrayHasKey('copilot', \App\Services\Ai\AiGateway::openCircuits());

        Cache::put('ai:cb:until', time() + 60, 60);   // khóa chung (quản trị / kiểm thử)
        $this->assertSame('circuit', $gw->blocked('brief', $this->uid));
        \App\Services\Ai\AiGateway::resetCircuits();
        $this->assertNull($gw->blocked('copilot', $this->uid));
    }

    /** Còn 1 lượt mà 2 request cùng lúc: chỉ 1 được gọi model (đặt trước lượt nguyên tử, không chỉ đếm tbl_ai_runs). */
    public function test_quota_is_reserved_atomically_for_concurrent_calls(): void
    {
        config(['ai.limits.daily_per_user' => 1]);
        $gw = app(\App\Services\Ai\AiGateway::class);
        $uid = $this->uid;
        $inner = null;
        $calls = 0;
        // model giả: trong lúc request 1 ĐANG chờ model, request 2 của cùng người dùng tới (mô phỏng đồng thời)
        $llm = new class ($gw, $uid, $inner, $calls) implements StructuredLlm {
            public function __construct(private $gw, private int $uid, private &$inner, private &$calls) {}
            public function name(): string
            {
                return 'fake';
            }
            public function model(): string
            {
                return 'fake';
            }
            public function available(): bool
            {
                return true;
            }
            public function generate(string $system, string $user): array
            {
                return [];
            }
            public function generateJson(string $system, string $user, array $schema, array $opt = []): array
            {
                $this->calls++;
                if ($this->inner === null) {
                    $this->inner = $this->gw->json('brief', $this->uid, 's', 'u2', $schema);
                }

                return ['data' => ['ok' => true], 'input_tokens' => 1, 'output_tokens' => 1];
            }
        };
        $this->app->instance(StructuredLlm::class, $llm);
        $first = $gw->json('brief', $uid, 's', 'u1', ['type' => 'object']);
        $this->assertTrue($first['ok']);
        $this->assertSame('quota', $inner['reason'], 'request thứ hai phải hết lượt dù lượt đầu chưa kịp ghi log');
        $this->assertSame(1, $calls, 'chỉ một lần gọi model');
        $this->assertSame(0, $gw->remaining($uid), 'model đã trả lời: lượt bị trừ ngay trong bộ đếm, không chờ nhật ký');
        $this->assertSame(['used' => 1, 'reserved' => 0], (array) DB::table('tbl_ai_quota')->where('userId', $uid)->first(['used', 'reserved']));
    }

    /** Gọi lỗi / quá thời gian: lượt đặt trước được trả lại. Ghi nhật ký lỗi cũng không làm "mất" việc trừ lượt. */
    public function test_failed_call_refunds_the_reserved_quota_and_counter_does_not_depend_on_log(): void
    {
        config(['ai.limits.daily_per_user' => 2]);
        $gw = app(\App\Services\Ai\AiGateway::class);
        $this->app->instance(StructuredLlm::class, new FakeLlm([], 'error'));
        $this->assertFalse($gw->json('brief', $this->uid, 's', 'u', ['type' => 'object'])['ok']);
        $this->assertSame(2, $gw->remaining($this->uid), 'lỗi: trả lại lượt');

        \App\Services\Ai\AiGateway::resetCircuits();
        $this->app->instance(StructuredLlm::class, new FakeLlm(['brief' => ['ok' => true]]));
        $this->assertTrue($gw->json('brief', $this->uid, 's', 'u', ['type' => 'object'])['ok']);
        $this->assertTrue($gw->json('brief', $this->uid, 's', 'u', ['type' => 'object'])['ok']);
        // không ghi tbl_ai_runs nào (giả lập ghi nhật ký lỗi) mà vẫn hết lượt
        $this->assertSame(0, DB::table('tbl_ai_runs')->where('userId', $this->uid)->count());
        $this->assertSame('quota', $gw->json('brief', $this->uid, 's', 'u', ['type' => 'object'])['reason']);
    }
}
