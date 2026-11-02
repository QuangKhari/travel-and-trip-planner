<?php

namespace App\Services\Travel;

use Illuminate\Support\Facades\Http;

/** AviationStack (gói miễn phí: HTTP, 100 lượt / tháng): GET /v1/flights?flight_iata=&flight_date= */
class AviationStackProvider implements FlightStatusProvider
{
    public function name(): string
    {
        return 'aviationstack';
    }

    public function configured(): bool
    {
        return (string) config('travel_apis.flight_status.aviationstack_key') !== '';
    }

    public function status(string $flightNo, string $date): ?array
    {
        $res = Http::acceptJson()->timeout(TravelApi::timeoutSeconds())->connectTimeout(3)
            ->get('http://api.aviationstack.com/v1/flights', [
                'access_key' => config('travel_apis.flight_status.aviationstack_key'), 'flight_iata' => $flightNo, 'flight_date' => $date,
            ]);
        if (!$res->successful() || $res->json('error')) {
            throw new \RuntimeException('HTTP ' . $res->status() . ' ' . (string) $res->json('error.code'));
        }
        $f = $res->json('data.0');
        if (!$f) {
            return null;
        }
        $delay = isset($f['departure']['delay']) ? (int) $f['departure']['delay'] : null;

        return ['status' => AeroDataBoxProvider::map((string) ($f['flight_status'] ?? ''), $delay), 'delayMinutes' => $delay];
    }
}
