<?php

namespace Tests\Feature;

use App\Services\Ai\Explain\AlertExplainer;
use App\Services\Ai\Llm\FakeLlm;
use App\Services\Ai\Llm\StructuredLlm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Đợt E (AP2): LLM chỉ diễn đạt lại dữ kiện của thẻ cảnh báo; sai số liệu / bịa cách sửa -> dùng mẫu. */
class AlertExplainerTest extends TestCase
{
    use RefreshDatabase;

    private array $card = ['id' => 'alert:weather.rain:i3', 'kind' => 'alert', 'tone' => 'warn', 'title' => 'Có thể mưa ngày 2',
        'sub' => 'Đồi chè Cầu Đất', 'text' => 'Khả năng mưa 80% từ 14:00 đến 17:00, trùng giờ tham quan ngoài trời.', 'day' => 2,
        'source' => 'Dự báo theo giờ Open-Meteo',
        'actions' => [['label' => 'Thay bằng Bảo tàng Lâm Đồng', 'hint' => 'Trong nhà'], ['label' => 'Dời sang ngày 3 (khô ráo hơn)']]];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ai.enabled' => true, 'ai.features.explain' => true]);
    }

    private function run_(array $data, ?string $fail = null): array
    {
        $this->app->instance(StructuredLlm::class, new FakeLlm(['explain' => $data], $fail));
        Cache::flush();

        return app(AlertExplainer::class)->explain($this->card, null);
    }

    public function test_grounded_answer_is_kept(): void
    {
        $r = $this->run_(['why' => 'Ngày 2 có khả năng mưa 80% đúng lúc bạn ở Đồi chè Cầu Đất ngoài trời.',
            'fix' => 'Nên chọn "Thay bằng Bảo tàng Lâm Đồng" vì là nơi trong nhà.']);
        $this->assertSame('llm', $r['engine']);
        $this->assertStringContainsString('80%', $r['why']);
    }

    public function test_invented_number_falls_back_to_template(): void
    {
        $r = $this->run_(['why' => 'Mưa 95% cả ngày.', 'fix' => 'Nên chọn "Thay bằng Bảo tàng Lâm Đồng".']);
        $this->assertSame('template', $r['engine']);
        $this->assertStringContainsString('Open-Meteo', $r['why']);
        $this->assertStringContainsString('Thay bằng Bảo tàng Lâm Đồng', $r['fix']);
    }

    public function test_invented_fix_falls_back(): void
    {
        $r = $this->run_(['why' => 'Ngày 2 có thể mưa ở Đồi chè Cầu Đất.', 'fix' => 'Hãy đi cáp treo thay thế.']);
        $this->assertSame('template', $r['engine']);
    }

    public function test_llm_down_uses_template(): void
    {
        $r = $this->run_([], 'timeout');
        $this->assertSame('template', $r['engine']);
        $this->assertNotEmpty($r['why']);
    }

    public function test_template_without_options(): void
    {
        $f = AlertExplainer::facts(['title' => 'Đóng cửa đúng ngày 1', 'text' => 'Thứ Hai nghỉ.']);
        $t = AlertExplainer::template($f);
        $this->assertNull($t['fix']);
        $this->assertSame('Thứ Hai nghỉ.', $t['why']);
        $c = AlertExplainer::template(AlertExplainer::facts(['id' => 'alert:hours.closed:i2', 'title' => 'Đóng cửa đúng ngày 1', 'sub' => 'Bảo tàng Lâm Đồng', 'actions' => [['label' => 'Chuyển vào Đã lưu']]]));
        $this->assertStringContainsString('nghỉ đúng ngày', $c['why']);
        $this->assertStringContainsString('Chuyển vào Đã lưu', $c['fix']);
    }
}
