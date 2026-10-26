<?php

namespace Tests\Unit;

use App\Services\Content\PublishGate;
use App\Support\PlaceKind;
use PHPUnit\Framework\TestCase;

/** Tuần 1: điều kiện RIÊNG để trải nghiệm vào Planner (không chặn xuất bản) và nhãn kind địa điểm. */
class PlannerGateTest extends TestCase
{
    public function test_experience_needs_difficulty_and_minutes_for_planner(): void
    {
        $this->assertCount(2, PublishGate::plannerProblems('experiences', (object) []));
        $this->assertCount(1, PublishGate::plannerProblems('experiences', (object) ['difficulty' => 'easy']));
        $this->assertSame([], PublishGate::plannerProblems('experiences', (object) ['difficulty' => 'hard', 'durationMinutes' => 90]));
    }

    public function test_other_kinds_have_no_planner_conditions(): void
    {
        $this->assertSame([], PublishGate::plannerProblems('foods', (object) []));
    }

    public function test_place_kind_labels(): void
    {
        $this->assertSame('Bãi biển', PlaceKind::label('beach'));
        $this->assertSame('hang dong la', PlaceKind::label('hang_dong_la'));
        $this->assertNull(PlaceKind::label(null));
        $this->assertNull(PlaceKind::label(' '));
    }
}
