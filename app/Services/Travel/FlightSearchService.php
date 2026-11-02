<?php

namespace App\Services\Travel;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * T-36 tìm vé máy bay (Duffel). Cache 15 phút trong tbl_flight_offer_cache và không giữ quá hạn của offer.
 * Không có token / TRAVEL_API_SIMULATE / lỗi / timeout -> offer giả lập (có nhãn), trang vẫn chạy.
 *
 * @phpstan-type Offer array{id:string,carrier:string,flightNo:string,from:string,to:string,departAt:string,arriveAt:string,durationMin:int,stops:int,price:int,currency:string,simulated:bool}
 */
class FlightSearchService
{
    public const SERVICE = 'duffel';

    /** @return array{offers:list<array>,simulated:bool,provider:string,label:?string,error:?string,cached:bool} */
    public function search(string $from, string $to, string $date, int $adults): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $adults = max(1, min(9, $adults));
        $key = "route:flight:{$from}:{$to}:{$date}:{$adults}";

        $hit = DB::table('tbl_flight_offer_cache')->where('cacheKey', $key)->where('expiresAt', '>', now())->first();
        if ($hit) {
            return ['offers' => json_decode($hit->payload, true), 'simulated' => (bool) $hit->simulated, 'provider' => $hit->provider,
                'label' => $hit->simulated ? TravelApi::SIM_LABEL : null, 'error' => null, 'cached' => true];
        }

        $error = null;
        $offers = null;
        $token = (string) config('travel_apis.flight_search.duffel_token');
        if (!TravelApi::simulateOnly() && $token !== '' && TravelApi::available(self::SERVICE)) {
            try {
                $offers = $this->duffel($token, $from, $to, $date, $adults);
                TravelApi::ok(self::SERVICE);
            } catch (\Throwable $e) {
                TravelApi::failed(self::SERVICE, mb_substr($e->getMessage(), 0, 120));
                $error = 'Tạm thời không lấy được giá vé, đang hiển thị dữ liệu thử nghiệm.';
            }
        }
        $simulated = $offers === null;
        if ($simulated) {
            $offers = $this->simulate($from, $to, $date, $adults);
        }

        // hết hạn: sớm nhất giữa TTL cache và hạn của offer (Duffel trả expires_at)
        $exp = now()->addMinutes((int) config('travel_apis.flight_search.cache_minutes', 15));
        foreach ($offers as $o) {
            if (!empty($o['expiresAt']) && Carbon::parse($o['expiresAt'])->lt($exp)) {
                $exp = Carbon::parse($o['expiresAt']);
            }
        }
        // lỗi tạm thời: chỉ cache giả lập ngắn để thử lại API sớm
        if ($simulated && $error) {
            $exp = now()->addMinutes(2);
        }
        DB::table('tbl_flight_offer_cache')->upsert([[
            'cacheKey' => $key, 'provider' => $simulated ? 'simulated' : 'duffel', 'simulated' => $simulated,
            'payload' => json_encode($offers, JSON_UNESCAPED_UNICODE), 'expiresAt' => $exp, 'createdAt' => now(),
        ]], ['cacheKey'], ['provider', 'simulated', 'payload', 'expiresAt', 'createdAt']);

        return ['offers' => $offers, 'simulated' => $simulated, 'provider' => $simulated ? 'simulated' : 'duffel',
            'label' => $simulated ? TravelApi::SIM_LABEL : null, 'error' => $error, 'cached' => false];
    }

    private function duffel(string $token, string $from, string $to, string $date, int $adults): array
    {
        $c = config('travel_apis.flight_search');
        $res = Http::withToken($token)->acceptJson()
            ->withHeaders(['Duffel-Version' => $c['duffel_version'] ?? 'v2'])
            ->timeout(TravelApi::timeoutSeconds())->connectTimeout(3)
            ->post(rtrim($c['duffel_url'], '/') . '/air/offer_requests?return_offers=true&supplier_timeout=4000', ['data' => [
                'slices' => [['origin' => $from, 'destination' => $to, 'departure_date' => $date]],
                'passengers' => array_fill(0, $adults, ['type' => 'adult']),
                'cabin_class' => 'economy',
            ]]);
        if (!$res->successful()) {
            throw new \RuntimeException('HTTP ' . $res->status());
        }
        $out = [];
        foreach ((array) $res->json('data.offers', []) as $o) {
            $seg = $o['slices'][0]['segments'] ?? [];
            if (!$seg) {
                continue;
            }
            $first = $seg[0];
            $last = end($seg);
            $out[] = [
                'id' => (string) $o['id'],
                'carrier' => (string) ($o['owner']['name'] ?? ''),
                'flightNo' => (string) (($first['marketing_carrier']['iata_code'] ?? '') . ($first['marketing_carrier_flight_number'] ?? '')),
                'from' => (string) ($first['origin']['iata_code'] ?? ''), 'to' => (string) ($last['destination']['iata_code'] ?? ''),
                'departAt' => (string) $first['departing_at'], 'arriveAt' => (string) $last['arriving_at'],
                'durationMin' => (int) round((strtotime($last['arriving_at']) - strtotime($first['departing_at'])) / 60),
                'stops' => count($seg) - 1,
                'price' => (int) round((float) $o['total_amount']), 'currency' => (string) $o['total_currency'],
                'expiresAt' => $o['expires_at'] ?? null,
                'simulated' => false,
            ];
        }
        usort($out, fn($a, $b) => $a['price'] <=> $b['price']);

        return array_slice($out, 0, (int) ($c['max_offers'] ?? 8));
    }

    /** Giả lập: 4–6 chuyến trong ngày của các hãng nội địa, giá theo chặng và số khách, ổn định theo đầu vào. */
    public function simulate(string $from, string $to, string $date, int $adults): array
    {
        $carriers = [['Vietnam Airlines', 'VN', 1.25], ['Vietjet Air', 'VJ', 0.85], ['Bamboo Airways', 'QH', 1.0], ['Vietravel Airlines', 'VU', 0.95]];
        $seed = "{$from}-{$to}-{$date}";
        $base = TravelApi::seeded($from . $to, 900000, 1900000);
        $dur = TravelApi::seeded($from . $to, 60, 130, 1);
        $n = TravelApi::seeded($seed, 4, 6, 2);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            [$name, $code, $mult] = $carriers[TravelApi::seeded($seed, 0, 3, 10 + $i)];
            $dep = Carbon::parse($date . ' 06:00')->addMinutes($i * 150 + TravelApi::seeded($seed, 0, 90, 20 + $i));
            $price = (int) (round($base * $mult * (0.8 + TravelApi::seeded($seed, 0, 60, 30 + $i) / 100) / 1000) * 1000) * $adults;
            $out[] = [
                'id' => 'sim_' . substr(md5($seed . $i), 0, 12), 'carrier' => $name, 'flightNo' => $code . TravelApi::seeded($seed, 100, 1899, 40 + $i),
                'from' => $from, 'to' => $to, 'departAt' => $dep->format('Y-m-d\TH:i:00'), 'arriveAt' => $dep->copy()->addMinutes($dur)->format('Y-m-d\TH:i:00'),
                'durationMin' => $dur, 'stops' => 0, 'price' => $price, 'currency' => 'VND', 'expiresAt' => null, 'simulated' => true,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['departAt'], $b['departAt']));

        return $out;
    }
}
