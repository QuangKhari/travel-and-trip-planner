<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Ask\TripFacts;
use PHPUnit\Framework\TestCase;

/** F5: dữ kiện chuyến đưa vào AskService. Mỗi ngày một nguồn, giờ mở cửa đúng THỨ của ngày đó. */
class TripFactsTest extends TestCase
{
    public function test_one_fact_per_day_with_hours_of_that_weekday(): void
    {
        $it = fn($n, $o = []) => $o + ['type' => 'place', 'refId' => 1, 'name' => $n, 'sub' => 'Bảo tàng', 'outdoor' => false, 'hours' => null];
        $payload = ['trip' => [], 'unassigned' => [$it('Phố cổ')], 'days' => [
            ['day' => 1, 'dow' => 1, 'date' => '2026-11-16', 'items' => [$it('Bảo tàng Dân tộc học', ['hours' => ['1' => [], '2' => [[510, 1050]]]])]],
            ['day' => 2, 'dow' => 2, 'date' => '2026-11-17', 'items' => [$it('Bảo tàng Dân tộc học 2', ['hours' => ['1' => [], '2' => [[510, 1050]]], 'outdoor' => true])]],
        ]];
        $health = ['days' => [['day' => 1, 'travelKm' => 2.5, 'rows' => [['start' => '08:30', 'end' => '10:00']]]],
            'checks' => [['level' => 'bad', 'text' => 'Ngày 1: “Bảo tàng Dân tộc học” đóng cửa hôm đó.'], ['level' => 'ok', 'text' => 'ổn']]];
        $f = TripFacts::build($payload, $health, '/len-ke-hoach/5', 'Hà Nội', 'Tháng 11 mát, ít mưa.', 'đi với bố mẹ');

        $this->assertSame(['trip:day:1', 'trip:day:2', 'trip:health', 'trip:month', 'trip:notes', 'trip:saved'], array_column($f, 'key'));
        $this->assertStringContainsString('Ngày 1 (Thứ Hai 16/11/2026) ở Hà Nội: 08:30–10:00 Bảo tàng Dân tộc học', $f[0]['text']);
        $this->assertStringContainsString('giờ mở cửa hôm đó: đóng cửa', $f[0]['text']);
        $this->assertStringContainsString('giờ mở cửa hôm đó: 08:30–17:30', $f[1]['text']);
        $this->assertStringContainsString('ngoài trời', $f[1]['text']);
        $this->assertStringNotContainsString('ổn', $f[2]['text'], 'chỉ đưa vấn đề bad / warn');
        $this->assertSame('/len-ke-hoach/5#ngay-1', $f[0]['url']);
        $this->assertSame(2, TripFacts::dayOf('trip:day:2'));
        $this->assertNull(TripFacts::dayOf('season:ha-noi'));
    }
}
