<?php

namespace App\Services\Travel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * T-37 trạng thái chuyến bay. Chuỗi dự phòng: AeroDataBox → AviationStack → trạng thái đã lưu (kèm thời điểm lấy) → giả lập có nhãn.
 * Cache 30 phút; KHÔNG có job làm mới định kỳ (chỉ lấy khi người dùng mở chuyến / bấm kiểm tra) để giữ hạn mức gói miễn phí.
 * Chỉ hiển thị, không tự đổi lịch của người dùng.
 */
class FlightStatusService
{
    public const LABELS = ['scheduled' => 'Đúng lịch', 'on_time' => 'Đúng giờ', 'delayed' => 'Trễ', 'cancelled' => 'Đã hủy', 'departed' => 'Đã cất cánh', 'landed' => 'Đã hạ cánh'];

    /** @var list<FlightStatusProvider> */
    private array $providers;

    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? array_map(fn($n) => match ($n) {
            'aerodatabox' => new AeroDataBoxProvider(),
            'aviationstack' => new AviationStackProvider(),
        }, (array) config('travel_apis.flight_status.chain', []));
    }

    /** @return array{status:string,label:string,delayMinutes:?int,source:string,simulated:bool,fetchedAt:string,stale:bool} */
    public function get(string $flightNo, string $date): array
    {
        $flightNo = strtoupper(preg_replace('/\s+/', '', $flightNo));
        $key = "flight:status:{$flightNo}:{$date}";

        return Cache::remember($key, now()->addMinutes((int) config('travel_apis.flight_status.cache_minutes', 30)), fn() => $this->fetch($flightNo, $date));
    }

    private function fetch(string $flightNo, string $date): array
    {
        if (!TravelApi::simulateOnly()) {
            foreach ($this->providers as $p) {
                if (!$p->configured() || !TravelApi::available($p->name()) || !TravelApi::quotaLeft($p->name())) {
                    continue;
                }
                try {
                    TravelApi::countCall($p->name());
                    $r = $p->status($flightNo, $date);
                    TravelApi::ok($p->name());
                    if ($r) {
                        DB::table('tbl_flight_status')->upsert([[
                            'flightNo' => $flightNo, 'flightDate' => $date, 'status' => $r['status'], 'delayMinutes' => $r['delayMinutes'],
                            'provider' => $p->name(), 'fetchedAt' => now(),
                        ]], ['flightNo', 'flightDate'], ['status', 'delayMinutes', 'provider', 'fetchedAt']);

                        return $this->shape($r['status'], $r['delayMinutes'], $p->name(), false, now(), false);
                    }
                } catch (\Throwable $e) {
                    TravelApi::failed($p->name(), mb_substr($e->getMessage(), 0, 120));
                }
            }
            $saved = DB::table('tbl_flight_status')->where(['flightNo' => $flightNo, 'flightDate' => $date])->first();
            if ($saved) {
                return $this->shape($saved->status, $saved->delayMinutes, $saved->provider, false, \Carbon\Carbon::parse($saved->fetchedAt), true);
            }
        }

        // giả lập ổn định: phần lớn đúng giờ, đôi khi trễ
        $roll = TravelApi::seeded($flightNo . $date, 0, 99);
        [$status, $delay] = $roll < 75 ? ['on_time', 0] : ($roll < 96 ? ['delayed', 15 + TravelApi::seeded($flightNo . $date, 0, 90, 1)] : ['cancelled', null]);

        return $this->shape($status, $delay, 'simulated', true, now(), false);
    }

    private function shape(string $status, ?int $delay, string $source, bool $sim, \Carbon\Carbon $at, bool $stale): array
    {
        $label = self::LABELS[$status] ?? $status;
        if ($status === 'delayed' && $delay) {
            $label .= ' ' . $delay . ' phút';
        }

        return ['status' => $status, 'label' => $label, 'delayMinutes' => $delay, 'source' => $source, 'simulated' => $sim,
            'fetchedAt' => $at->format('H:i d/m'), 'stale' => $stale];
    }
}
