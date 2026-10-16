<?php

namespace Tests\Unit\Discover;

use App\Services\Discover\Poi\GeoapifyProvider;
use App\Services\Discover\Poi\OverpassProvider;
use App\Services\Discover\PlaceSync;
use App\Services\Discover\WeatherService;
use App\Services\Discover\WikipediaClient;
use Tests\TestCase;

class DiscoverParsersTest extends TestCase
{
    public function test_commons_file_name(): void
    {
        $this->assertSame('Ly Son.jpg', WikipediaClient::commonsFileName('https://upload.wikimedia.org/wikipedia/commons/a/ab/Ly%20Son.jpg'));
        $this->assertSame('Ly_Son.jpg', WikipediaClient::commonsFileName('https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Ly_Son.jpg/320px-Ly_Son.jpg'));
    }

    public function test_license_and_summary(): void
    {
        $this->assertTrue(WikipediaClient::isFreeLicense('CC BY-SA 4.0'));
        $this->assertFalse(WikipediaClient::isFreeLicense('Fair use'));
        $this->assertNull(WikipediaClient::parseSummary(['type' => 'disambiguation', 'extract' => 'x']));
        $s = WikipediaClient::parseSummary(['title' => 'Lý Sơn', 'extract' => 'Đảo', 'coordinates' => ['lat' => 15.38, 'lon' => 109.12], 'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/X.jpg']]);
        $this->assertSame('X.jpg', $s['imageFile']);
        $this->assertSame(15.38, $s['lat']);
    }

    public function test_weather_normalize(): void
    {
        $p = WeatherService::normalize([
            'current' => ['time' => '2026-10-15T10:00', 'temperature_2m' => 29.6, 'weather_code' => 63, 'relative_humidity_2m' => 80, 'wind_speed_10m' => 12.2, 'apparent_temperature' => 33],
            'daily' => ['time' => ['2026-10-15'], 'weather_code' => [3], 'temperature_2m_max' => [31.4], 'temperature_2m_min' => [25.1], 'precipitation_probability_max' => [60]],
        ]);
        $this->assertEquals(30, $p['current']['temp']);
        $this->assertSame('Mưa vừa', $p['current']['label']);
        $this->assertSame('Nhiều mây', $p['daily'][0]['label']);
        $this->assertNull(WeatherService::normalize(['error' => true]));
    }

    public function test_overpass_parse_and_select(): void
    {
        $rows = OverpassProvider::parse(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 15.0, 'lon' => 109.0, 'tags' => ['name' => 'Bãi A', 'natural' => 'beach', 'wikidata' => 'Q1']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 15.1, 'lon' => 109.1], 'tags' => ['name' => 'Bảo tàng B', 'tourism' => 'museum']],
            ['type' => 'node', 'id' => 3, 'lat' => 15, 'lon' => 109, 'tags' => ['natural' => 'beach']],
        ]]);
        $this->assertCount(2, $rows);
        $this->assertSame('node/1', $rows[0]['externalId']);
        $this->assertSame('culture', $rows[1]['category']);
        $sel = PlaceSync::select($rows, 15, 109, ['see' => 5, 'culture' => 5]);
        $this->assertCount(2, $sel);
        $this->assertSame('see', $sel[0]['category']);
    }

    public function test_geoapify_parse(): void
    {
        $r = GeoapifyProvider::parse(['features' => [['properties' => ['name' => 'Chợ', 'place_id' => 'abc', 'lat' => 1, 'lon' => 2, 'categories' => ['catering.restaurant']]]]], 'eat');
        $this->assertSame('abc', $r[0]['externalId']);
    }
}
