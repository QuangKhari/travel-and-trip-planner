<?php

namespace Tests\Unit;

use App\Services\Content\SearchIntent;
use Tests\TestCase;

/** Tuần 6, mục 11: 20 câu kiểm thử Search Intent (rule-based, không cần DB hay LLM). */
class SearchIntentTest extends TestCase
{
    private function parse(string $q): array
    {
        $d = fn(int $id, string $slug, string $name, string $prov = '') => ['id' => $id, 'slug' => $slug, 'name' => $name, 'names' => [self::f($name)], 'provinces' => $prov ? [self::f($prov)] : []];
        $dests = [$d(1, 'ha-noi', 'Hà Nội'), $d(2, 'da-lat', 'Đà Lạt', 'Lâm Đồng'), $d(3, 'hoi-an', 'Hội An', 'Quảng Nam'), $d(4, 'hue', 'Huế', 'Thừa Thiên Huế'),
            $d(5, 'da-nang', 'Đà Nẵng'), $d(6, 'sa-pa', 'Sa Pa', 'Lào Cai'), $d(7, 'phu-quoc', 'Phú Quốc', 'Kiên Giang')];

        return (new SearchIntent())->parse($q, $dests, new \DateTimeImmutable('2026-10-28'));
    }

    private static function f(string $s): string
    {
        return \App\Services\Content\SearchCatalog::fold($s);
    }

    public function test_destination_and_month(): void
    {
        $r = $this->parse('Đà Lạt tháng 12');
        $this->assertSame('da-lat', $r['destination']['slug']);
        $this->assertSame(12, $r['month']);
        $this->assertSame('season', $r['primary']);
    }

    public function test_unaccented_input_is_same_as_accented(): void
    {
        $a = $this->parse('Hà Nội 3 ngày văn hóa');
        $b = $this->parse('ha noi 3 ngay van hoa');
        $this->assertSame($a['destination'], $b['destination']);
        $this->assertSame([3, ['culture']], [$b['days'], $b['interests']]);
    }

    public function test_destination_days_interest(): void
    {
        $r = $this->parse('Hà Nội 3 ngày văn hóa');
        $this->assertSame([1, 3, ['culture']], [$r['destination']['id'], $r['days'], $r['interests']]);
        $this->assertSame('plan', $r['primary']);
    }

    public function test_food_question(): void
    {
        $r = $this->parse('ăn gì Huế');
        $this->assertSame('hue', $r['destination']['slug']);
        $this->assertContains('food', $r['types']);
        $this->assertSame('food', $r['primary']);
        $this->assertSame([], $r['terms']);
    }

    public function test_days_and_budget_in_millions(): void
    {
        $r = $this->parse('4 ngày Hội An 5 triệu');
        $this->assertSame([4, 5000000.0], [$r['days'], $r['budget']]);
        $this->assertSame('hoi-an', $r['destination']['slug']);
    }

    public function test_days_and_nights(): void
    {
        $this->assertSame(3, $this->parse('Đà Nẵng 3 ngày 2 đêm')['days']);
    }

    public function test_days_written_in_words(): void
    {
        $this->assertSame(3, $this->parse('đi Huế ba ngày')['days']);
    }

    public function test_weekend_means_two_days(): void
    {
        $this->assertSame(2, $this->parse('cuối tuần Đà Lạt')['days']);
    }

    public function test_days_over_limit_are_dropped(): void
    {
        $this->assertNull($this->parse('Hà Nội 30 ngày')['days']);
    }

    public function test_this_month(): void
    {
        $this->assertSame(10, $this->parse('Sa Pa tháng này')['month']);
    }

    public function test_invalid_month_ignored(): void
    {
        $this->assertNull($this->parse('Huế tháng 13')['month']);
    }

    public function test_group_size(): void
    {
        $r = $this->parse('Phú Quốc 4 ngày 2 người');
        $this->assertSame([4, 2], [$r['days'], $r['groupSize']]);
    }

    public function test_interest_nature_and_food(): void
    {
        $r = $this->parse('Đà Nẵng thiên nhiên và ẩm thực');
        $this->assertEqualsCanonicalizing(['nature', 'food'], $r['interests']);
    }

    public function test_dislike_trekking_is_not_adventure_interest(): void
    {
        $r = $this->parse('Sa Pa 3 ngày không trekking');
        $this->assertSame(['trekking'], $r['dislikes']);
        $this->assertNotContains('adventure', $r['interests']);
    }

    public function test_dislike_crowds(): void
    {
        $this->assertSame(['crowds'], $this->parse('Đà Lạt ít đông thư giãn')['dislikes']);
    }

    public function test_experience_question(): void
    {
        $r = $this->parse('Hội An có trải nghiệm gì');
        $this->assertContains('experience', $r['types']);
        $this->assertSame('hoi-an', $r['destination']['slug']);
    }

    public function test_event_question(): void
    {
        $r = $this->parse('lễ hội Huế tháng 4');
        $this->assertContains('event', $r['types']);
        $this->assertSame([4, 'hue'], [$r['month'], $r['destination']['slug']]);
    }

    public function test_tour_question(): void
    {
        $this->assertContains('tour', $this->parse('đặt tour Phú Quốc')['types']);
    }

    public function test_province_name_resolves_when_no_destination_name(): void
    {
        $this->assertSame('hoi-an', $this->parse('Quảng Nam 2 ngày')['destination']['slug']);
    }

    public function test_synonym_sapa_one_word(): void
    {
        $this->assertSame('sa-pa', $this->parse('sapa tháng 9')['destination']['slug']);
    }

    public function test_no_destination_gives_no_planner_link(): void
    {
        $r = $this->parse('món ngon nên thử');
        $this->assertNull($r['destination']);
        $this->assertFalse(SearchIntent::plannable($r));
        $this->assertSame([], $r['plannerQuery']);
    }

    public function test_plannable_needs_destination_and_a_signal(): void
    {
        $this->assertTrue(SearchIntent::plannable($this->parse('Huế 2 ngày')));
        $this->assertFalse(SearchIntent::plannable($this->parse('Huế')));
    }

    public function test_free_text_left_over_becomes_search_terms(): void
    {
        $r = $this->parse('Huế bún bò');
        $this->assertSame(['bun', 'bo'], $r['terms']);
        $this->assertSame('Huế bun bo', $r['searchQuery']);
    }
}
