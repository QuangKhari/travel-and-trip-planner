<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Copilot\CopilotContext;
use PHPUnit\Framework\TestCase;

/** Bối cảnh La bàn Copilot: khóa mục giống F2, mục đã khóa / "Đã lưu" được ghi rõ, gợi ý bắt đầu lấy từ Sức khỏe chuyến đi. */
class CopilotContextTest extends TestCase
{
    private function ctx(): array
    {
        $it = fn($n, $ref, $day, $o = []) => $o + ['type' => 'place', 'refId' => $ref, 'name' => $n, 'sub' => '', 'day' => $day, 'lat' => 21.0, 'lng' => 105.8, 'reasons' => [], 'locked' => false, 'destinationId' => 5];
        $payload = ['trip' => ['title' => 'HN', 'groupSize' => 2, 'budget' => null], 'days' => [
            ['day' => 1, 'dow' => 1, 'date' => '2026-11-16', 'items' => [$it('Văn Miếu', 1, 1), $it('Hoàng thành', 2, 1, ['locked' => true])]],
            ['day' => 2, 'dow' => 2, 'date' => '2026-11-17', 'items' => [$it('Hồ Tây', 3, 2)]]], 'unassigned' => [$it('Phố cổ', 4, null)]];
        $health = ['days' => [['day' => 1, 'rows' => [['start' => '08:00', 'durationMin' => 90, 'fixed' => true], ['start' => '10:00', 'durationMin' => 60, 'fixed' => false]],
            'loadPct' => 110, 'loadLabel' => 'Quá dày', 'travelKm' => 3.2, 'travelMin' => 12, 'start' => '08:00', 'end' => '19:00']],
            'checks' => [['level' => 'bad', 'code' => 'capacity.overload', 'day' => 1, 'text' => 'Ngày 1 quá dày']]];

        return CopilotContext::build($payload, $health, 'Hà Nội', null);
    }

    public function test_keys_carry_saved_position_and_flags(): void
    {
        $k = $this->ctx()['keys'];
        $this->assertSame([1, 0, true, false], [$k['i1']['day'], $k['i1']['index'], $k['i1']['fixed'], $k['i1']['locked']]);
        $this->assertSame([1, 1, true], [$k['i2']['day'], $k['i2']['index'], $k['i2']['locked']]);
        $this->assertNull($k['i4']['day']);
        $this->assertSame('place:2:hoàng thành', CopilotContext::identity($k['i2']));
    }

    public function test_table_lists_locked_and_saved_items(): void
    {
        $t = $this->ctx()['table'];
        $this->assertStringContainsString('i1 | Văn Miếu', $t);
        $this->assertStringContainsString('Mục đã khóa (không đụng trừ khi người dùng nêu tên): i2', $t);
        $this->assertStringContainsString('i4 | Phố cổ', $t);
    }

    public function test_quick_actions_come_from_health(): void
    {
        $q = $this->ctx()['quick'];
        $this->assertSame('Ngày 1 quá dày', $q[0]['label']);
    }

    public function test_explicit_mentions_by_text_and_pick(): void
    {
        $k = $this->ctx()['keys'];
        $this->assertSame(['i2', 'i3'], CopilotContext::explicit('Chuyển hoàng thành sang ngày 2', [['name' => 'Hồ Tây', 'day' => 2]], $k));
        $this->assertSame([], CopilotContext::explicit('Ngày 1 nhẹ hơn', [['name' => 'Hồ Tây', 'day' => 1]], $k), 'chọn đúng tên nhưng sai ngày thì không tính');
    }
}
