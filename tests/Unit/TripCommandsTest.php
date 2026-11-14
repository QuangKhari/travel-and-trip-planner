<?php

namespace Tests\Unit;

use App\Services\Ai\Copilot\TripCommands as T;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class TripCommandsTest extends TestCase
{
    public function test_day_and_trip_commands(): void
    {
        $t = Carbon::parse('2026-11-14');
        $p = fn(string $q) => T::parse($q, 3, '2026-12-01', $t);
        $this->assertSame(['cmd' => 'clear_day', 'day' => 2], $p('bỏ ngày 2'));
        $this->assertSame(['cmd' => 'clear_day', 'day' => 3], $p('Xóa hết lịch ngày 3 giúp mình'));
        $this->assertSame(['cmd' => 'swap', 'a' => 1, 'b' => 3], $p('đổi ngày 3 với ngày 1'));
        $this->assertSame(['cmd' => 'days', 'n' => 2], $p('rút còn 2 ngày'));
        $this->assertSame(['cmd' => 'days', 'n' => 4], $p('thêm 1 ngày'));
        $this->assertSame(['cmd' => 'shift', 'to' => '2026-12-20'], $p('dời chuyến sang 20/12'));
        $this->assertSame(['cmd' => 'shift', 'to' => '2026-12-03'], $p('lùi chuyến 2 ngày'));
        $this->assertSame(['cmd' => 'shift', 'to' => '2026-11-29'], $p('đi sớm hơn 2 ngày'));
        foreach (['bỏ bảo tàng ở ngày 2', 'thêm quán ăn vào ngày 2', 'đổi giờ ngày 2', 'đổi ngày 2 với ngày 5', 'bỏ ngày 4'] as $q) {
            $this->assertNull($p($q), $q);
        }
    }
}
