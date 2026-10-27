<?php

namespace Tests\Unit;

use App\Services\Content\CoverageScorer;
use App\Services\Content\ItineraryValidator;
use Tests\TestCase;

/** Tuần 3: kiểm tra điểm dừng lịch trình (thuần PHP) và công thức điểm Coverage. */
class ItineraryValidatorTest extends TestCase
{
    private const TYPES = ['destination', 'place', 'food', 'event', 'experience'];

    private function row(array $o = []): array
    {
        return $o + ['dayNo' => 1, 'refType' => null, 'refId' => null, 'label' => 'x', 'slot' => null, 'durationMin' => null];
    }

    public function test_points_and_percent_formula(): void
    {
        $this->assertSame(10.0, CoverageScorer::points(20, 30, 15));
        $this->assertSame(20.0, CoverageScorer::points(20, 30, 99));
        $this->assertSame(0.0, CoverageScorer::points(5, 0, 3));
        $this->assertSame(87, CoverageScorer::percent(86.5, 100));
        $this->assertSame(100, CoverageScorer::percent(95, 95));
        $this->assertSame(0, CoverageScorer::percent(5, 0));
    }

    public function test_structure_errors_block_saving(): void
    {
        $v = fn(array $items, int $days = 2) => ItineraryValidator::check($items, $days, self::TYPES);

        $this->assertSame([], $v([$this->row(['refType' => 'food', 'refId' => 5, 'slot' => 'morning', 'durationMin' => 60])])['errors']);
        $this->assertCount(1, $v([$this->row(['dayNo' => 3])])['errors']);
        $this->assertStringContainsString('khung giờ', $v([$this->row(['slot' => 'midnight'])])['errors'][0]);
        $this->assertStringContainsString('thời lượng', $v([$this->row(['durationMin' => 2])])['errors'][0]);
        $this->assertCount(1, $v([$this->row(['refType' => 'food'])])['errors']);       // food thiếu refId
        $this->assertCount(1, $v([$this->row(['label' => ''])])['errors']);              // thiếu tên
        $this->assertCount(1, $v([$this->row(['refType' => 'bus', 'refId' => 1])])['errors']);
    }

    public function test_transport_has_no_ref(): void
    {
        $ok = ItineraryValidator::check([$this->row(['refType' => 'transport', 'label' => 'Taxi', 'durationMin' => 30])], 1, self::TYPES);
        $this->assertSame([], $ok['errors']);
        $bad = ItineraryValidator::check([$this->row(['refType' => 'transport', 'refId' => 7, 'label' => 'Taxi'])], 1, self::TYPES);
        $this->assertCount(1, $bad['errors']);
    }

    public function test_dense_day_and_order_warnings(): void
    {
        $dense = [];
        for ($i = 0; $i < 7; $i++) {
            $dense[] = $this->row(['label' => "x$i", 'slot' => 'morning', 'durationMin' => 90]);
        }
        $r = ItineraryValidator::check($dense, 1, self::TYPES);
        $this->assertSame([], $r['errors']);
        $this->assertSame(630, $r['days'][1]['minutes']);
        $this->assertCount(1, array_filter($r['warnings'], fn($w) => str_contains($w, 'quá dày')));
        $this->assertCount(1, array_filter($r['warnings'], fn($w) => str_contains($w, 'vượt quỹ')));

        $order = ItineraryValidator::check([$this->row(['slot' => 'evening', 'durationMin' => 60]), $this->row(['slot' => 'morning', 'durationMin' => 60])], 1, self::TYPES);
        $this->assertCount(1, array_filter($order['warnings'], fn($w) => str_contains($w, 'theo khung giờ')));

        $empty = ItineraryValidator::check([$this->row()], 2, self::TYPES);
        $this->assertContains('Ngày 2 chưa có điểm dừng.', $empty['warnings']);
    }

    public function test_missing_minutes_use_estimates(): void
    {
        $r = ItineraryValidator::check([
            $this->row(['refType' => 'event', 'refId' => 1]), $this->row(['refType' => 'experience', 'refId' => 1]), $this->row(['refType' => 'food', 'refId' => 1]),
        ], 1, self::TYPES);
        $this->assertSame(360, $r['days'][1]['minutes']);   // 180 + 120 + 60
        $this->assertTrue($r['days'][1]['estimated']);
    }

    public function test_slot_labels_and_rank(): void
    {
        $this->assertSame('Trưa', ItineraryValidator::slotLabel('noon'));
        $this->assertNull(ItineraryValidator::slotLabel(null));
        $this->assertNull(ItineraryValidator::slotLabel('x'));
        $this->assertSame([1, 4, 99], [ItineraryValidator::slotRank('morning'), ItineraryValidator::slotRank('evening'), ItineraryValidator::slotRank(null)]);
    }
}
