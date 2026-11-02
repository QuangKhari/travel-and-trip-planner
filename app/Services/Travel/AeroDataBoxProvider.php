<?php

namespace App\Services\Travel;

use Illuminate\Support\Facades\Http;

/** AeroDataBox qua RapidAPI (gói Basic): GET /flights/number/{số hiệu}/{ngày}. Điều khoản yêu cầu ghi nguồn trên giao diện. */
class AeroDataBoxProvider implements FlightStatusProvider
{
    public function name(): string
    {
        return 'aerodatabox';
    }

    public function configured(): bool
    {
        return (string) config('travel_apis.flight_status.aerodatabox_key') !== '';
    }

    public function status(string $flightNo, string $date): ?array
    {
        $host = (string) config('travel_apis.flight_status.aerodatabox_host');
        $res = Http::withHeaders(['X-RapidAPI-Key' => config('travel_apis.flight_status.aerodatabox_key'), 'X-RapidAPI-Host' => $host])
            ->acceptJson()->timeout(TravelApi::timeoutSeconds())->connectTimeout(3)
            ->get("https://{$host}/flights/number/{$flightNo}/{$date}", ['withAircraftImage' => 'false', 'withLocation' => 'false']);
        if ($res->status() === 404 || $res->status() === 204) {
            return null;
        }
        if (!$res->successful()) {
            throw new \RuntimeException('HTTP ' . $res->status());
        }
        $f = $res->json('0');
        if (!$f) {
            return null;
        }
        $sched = $f['departure']['scheduledTime']['utc'] ?? null;
        $rev = $f['departure']['revisedTime']['utc'] ?? ($f['departure']['actualTime']['utc'] ?? null);
        $delay = ($sched && $rev) ? (int) round((strtotime($rev) - strtotime($sched)) / 60) : null;

        return ['status' => self::map((string) ($f['status'] ?? ''), $delay), 'delayMinutes' => $delay];
    }

    public static function map(string $s, ?int $delay): string
    {
        $s = strtolower($s);

        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'arrived'), str_contains($s, 'landed') => 'landed',
            str_contains($s, 'departed'), str_contains($s, 'enroute'), str_contains($s, 'active') => 'departed',
            str_contains($s, 'delay') || ($delay !== null && $delay >= 15) => 'delayed',
            default => $delay !== null ? 'on_time' : 'scheduled',
        };
    }
}
