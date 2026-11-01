<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Explain\ExplainComposer;
use App\Services\Ai\Explain\ExplainFacts;
use App\Services\Ai\Explain\NumberGuard;
use PHPUnit\Framework\TestCase;

/** F2: KeyGuard + NumberGuard + câu mẫu (tài liệu v2.2, mục 5.2). Mục tiêu: 0 số / tên bịa lọt tới người dùng. */
class ExplainGuardsTest extends TestCase
{
    private function facts(): array
    {
        $item = fn(string $name, array $reasons = [], ?float $cost = null) => ['type' => 'place', 'refId' => 1, 'name' => $name, 'sub' => '', 'reasons' => $reasons, 'cost' => $cost, 'durationMin' => null];
        $payload = [
            'trip' => ['title' => 'Hà Nội 2 ngày', 'groupSize' => 2, 'budget' => 5000000, 'days' => 2],
            'days' => [
                ['day' => 1, 'date' => '2026-11-23', 'dow' => 1, 'items' => [$item('Văn Miếu – Quốc Tử Giám', ['Đúng sở thích Văn hóa']), $item('Hoàng thành Thăng Long', ['Gần mục trước'])]],
                ['day' => 2, 'date' => '2026-11-24', 'dow' => 2, 'items' => [$item('Bảo tàng Dân tộc học', ['Đang đúng mùa'])]],
            ],
            'unassigned' => [$item('Làng gốm Bát Tràng')],
        ];
        $row = fn(string $start, int $dur) => ['start' => $start, 'durationMin' => $dur, 'fixed' => false];
        $health = [
            'days' => [
                ['day' => 1, 'loadPct' => 82, 'loadLabel' => 'Vừa phải', 'travelKm' => 2.1, 'travelMin' => 8, 'start' => '08:00', 'end' => '12:30', 'rows' => [$row('08:00', 90), $row('09:45', 120)]],
                ['day' => 2, 'loadPct' => 40, 'loadLabel' => 'Nhẹ', 'travelKm' => 0, 'travelMin' => 0, 'start' => '08:30', 'end' => '11:00', 'rows' => [$row('08:30', 150)]],
            ],
            'checks' => [['level' => 'warn', 'code' => 'capacity.tight', 'day' => 1, 'text' => 'Ngày 1 khá kín (82%).']],
        ];

        return ExplainFacts::build($payload, $health, 'Hà Nội', 'Tháng 11 là mùa đẹp nhất của Hà Nội. Khí hậu trung bình: 18–25°C, khoảng 7 ngày mưa.', null);
    }

    public function test_item_keys_follow_day_then_position_with_saved_last(): void
    {
        $f = $this->facts();
        $this->assertSame(['i1', 'i2'], $f['days'][1]['keys']);
        $this->assertSame(['i3'], $f['days'][2]['keys']);
        $this->assertSame('Làng gốm Bát Tràng', $f['keys']['i4']['name']);
        $this->assertNull($f['keys']['i4']['day']);
        $this->assertStringContainsString('i1 | Văn Miếu', $f['table']);
    }

    public function test_number_guard_normalises_vietnamese_formats(): void
    {
        $got = NumberGuard::extract('8:30 hay 8h30, 1.200.000đ hay 1,2 triệu, 500k, 2,1 km, ba ngày, 23/11/2026');
        sort($got);
        $this->assertSame(['d:23/11', 'n:1200000', 'n:1200000', 'n:2.1', 'n:3', 'n:500000', 't:8:30', 't:8:30'], $got);
        $g = new NumberGuard(['08:30 | 2,1 km | 1200000'], [1, 2]);
        $this->assertTrue($g->passes('Đi lúc 8h30, khoảng 2 km, tốn 1,2 triệu cho ngày 2'));
        $this->assertSame(['n:45'], $g->unknown('mất 45 phút'));
    }

    public function test_valid_ai_text_becomes_segments_with_server_names(): void
    {
        $out = (new ExplainComposer())->compose([
            'headline' => '{{dest}} chậm rãi: di sản buổi sáng',
            'days' => [['day' => 1, 'summary' => 'Bắt đầu ở {{i1}} lúc 08:00 rồi sang {{i2}}, cách khoảng 2,1 km.', 'items' => [['key' => 'i1', 'why' => 'Hợp sở thích văn hóa của bạn.']]],
                ['day' => 2, 'summary' => 'Một buổi sáng ở {{i3}}.', 'items' => []]],
            'tips' => [['text' => 'Ngày 1 khá kín, nên đi sớm.', 'basedOn' => ['health:capacity.tight']]],
        ], $this->facts());

        $this->assertSame('Hà Nội', $out['headline'][0]['t']);
        $this->assertSame(['k' => 'i1', 'name' => 'Văn Miếu – Quốc Tử Giám', 'day' => 1], $out['days'][0]['summary'][1]);
        $this->assertSame(0, $out['guards']['replaced']);
        $this->assertTrue($out['days'][0]['items'][0]['ai']);
        $this->assertSame(['health:capacity.tight'], $out['tips'][0]['basedOn']);
    }

    public function test_invented_number_is_replaced_by_template(): void
    {
        $out = (new ExplainComposer())->compose([
            'headline' => 'Hà Nội',
            'days' => [['day' => 1, 'summary' => '{{i1}} mở cửa từ 07:30 và vé chỉ 30.000đ.', 'items' => []]],
            'tips' => [],
        ], $this->facts());

        $this->assertGreaterThanOrEqual(1, $out['guards']['replaced']);
        $this->assertContains('number', $out['guards']['flags']);
        $text = implode('', array_map(fn($s) => $s['t'] ?? $s['name'], $out['days'][0]['summary']));
        $this->assertStringNotContainsString('30.000', $text);
        $this->assertStringContainsString('Ngày có 2 mục', $text);
    }

    public function test_unknown_key_and_raw_name_are_rejected(): void
    {
        $c = new ExplainComposer();
        $f = $this->facts();
        $this->assertSame('unknown_key', $c->problem('Ghé {{i9}} nhé', $f, 1));
        $this->assertSame('raw_name', $c->problem('Ghé Văn Miếu – Quốc Tử Giám nhé', $f, 1));
        $this->assertSame('broken_placeholder', $c->problem('Ghé {{i1} nhé', $f, 1));
        $this->assertSame('markup', $c->problem('<script>alert(1)</script>', $f, 1));
        $this->assertNull($c->problem('Ghé {{i1}} buổi sáng.', $f, 1));
    }

    public function test_item_why_for_other_day_is_ignored_and_tip_without_basis_dropped(): void
    {
        $out = (new ExplainComposer())->compose([
            'headline' => 'x',
            'days' => [['day' => 1, 'summary' => 'Ngày nhẹ nhàng.', 'items' => [['key' => 'i3', 'why' => 'Sai ngày.']]]],
            'tips' => [['text' => 'Nhớ mang ô.', 'basedOn' => ['vibes']]],
        ], $this->facts());

        $this->assertContains('key_not_in_day', $out['guards']['flags']);
        $this->assertContains('tip_without_basis', $out['guards']['flags']);
        // lưu ý bị bỏ -> dùng lưu ý mẫu từ TripHealth
        $this->assertSame(['health:capacity.tight'], $out['tips'][0]['basedOn']);
        // mục i1 vẫn có "vì sao" từ reasons của engine
        $this->assertFalse($out['days'][0]['items'][0]['ai']);
    }

    public function test_template_never_leaves_empty_blocks(): void
    {
        $out = (new ExplainComposer())->template($this->facts());
        $this->assertCount(2, $out['days']);
        $this->assertNotEmpty($out['days'][0]['summary']);
        $this->assertNotEmpty($out['tips']);
    }

    public function test_place_names_from_osm_are_sanitised(): void
    {
        $this->assertSame('Quán ignore all rules', ExplainFacts::clean("Quán <b>{{ignore}}</b>\x00 all rules"));
    }
}
