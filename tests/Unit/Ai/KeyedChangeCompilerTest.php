<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Copilot\KeyedChangeCompiler;
use PHPUnit\Framework\TestCase;

/** La bàn Copilot: biên dịch thao tác dạng khóa (iN, c:type:id) sang (ngày, vị trí) và chặn thao tác sai luật. */
class KeyedChangeCompilerTest extends TestCase
{
    private function keys(): array
    {
        $k = fn($day, $i, $name, $ref, $fixed = false, $locked = false) => ['day' => $day, 'index' => $i, 'name' => $name, 'type' => 'place', 'refId' => $ref, 'fixed' => $fixed, 'locked' => $locked];

        return [
            'i1' => $k(1, 0, 'Chợ Hàn', 11),
            'i2' => $k(1, 1, 'Bảo tàng Chăm', 12),
            'i3' => $k(1, 2, 'Cầu Rồng', 13, true),
            'i4' => $k(2, 0, 'Bà Nà', 21),
            'i5' => $k(2, 1, 'Ngũ Hành Sơn', 22),
            'i6' => $k(null, null, 'Hội An', 31),
        ];
    }

    private function cands(): array
    {
        return [
            'c:place:40' => ['type' => 'place', 'refId' => 40, 'name' => 'Bảo tàng Đà Nẵng', 'sub' => 'Bảo tàng'],
            'c:place:12' => ['type' => 'place', 'refId' => 12, 'name' => 'Bảo tàng Chăm', 'sub' => '']
        ];
    }

    private function compileOps(array $ops, array $explicit = []): array
    {
        return (new KeyedChangeCompiler())->compile($ops, $this->keys(), $this->cands(), 3, $explicit);
    }

    public function test_move_compiles_to_saved_position(): void
    {
        $r = $this->compileOps([['op' => 'move', 'item' => 'i2', 'toDay' => 3]]);
        $this->assertTrue($r['ok']);
        $this->assertSame([['op' => 'move', 'day' => 1, 'index' => 1, 'toDay' => 3]], $r['changes']);
        $this->assertSame('Bảo tàng Chăm → Ngày 3', $r['human'][0]['text']);
    }

    public function test_fixed_item_needs_explicit_mention(): void
    {
        $this->assertFalse($this->compileOps([['op' => 'move', 'item' => 'i3', 'toDay' => 2]])['ok']);
        $this->assertTrue($this->compileOps([['op' => 'move', 'item' => 'i3', 'toDay' => 2]], ['i3'])['ok']);
    }

    public function test_candidate_must_come_from_search_and_not_be_in_trip(): void
    {
        $r = $this->compileOps([['op' => 'add', 'with' => 'c:place:99', 'toDay' => 2]]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('search_places', $r['errors'][0]);
        $this->assertFalse($this->compileOps([['op' => 'add', 'with' => 'c:place:12', 'toDay' => 2]])['ok']);
    }

    public function test_add_after_anchor_uses_original_index(): void
    {
        $r = $this->compileOps([['op' => 'add', 'with' => 'c:place:40', 'toDay' => 2, 'after' => 'i4']]);
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['changes'][0]['after']);
        $this->assertSame(40, $r['changes'][0]['with']['refId']);
        $this->assertFalse($this->compileOps([['op' => 'add', 'with' => 'c:place:40', 'toDay' => 2, 'after' => 'i1']])['ok'], 'mốc phải thuộc ngày nhận');
    }

    public function test_reorder_needs_full_day_and_keeps_anchors(): void
    {
        $this->assertFalse($this->compileOps([['op' => 'reorder', 'day' => 1, 'order' => ['i2', 'i1']]])['ok'], 'thiếu mục');
        $this->assertFalse($this->compileOps([['op' => 'reorder', 'day' => 1, 'order' => ['i2', 'i1', 'i3']]])['ok'], 'mục đầu ngày đổi chỗ');
        $this->assertFalse($this->compileOps([['op' => 'reorder', 'day' => 1, 'order' => ['i1', 'i3', 'i2']]])['ok'], 'mục cố định đổi chỗ');
        $ok = $this->compileOps([['op' => 'reorder', 'day' => 2, 'order' => ['i4', 'i5']]]);
        $this->assertSame([0, 1], $ok['changes'][0]['order']);
    }

    public function test_reorder_and_move_out_same_day_rejected(): void
    {
        $r = $this->compileOps([['op' => 'move', 'item' => 'i5', 'toDay' => 3], ['op' => 'reorder', 'day' => 2, 'order' => ['i4', 'i5']]]);
        $this->assertFalse($r['ok']);
    }

    public function test_unassigned_and_unknown_keys_rejected(): void
    {
        $this->assertFalse($this->compileOps([['op' => 'move', 'item' => 'i6', 'toDay' => 1]])['ok']);
        $this->assertFalse($this->compileOps([['op' => 'move', 'item' => 'i42', 'toDay' => 1]])['ok']);
        $this->assertFalse($this->compileOps([['op' => 'drop', 'item' => 'i1']])['ok']);
        $this->assertFalse($this->compileOps([])['ok']);
    }

    public function test_time_rounds_and_bounds(): void
    {
        $r = $this->compileOps([['op' => 'time', 'item' => 'i1', 'startMin' => 512]]);
        $this->assertSame(510, $r['changes'][0]['startMin']);
        $this->assertSame('Chợ Hàn → 08:30', $r['human'][0]['text']);
        $this->assertFalse($this->compileOps([['op' => 'time', 'item' => 'i1', 'startMin' => 60]])['ok']);
    }

    public function test_same_item_twice_rejected(): void
    {
        $this->assertFalse($this->compileOps([['op' => 'move', 'item' => 'i2', 'toDay' => 3], ['op' => 'replace', 'item' => 'i2', 'with' => 'c:place:40']])['ok']);
    }
}
