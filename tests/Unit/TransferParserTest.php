<?php

namespace Tests\Unit;

use App\Services\Planner\TransferParser;
use Tests\TestCase;

/** Đọc giờ tới / giờ về và phương tiện trong câu của người dùng (luật, chạy cả khi AI tắt). */
class TransferParserTest extends TestCase
{
    public function test_reads_arrival_departure_and_kind(): void
    {
        $this->assertSame(['moveKind' => 'flight', 'arriveAt' => '14:00', 'leaveAt' => '17:30'], TransferParser::parse('Đà Lạt 3 ngày, bay tới 14h, về chuyến 17h30 ngày cuối'));
        $this->assertSame(['moveKind' => 'train', 'arriveAt' => '06:00'], TransferParser::parse('đi tàu, tới nơi lúc 6 giờ sáng'));
        $this->assertSame(['moveKind' => 'bus', 'leaveAt' => '21:00'], TransferParser::parse('xe khách về lúc 9 giờ tối'));
        $this->assertSame(['arriveAt' => '14:15'], TransferParser::parse('đến 2:15 chiều'));
    }

    public function test_numbers_without_time_unit_are_not_times(): void
    {
        $this->assertSame([], TransferParser::parse('Đi Đà Lạt 3 ngày tháng 12, 2 người, khoảng 5 triệu'));
        $this->assertSame([], TransferParser::parse('đến Hội An 4 ngày'));
        $this->assertSame([], TransferParser::parse('tới lúc 25h'));
    }

    public function test_minutes_apply_station_buffers(): void
    {
        $d = TransferParser::minutes(['moveKind' => 'flight', 'arriveAt' => '13:00', 'leaveAt' => '17:00']);
        $this->assertSame(13 * 60 + 45, $d['arriveMin']);
        $this->assertSame(15 * 60, $d['leaveMin']);
        $self = TransferParser::minutes(['moveKind' => 'self', 'arriveAt' => '10:00']);
        $this->assertSame(600, $self['arriveMin']);
    }
}
